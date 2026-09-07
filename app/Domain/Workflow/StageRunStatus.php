<?php

namespace App\Domain\Workflow;

enum StageRunStatus: string
{
    case Running = 'running';
    case Waiting = 'waiting';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
