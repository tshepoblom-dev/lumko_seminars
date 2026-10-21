<?php

namespace App\Notifications\Concerns;

use App\Enums\CommunicationCategory;
use App\Enums\ConsentType;
use App\Models\User;
use App\Services\ConsentService;

/**
 * Any Notification using this trait must implement category(): CommunicationCategory.
 * Add `use GuardsMarketingConsent;` and call `$this->guardConsent($notifiable)` at
 * the top of `toMail()`/`via()` (returning an empty channel list refuses the send).
 * No notification in this codebase currently declares category() === Marketing;
 * this exists so the first one that does cannot forget the check.
 */
trait GuardsMarketingConsent
{
    public function category(): CommunicationCategory
    {
        return CommunicationCategory::Event;
    }

    protected function consentAllows(User $notifiable): bool
    {
        if ($this->category() !== CommunicationCategory::Marketing) {
            return true;
        }

        return app(ConsentService::class)->isGranted($notifiable, ConsentType::LumkoMarketing);
    }
}
