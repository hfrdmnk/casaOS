<?php

namespace App\Models\Finance;

/**
 * Who set a transaction's category or payee. Rules never overwrite a manual value; clearing a
 * manual value hands the field back to the rules.
 */
enum FieldSource: string
{
    case Manual = 'manual';
    case Rule = 'rule';
}
