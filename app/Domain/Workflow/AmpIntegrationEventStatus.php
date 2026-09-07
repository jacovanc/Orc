<?php

namespace App\Domain\Workflow;

enum AmpIntegrationEventStatus: string
{
    case Processed = 'processed';
    case Rejected = 'rejected';
}
