<?php

namespace App\Models\Finance;

enum AmountMode: string
{
    /** One column holding a signed amount. */
    case Signed = 'signed';

    /** Separate debit (money out) and credit (money in) columns. */
    case Split = 'split';
}
