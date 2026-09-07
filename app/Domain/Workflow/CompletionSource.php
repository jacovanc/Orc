<?php

namespace App\Domain\Workflow;

enum CompletionSource: string
{
    case AmpAgent = 'amp_agent';
    case AgentSimulation = 'agent_simulation';
    case HumanAction = 'human_action';
}
