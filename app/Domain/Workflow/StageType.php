<?php

namespace App\Domain\Workflow;

enum StageType: string
{
    case Agent = 'agent';
    case Human = 'human';
    case Terminal = 'terminal';
}
