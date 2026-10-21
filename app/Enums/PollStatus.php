<?php

namespace App\Enums;

enum PollStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Closed = 'closed';
}
