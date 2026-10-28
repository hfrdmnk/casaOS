<?php

namespace App\Models\Finance;

/**
 * Who decided a transaction's subscription: matching (`auto`) or the user (`manual`). Manual is
 * final, including a manual unlink: automatic linking never touches the row again.
 */
enum SubscriptionLinkSource: string
{
    case Auto = 'auto';
    case Manual = 'manual';
}
