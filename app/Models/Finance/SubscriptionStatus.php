<?php

namespace App\Models\Finance;

/**
 * An active subscription links new matching charges and shows as upcoming; a cancelled one keeps
 * its payment history and does neither.
 */
enum SubscriptionStatus: string
{
    case Active = 'active';
    case Cancelled = 'cancelled';
}
