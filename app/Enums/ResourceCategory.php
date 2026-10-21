<?php

namespace App\Enums;

enum ResourceCategory: string
{
    case BeforeEvent = 'before_event';
    case DuringEvent = 'during_event';
    case AfterEvent = 'after_event';

    public function label(): string
    {
        return match ($this) {
            self::BeforeEvent => 'Before the event',
            self::DuringEvent => 'During the event',
            self::AfterEvent => 'After the event',
        };
    }
}
