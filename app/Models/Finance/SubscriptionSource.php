<?php

namespace App\Models\Finance;

/**
 * How a subscription came to be: created by the user (`manual`, e.g. "Mark as subscription") or
 * a confirmed detection suggestion (`detected`).
 */
enum SubscriptionSource: string
{
    case Manual = 'manual';
    case Detected = 'detected';
}
