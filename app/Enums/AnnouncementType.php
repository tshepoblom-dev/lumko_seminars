<?php

namespace App\Enums;

enum AnnouncementType: string
{
    case EventUpdate = 'event_update';
    case Reminder = 'reminder';
    case ProgrammeUpdate = 'programme_update';
    case ResourceAvailable = 'resource_available';
    case LiveEventNotice = 'live_event_notice';
    case PostEvent = 'post_event';

    public function label(): string
    {
        return match ($this) {
            self::EventUpdate => 'Event update',
            self::Reminder => 'Reminder',
            self::ProgrammeUpdate => 'Programme update',
            self::ResourceAvailable => 'Resource available',
            self::LiveEventNotice => 'Live event notice',
            self::PostEvent => 'Post-event',
        };
    }
}
