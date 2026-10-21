<?php

namespace App\Enums;

enum LivestreamProvider: string
{
    case Youtube = 'youtube';
    case Facebook = 'facebook';
    case Agora = 'agora';

    public function label(): string
    {
        return match ($this) {
            self::Youtube => 'YouTube',
            self::Facebook => 'Facebook',
            self::Agora => 'Agora (interactive)',
        };
    }

    /** Whether this provider is a simple embeddable player (a URL is enough). */
    public function supportsEmbed(): bool
    {
        return $this !== self::Agora;
    }

    /** Whether this provider needs the Agora host/publisher console. */
    public function requiresHostConsole(): bool
    {
        return $this === self::Agora;
    }
}
