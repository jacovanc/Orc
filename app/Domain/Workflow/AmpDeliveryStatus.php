<?php

namespace App\Domain\Workflow;

enum AmpDeliveryStatus: string
{
    case Pending = 'pending';
    case Delivering = 'delivering';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Ambiguous = 'ambiguous';
}
