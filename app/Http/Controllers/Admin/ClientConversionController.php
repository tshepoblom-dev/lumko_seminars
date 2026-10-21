<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClientConversion;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Attendee → "Became a client": staff record that this person became a
 * Lumko client, crediting the partner that referred them (pre-filled from
 * "How did you hear about us?", changeable). One record per person. The
 * partner's reward is settled outside the platform; partners only ever see
 * the count.
 */
class ClientConversionController extends Controller
{
    public function update(Request $request, Event $event, EventRegistration $registration): RedirectResponse
    {
        $this->authorize('update', $event);
        abort_unless($registration->event_id === $event->id, 404);

        $data = $request->validate([
            'converted_on' => ['required', 'date', 'before_or_equal:today'],
            'partner_id' => ['nullable', 'integer', 'exists:partners,id'],
            'deal_value' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = $registration->user;
        $existing = ClientConversion::where('user_id', $user->id)->first();
        $before = $existing?->only(['partner_id', 'converted_on', 'deal_value_cents']);

        $conversion = ClientConversion::updateOrCreate(['user_id' => $user->id], [
            'organisation_id' => $user->organisation_id,
            'partner_id' => $data['partner_id'] ?? null,
            'event_id' => $existing?->event_id ?? $event->id,
            'converted_on' => $data['converted_on'],
            'deal_value_cents' => isset($data['deal_value']) ? (int) round($data['deal_value'] * 100) : null,
            'notes' => $data['notes'] ?? null,
            'recorded_by' => $existing?->recorded_by ?? $request->user()->id,
        ]);

        Audit::record($existing ? 'client_conversion.updated' : 'client_conversion.recorded', subject: $conversion, eventId: $event->id,
            before: $before, after: $conversion->only(['partner_id', 'converted_on', 'deal_value_cents']));

        return back()->with('success', "{$user->name} is recorded as a client.");
    }

    public function destroy(Event $event, EventRegistration $registration): RedirectResponse
    {
        $this->authorize('update', $event);
        abort_unless($registration->event_id === $event->id, 404);

        $conversion = ClientConversion::where('user_id', $registration->user_id)->first();

        if ($conversion) {
            $conversion->delete();
            Audit::record('client_conversion.removed', eventId: $event->id, before: $conversion->only(['user_id', 'partner_id', 'converted_on']));
        }

        return back()->with('success', 'Client record removed.');
    }
}
