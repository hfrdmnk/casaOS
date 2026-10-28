<?php

namespace App\Models\Finance;

use LogicException;

/**
 * What a matching rule does to a transaction. Each action owns one transaction field and its
 * source column; a manual value in that field is never overwritten. `mark_as_transfer` takes no
 * value: it marks the transaction as a transfer without a counterpart (pairing may find one later)
 * and clears its category.
 */
enum RuleActionType: string
{
    case SetCategory = 'set_category';
    case SetPayee = 'set_payee';
    case MarkAsTransfer = 'mark_as_transfer';

    /**
     * The actions that set a reference to a category or payee.
     *
     * @return list<self>
     */
    public static function valued(): array
    {
        return [self::SetCategory, self::SetPayee];
    }

    /**
     * The transaction column the action sets, e.g. `category_id`.
     */
    public function column(): string
    {
        return match ($this) {
            self::SetCategory => 'category_id',
            self::SetPayee => 'payee_id',
            self::MarkAsTransfer => 'is_transfer',
        };
    }

    /**
     * The column recording who set it, e.g. `category_source`.
     */
    public function sourceColumn(): string
    {
        return match ($this) {
            self::SetCategory => 'category_source',
            self::SetPayee => 'payee_source',
            self::MarkAsTransfer => 'transfer_source',
        };
    }

    /**
     * The model the action's value points at (only for valued() actions).
     *
     * @return class-string<Category|Payee>
     */
    public function model(): string
    {
        return match ($this) {
            self::SetCategory => Category::class,
            self::SetPayee => Payee::class,
            self::MarkAsTransfer => throw new LogicException('mark_as_transfer takes no value.'),
        };
    }

    /**
     * The field name used in previews and summaries: `category`, `payee` or `transfer`.
     */
    public function field(): string
    {
        return match ($this) {
            self::SetCategory => 'category',
            self::SetPayee => 'payee',
            self::MarkAsTransfer => 'transfer',
        };
    }
}
