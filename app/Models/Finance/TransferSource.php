<?php

namespace App\Models\Finance;

/**
 * Who decided whether a transaction is a transfer. Manual is final: rules and transfer pairing
 * never touch the row again. A rule marks a transfer that pairing may then give a counterpart;
 * auto means pairing made it a transfer, so it falls back to a plain transaction when it loses its
 * counterpart.
 */
enum TransferSource: string
{
    case Manual = 'manual';
    case Rule = 'rule';
    case Auto = 'auto';
}
