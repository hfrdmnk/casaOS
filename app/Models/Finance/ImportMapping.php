<?php

namespace App\Models\Finance;

use App\Actions\Finance\Import\CsvReader;
use App\Actions\Finance\Import\ParsedRow;
use App\Actions\Finance\Money;
use App\Models\Concerns\BelongsToUser;
use Database\Factories\Finance\ImportMappingFactory;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * How to read one file shape into one account. Columns are 0-based positions into `header`.
 *
 * @property int $id
 * @property int $user_id
 * @property int $account_id
 * @property string $name
 * @property string $signature
 * @property string $encoding
 * @property string $delimiter
 * @property int $skip_lines
 * @property list<string> $header
 * @property int $date_column
 * @property string $date_format
 * @property int $description_column
 * @property AmountMode $amount_mode
 * @property int|null $amount_column
 * @property int|null $debit_column
 * @property int|null $credit_column
 * @property bool $invert_sign
 * @property string $decimal_separator
 * @property string|null $thousands_separator
 * @property int|null $fee_column
 * @property int|null $balance_column
 * @property int|null $currency_column
 * @property int|null $status_column
 * @property array<string, string>|null $status_map raw file value => completed|pending|reverted
 * @property int|null $filter_column
 * @property string|null $filter_value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 */
#[Fillable([
    'account_id', 'name', 'signature', 'encoding', 'delimiter', 'skip_lines', 'header',
    'date_column', 'date_format', 'description_column', 'amount_mode', 'amount_column', 'debit_column',
    'credit_column', 'invert_sign', 'decimal_separator', 'thousands_separator', 'fee_column',
    'balance_column', 'currency_column', 'status_column', 'status_map', 'filter_column', 'filter_value',
])]
class ImportMapping extends Model
{
    /** @use HasFactory<ImportMappingFactory> */
    use BelongsToUser, HasFactory;

    protected static function booted(): void
    {
        static::saving(function (ImportMapping $mapping): void {
            $mapping->signature = CsvReader::signature($mapping->encoding, $mapping->delimiter, $mapping->skip_lines, $mapping->header);
        });
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Whether the row filter selects the row for this mapping's account.
     *
     * @param  list<string>  $cells
     */
    public function selects(array $cells): bool
    {
        return $this->filter_column === null
            || trim($cells[$this->filter_column] ?? '') === trim((string) $this->filter_value);
    }

    /**
     * Read one data row. A row whose currency differs from the account's is flagged and not parsed
     * further; every other problem is reported in `errors`.
     *
     * @param  list<string>  $cells
     */
    public function parseRow(array $cells, int $line, string $accountCurrency = 'CHF'): ParsedRow
    {
        if (count($cells) !== count($this->header)) {
            return new ParsedRow($line, [sprintf('has %d columns, the header has %d', count($cells), count($this->header))]);
        }

        $currency = $this->currency_column === null ? $accountCurrency : strtoupper(trim($cells[$this->currency_column]));
        $rawAmount = $this->amount_mode === AmountMode::Signed
            ? trim($cells[(int) $this->amount_column])
            : trim($cells[(int) $this->debit_column].' / '.$cells[(int) $this->credit_column], ' /');

        if ($currency !== $accountCurrency) {
            return new ParsedRow($line, currency: $currency, foreignCurrency: true, description: trim($cells[$this->description_column]), rawAmount: $rawAmount);
        }

        $errors = [];
        [$date, $time] = $this->parseDate(trim($cells[$this->date_column]), $errors);

        $description = trim($cells[$this->description_column]);

        if ($description === '') {
            $errors[] = sprintf('%s is empty', $this->header[$this->description_column]);
        }

        $amount = $this->parseAmount($cells, $errors);
        $fee = $this->fee_column === null ? 0 : abs($this->money($cells, $this->fee_column, $errors) ?? 0);
        $balance = $this->balance_column === null ? null : $this->money($cells, $this->balance_column, $errors);
        $status = $this->parseStatus($cells, $errors);

        return new ParsedRow(
            line: $line,
            errors: $errors,
            currency: $currency,
            date: $date,
            time: $time,
            description: $description,
            amount: $amount - $fee,
            fee: $fee,
            balance: $balance,
            status: $status,
            rawAmount: $rawAmount,
        );
    }

    /**
     * @param  list<string>  $errors
     * @return array{0: string, 1: string|null}
     */
    private function parseDate(string $value, array &$errors): array
    {
        $parsed = DateTimeImmutable::createFromFormat('!'.$this->date_format, $value);
        $problems = DateTimeImmutable::getLastErrors();

        if ($parsed === false || ($problems !== false && ($problems['warning_count'] > 0 || $problems['error_count'] > 0))) {
            $errors[] = sprintf('%s "%s" does not match the date format %s', $this->header[$this->date_column], $value, $this->date_format);

            return ['', null];
        }

        $hasTime = preg_match('/(?<!\\\\)[GHghis]/', $this->date_format) === 1;

        return [$parsed->format('Y-m-d'), $hasTime ? $parsed->format('H:i:s') : null];
    }

    /**
     * @param  list<string>  $cells
     * @param  list<string>  $errors
     */
    private function parseAmount(array $cells, array &$errors): int
    {
        $errorsBefore = count($errors);

        if ($this->amount_mode === AmountMode::Signed) {
            $amount = $this->money($cells, (int) $this->amount_column, $errors);

            if ($amount === null && count($errors) === $errorsBefore) {
                $errors[] = sprintf('%s is empty', $this->header[(int) $this->amount_column]);
            }
        } else {
            $debit = $this->money($cells, (int) $this->debit_column, $errors);
            $credit = $this->money($cells, (int) $this->credit_column, $errors);

            if ($debit === null && $credit === null && count($errors) === $errorsBefore) {
                $errors[] = sprintf('%s and %s are both empty', $this->header[(int) $this->debit_column], $this->header[(int) $this->credit_column]);
            }

            $amount = abs($credit ?? 0) - abs($debit ?? 0);
        }

        return ($this->invert_sign ? -1 : 1) * ($amount ?? 0);
    }

    /**
     * @param  list<string>  $cells
     * @param  list<string>  $errors
     */
    private function money(array $cells, int $column, array &$errors): ?int
    {
        try {
            return Money::parse($cells[$column], $this->decimal_separator, $this->thousands_separator);
        } catch (InvalidArgumentException $exception) {
            $errors[] = sprintf('%s %s', $this->header[$column], $exception->getMessage());

            return null;
        }
    }

    /**
     * @param  list<string>  $cells
     * @param  list<string>  $errors
     */
    private function parseStatus(array $cells, array &$errors): TransactionStatus
    {
        if ($this->status_column === null) {
            return TransactionStatus::Completed;
        }

        $value = trim($cells[$this->status_column]);
        $status = TransactionStatus::tryFrom($this->status_map[$value] ?? '');

        if ($status === null) {
            $errors[] = sprintf('%s "%s" is not mapped to completed, pending or reverted', $this->header[$this->status_column], $value);

            return TransactionStatus::Completed;
        }

        return $status;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'skip_lines' => 'integer',
            'header' => 'array',
            'date_column' => 'integer',
            'description_column' => 'integer',
            'amount_mode' => AmountMode::class,
            'amount_column' => 'integer',
            'debit_column' => 'integer',
            'credit_column' => 'integer',
            'invert_sign' => 'boolean',
            'fee_column' => 'integer',
            'balance_column' => 'integer',
            'currency_column' => 'integer',
            'status_column' => 'integer',
            'status_map' => 'array',
            'filter_column' => 'integer',
        ];
    }
}
