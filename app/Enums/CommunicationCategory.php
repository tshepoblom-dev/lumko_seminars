<?php

namespace App\Enums;

/**
 * Every Notification that sends mail must declare which category it is.
 * `Marketing` notifications are guarded by ConsentService::isGranted() at
 * send time (see App\Notifications\Concerns\GuardsMarketingConsent) —
 * no marketing notification is implemented in this work order (B14).
 */
enum CommunicationCategory: string
{
    case Event = 'event';
    case Marketing = 'marketing';
}
