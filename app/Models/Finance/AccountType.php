<?php

namespace App\Models\Finance;

enum AccountType: string
{
    case Checking = 'checking';
    case Savings = 'savings';
    case CreditCard = 'credit_card';
    case Investment = 'investment';
    case Crypto = 'crypto';

    public function label(): string
    {
        return match ($this) {
            self::Checking => 'Checking',
            self::Savings => 'Savings',
            self::CreditCard => 'Credit card',
            self::Investment => 'Investment',
            self::Crypto => 'Crypto',
        };
    }

    /**
     * Investment and crypto accounts only track balance snapshots; they never import transactions.
     */
    public function isImportable(): bool
    {
        return ! in_array($this, [self::Investment, self::Crypto], true);
    }

    /**
     * The values of the types that import transactions.
     *
     * @return list<string>
     */
    public static function importable(): array
    {
        return array_values(array_map(fn (self $type): string => $type->value, array_filter(self::cases(), fn (self $type): bool => $type->isImportable())));
    }

    /**
     * @return list<array{value: string, label: string, importable: bool}>
     */
    public static function options(): array
    {
        return array_map(fn (self $type): array => [
            'value' => $type->value,
            'label' => $type->label(),
            'importable' => $type->isImportable(),
        ], self::cases());
    }
}
