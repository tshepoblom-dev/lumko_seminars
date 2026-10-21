<?php

namespace App\Enums;

enum ConnectionStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
}
