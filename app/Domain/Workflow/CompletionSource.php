<?php

namespace App\Domain\Workflow;

enum CompletionSource: string
{
    case AgentSimulation = 'agent_simulation';
    case HumanAction = 'human_action';
}
