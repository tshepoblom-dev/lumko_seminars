<?php

namespace App\Http\Controllers\Partner;

use App\Enums\PartnerFeature;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Partner\Concerns\ResolvesPartnerEvent;
use App\Models\EventPartner;
use App\Models\Partner;
use App\Models\PartnerConnection;
use App\Support\Audit;
use App\Support\ReferralReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A partner's portal: the events it supports (tier, what it unlocks, lead
 * and referral counts) and its public profile. Leads, scanning, referrals,
 * analytics, resources and tickets have their own controllers.
 */
class PartnerPortalController extends Controller
{
    use ResolvesPartnerEvent;

    public function dashboard(Partner $partner): Response
    {
        $involvements = EventPartner::with(['event', 'tier'])
            ->where('partner_id', $partner->id)
            ->whereHas('event', fn ($q) => $q->where('status', '!=', 'archived'))
            ->get()
            ->sortByDesc(fn (EventPartner $ep) => $ep->event->starts_at)
            ->values();

        return Inertia::render('Partner/Dashboard', [
            'partner' => [
                'slug' => $partner->slug,
                'name' => $partner->name,
                'has_logo' => (bool) $partner->logo_path,
                'logo_approved' => $partner->logo_approved,
                'has_description' => filled($partner->description),
            ],
            'events' => $involvements->map(function (EventPartner $ep) use ($partner) {
                $leads = PartnerConnection::where('partner_id', $partner->id)->where('event_id', $ep->event_id)->active()
                    ->selectRaw('source, count(*) as total')->groupBy('source')->pluck('total', 'source');

                return [
                    'id' => $ep->event_id,
                    'name' => $ep->event->name,
                    'starts_at' => $ep->event->starts_at?->toIso8601String(),
                    'has_ended' => $ep->event->ends_at?->isPast() ?? false,
                    'tier' => $ep->tier?->name,
                    'features' => $ep->features(),
                    'feature_labels' => collect($ep->features())->map(fn ($f) => PartnerFeature::from($f)->label())->values(),
                    'is_published' => $ep->is_published,
                    'stand_label' => $ep->stand_label,
                    'leads' => [
                        'total' => (int) $leads->sum(),
                        'by_source' => collect(PartnerConnection::SOURCES)
                            ->mapWithKeys(fn ($s) => [$s => (int) ($leads[$s] ?? 0)]),
                    ],
                    'referrals' => $ep->hasFeature(PartnerFeature::Referrals)
                        ? collect(ReferralReport::forPartner($partner, $ep->event))->except('deal_value_cents')
                        : null,
                ];
            }),
            'canManageTeam' => request()->user()->can('manageTeam', $partner),
        ]);
    }

    public function editProfile(Partner $partner): Response
    {
        return Inertia::render('Partner/Profile', [
            'partner' => [
                'slug' => $partner->slug,
                'name' => $partner->name,
                'website' => $partner->website,
                'description' => $partner->description,
                'logo_url' => $partner->logo_path ? Storage::disk('public')->url($partner->logo_path) : null,
                'logo_alt' => $partner->logo_alt,
                'logo_approved' => $partner->logo_approved,
            ],
            'involvements' => EventPartner::with('event')->where('partner_id', $partner->id)
                ->whereHas('event', fn ($q) => $q->where('status', '!=', 'archived'))
                ->get()
                ->map(fn (EventPartner $ep) => [
                    'id' => $ep->id,
                    'event' => $ep->event->name,
                    'blurb' => $ep->blurb,
                ]),
        ]);
    }

    /**
     * The partner edits its own public profile. A new logo waits for staff
     * approval before it's shown, as for speaker photos. The name stays
     * with staff (it's how the partner appears in consent wording).
     */
    public function updateProfile(Request $request, Partner $partner): RedirectResponse
    {
        $data = $request->validate([
            'website' => ['nullable', 'url:https,http', 'max:2048'],
            'description' => ['nullable', 'string', 'max:5000'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'logo_alt' => ['nullable', 'required_with:logo', 'string', 'max:255'],
            'blurbs' => ['array'],
            'blurbs.*' => ['nullable', 'string', 'max:2000'],
        ]);

        $previousLogo = $partner->logo_path;
        $partner->fill(['website' => $data['website'] ?? null, 'description' => $data['description'] ?? null]);

        if ($request->hasFile('logo')) {
            $partner->logo_path = $request->file('logo')->store('partners', 'public');
            $partner->logo_alt = $data['logo_alt'];
            $partner->logo_approved = false;
        }

        $partner->save();

        if ($request->hasFile('logo') && $previousLogo) {
            Storage::disk('public')->delete($previousLogo);
        }

        foreach ($data['blurbs'] ?? [] as $eventPartnerId => $blurb) {
            EventPartner::where('partner_id', $partner->id)->whereKey((int) $eventPartnerId)->update(['blurb' => $blurb]);
        }

        Audit::record('partner.profile_updated', subject: $partner, after: [
            'logo_changed' => $request->hasFile('logo'),
        ]);

        return back()->with('success', $request->hasFile('logo')
            ? 'Profile saved. Your new logo appears on the event site once our team approves it.'
            : 'Profile saved.');
    }
}
