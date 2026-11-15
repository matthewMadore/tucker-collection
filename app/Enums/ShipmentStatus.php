<?php

namespace App\Enums;

/**
 * Values match the shipments_status_valid CHECK constraint. Transitions are
 * added with UPS tracking sync.
 */
enum ShipmentStatus: string
{
    case Pending = 'pending';
    case LabelCreated = 'label_created';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case Exception = 'exception';
}
