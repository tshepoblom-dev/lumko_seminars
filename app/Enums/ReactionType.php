<?php

namespace App\Enums;

/**
 * Emoji reactions that float over the stream. The first three are the old
 * text buttons: kept so existing rows still read, but no longer accepted.
 */
enum ReactionType: string
{
    case Useful = 'useful';
    case Interesting = 'interesting';
    case Question = 'question';

    case Thumbs = 'thumbs';
    case Heart = 'heart';
    case Clap = 'clap';
    case Party = 'party';
    case Laugh = 'laugh';
    case Think = 'think';

    /** @return list<string> what attendees can send now */
    public static function current(): array
    {
        return array_map(fn (self $t) => $t->value, [
            self::Thumbs, self::Heart, self::Clap, self::Party, self::Laugh, self::Think,
        ]);
    }

    public function emoji(): string
    {
        return match ($this) {
            self::Thumbs, self::Useful => '👍',
            self::Heart => '❤️',
            self::Clap => '👏',
            self::Party => '🎉',
            self::Laugh => '😂',
            self::Think, self::Interesting => '🤔',
            self::Question => '❓',
        };
    }
}
