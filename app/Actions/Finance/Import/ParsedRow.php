<?php

namespace App\Actions\Finance\Import;

use App\Models\Finance\TransactionStatus;

/**
 * One file row read through a mapping. Money is in Rappen; `amount` already has the fee subtracted.
 */
final readonly class ParsedRow
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public int $line,
        public array $errors = [],
        public ?string $currency = null,
        public bool $foreignCurrency = false,
        public string $date = '',
        public ?string $time = null,
        public string $description = '',
        public int $amount = 0,
        public int $fee = 0,
        public ?int $balance = null,
        public TransactionStatus $status = TransactionStatus::Completed,
        public string $rawAmount = '',
    ) {}

    /**
     * The identity of the row without its position in the file.
     *
     * @return list<string|int>
     */
    public function identity(): array
    {
        return [
            $this->date,
            $this->time ?? '',
            mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->description))),
            $this->amount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toPreview(): array
    {
        return [
            'line' => $this->line,
            'errors' => $this->errors,
            'foreign_currency' => $this->foreignCurrency,
            'currency' => $this->currency,
            'date' => $this->date,
            'time' => $this->time,
            'description' => $this->description,
            'amount' => $this->amount,
            'fee' => $this->fee,
            'balance' => $this->balance,
            'status' => $this->status->value,
            'raw_amount' => $this->rawAmount,
        ];
    }
}
