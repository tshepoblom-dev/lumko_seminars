<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case EventManager = 'event_manager';
    case Attendee = 'attendee';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::EventManager => 'Event Manager',
            self::Attendee => 'Attendee',
        };
    }

    public function isStaff(): bool
    {
        return in_array($this, [self::SuperAdmin, self::EventManager], true);
    }
}
