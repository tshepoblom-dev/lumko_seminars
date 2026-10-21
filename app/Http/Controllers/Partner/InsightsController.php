<?php

namespace App\Http\Controllers\Partner;

use App\Enums\PartnerFeature;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Partner\Concerns\ResolvesPartnerEvent;
use App\Models\Event;
use App\Models\PageView;
use App\Models\Partner;
use App\Models\PartnerConnection;
use App\Models\ResourceView;
use App\Services\SpeakerReport;
use App\Support\ReferralReport;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * A partner's numbers for one event, all aggregate (no personal data):
 * referrals (people who named the partner when registering, what came of
 * it) and analytics (partner page visits, leads over time, resource opens,
 * the event's audience mix with small groups folded away).
 */
class InsightsController extends Controller
{
    use ResolvesPartnerEvent;

    public function referrals(Partner $partner, Event $event): Response
    {
        $eventPartner = $this->eventPartner($partner, $event);
        $this->requireFeature($eventPartner, PartnerFeature::Referrals);

        $link = route('event.register', ['ref' => $partner->slug]);

        return Inertia::render('Partner/Referrals', [
            ...$this->portalProps($partner, $event, $eventPartner),
            'link' => $link,
            'qrSvg' => (string) QrCode::format('svg')->size(200)->margin(1)->generate($link),
            // Deal values stay with staff; the partner sees counts.
            'counts' => collect(ReferralReport::forPartner($partner, $event))->except('deal_value_cents'),
        ]);
    }

    public function analytics(Partner $partner, Event $event, SpeakerReport $report): Response
    {
        $eventPartner = $this->eventPartner($partner, $event);
        $this->requireFeature($eventPartner, PartnerFeature::Analytics);

        $since = now()->subDays(29)->startOfDay();
        $pagePath = '/'.ltrim(parse_url(route('partners.show', [$event, $partner]), PHP_URL_PATH), '/');

        $views = PageView::where('path', $pagePath)->where('viewed_on', '>=', $since->toDateString());
        $leads = PartnerConnection::where('partner_id', $partner->id)->where('event_id', $event->id)->active();

        $days = collect(range(0, 29))->map(fn ($i) => $since->copy()->addDays($i)->toDateString());
        $viewsByDay = (clone $views)->selectRaw('viewed_on, count(*) as total')->groupBy('viewed_on')->pluck('total', 'viewed_on')
            ->mapWithKeys(fn ($total, $day) => [Carbon::parse($day)->toDateString() => $total]);
        $leadsByDay = (clone $leads)->where('granted_at', '>=', $since)->get(['granted_at'])
            ->groupBy(fn ($c) => $c->granted_at->toDateString())->map->count();

        return Inertia::render('Partner/Analytics', [
            ...$this->portalProps($partner, $event, $eventPartner),
            'totals' => [
                'page_views' => (clone $views)->count(),
                // Visitor hashes rotate daily, so this is the sum of daily
                // unique visitors, not a count of distinct people.
                'page_visitors' => (clone $views)->distinct()->get(['viewed_on', 'visitor_hash'])->count(),
                'leads' => (clone $leads)->count(),
                'resource_opens' => ResourceView::whereHas('resource', fn ($q) => $q->where('partner_id', $partner->id)->where('event_id', $event->id))->count(),
            ],
            'daily' => $days->map(fn ($day) => [
                'date' => $day,
                'views' => (int) ($viewsByDay[$day] ?? 0),
                'leads' => (int) ($leadsByDay[$day] ?? 0),
            ])->values(),
            'audience' => $report->audience($event),
            'minGroup' => SpeakerReport::MIN_GROUP,
        ]);
    }
}
