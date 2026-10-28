<?php

namespace App\Models\Finance;

use Illuminate\Support\Carbon;

/**
 * How often a subscription charges. Detection accepts the gaps between consecutive charges within
 * `gapDays()`; the next expected date is the last charge plus one interval in calendar terms.
 */
enum SubscriptionInterval: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Weekly',
            self::Monthly => 'Monthly',
            self::Quarterly => 'Quarterly',
            self::Yearly => 'Yearly',
        };
    }

    /**
     * The days between two consecutive charges that count as this interval, inclusive.
     *
     * @return array{int, int}
     */
    public function gapDays(): array
    {
        return match ($this) {
            self::Weekly => [6, 8],
            self::Monthly => [28, 33],
            self::Quarterly => [85, 97],
            self::Yearly => [355, 375],
        };
    }

    /**
     * The day one interval after `$date` (Y-m-d). Months do not overflow: Jan 31 + 1 month is Feb 28.
     */
    public function after(string $date): string
    {
        $day = Carbon::parse($date);

        $next = match ($this) {
            self::Weekly => $day->addWeek(),
            self::Monthly => $day->addMonthNoOverflow(),
            self::Quarterly => $day->addMonthsNoOverflow(3),
            self::Yearly => $day->addYearNoOverflow(),
        };

        return $next->toDateString();
    }

    /**
     * What a charge of `$amount` costs per month on average, rounded to the Rappen.
     */
    public function monthly(int $amount): int
    {
        return (int) round(match ($this) {
            self::Weekly => $amount * 52 / 12,
            self::Monthly => $amount,
            self::Quarterly => $amount / 3,
            self::Yearly => $amount / 12,
        });
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $interval): array => ['value' => $interval->value, 'label' => $interval->label()], self::cases());
    }
}
