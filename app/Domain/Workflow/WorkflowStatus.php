<?php

namespace App\Domain\Workflow;

enum WorkflowStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
}
