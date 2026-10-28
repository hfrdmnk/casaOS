<?php

namespace App\Models\Finance;

/**
 * Transactions are stored as completed or pending. Reverted only exists on import rows: a reverted
 * row removes the transaction it matches and is never stored.
 */
enum TransactionStatus: string
{
    case Completed = 'completed';
    case Pending = 'pending';
    case Reverted = 'reverted';
}
