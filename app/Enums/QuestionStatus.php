<?php

namespace App\Enums;

enum QuestionStatus: string
{
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Hidden = 'hidden';
    case Answered = 'answered';
    case Archived = 'archived';
}
