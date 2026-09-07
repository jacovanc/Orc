<?php

namespace App\Domain\Workflow;

enum AmpLaunchStatus: string
{
    case Pending = 'pending';
    case Claimed = 'claimed';
    case Launched = 'launched';
    case Completed = 'completed';
    case Failed = 'failed';
    case Ambiguous = 'ambiguous';
}
