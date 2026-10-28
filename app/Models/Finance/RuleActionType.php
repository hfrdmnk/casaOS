<?php

namespace App\Models\Finance;

/**
 * What a matching rule does to a transaction. Each action owns one transaction field and its
 * source column; a manual value in that field is never overwritten.
 */
enum RuleActionType: string
{
    case SetCategory = 'set_category';
    case SetPayee = 'set_payee';

    /**
     * The transaction column the action sets, e.g. `category_id`.
     */
    public function column(): string
    {
        return match ($this) {
            self::SetCategory => 'category_id',
            self::SetPayee => 'payee_id',
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
        };
    }

    /**
     * The model the action's value points at.
     *
     * @return class-string<Category|Payee>
     */
    public function model(): string
    {
        return match ($this) {
            self::SetCategory => Category::class,
            self::SetPayee => Payee::class,
        };
    }

    /**
     * The field name used in previews and summaries: `category` or `payee`.
     */
    public function field(): string
    {
        return match ($this) {
            self::SetCategory => 'category',
            self::SetPayee => 'payee',
        };
    }
}
