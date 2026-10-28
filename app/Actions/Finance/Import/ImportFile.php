<?php

namespace App\Actions\Finance\Import;

use App\Models\Finance\AccountType;
use App\Models\Finance\BalanceSnapshot;
use App\Models\Finance\ImportBatch;
use App\Models\Finance\ImportMapping;
use App\Models\Finance\SnapshotSource;
use App\Models\Finance\Transaction;
use App\Models\Finance\TransactionStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Imports a bank CSV through every saved mapping that matches its shape.
 *
 * Bank files carry no transaction ids, so each row gets a fingerprint: its date, time (when the
 * date format has one), normalized description and net amount, plus how many identical rows came
 * before it in the same file. Re-importing the same rows, or a file whose date range overlaps,
 * finds the same fingerprints and creates nothing. A pending row that later arrives completed, or
 * with a changed amount or fee, is matched on date, time and description and updated in place; a
 * reverted row removes the transaction it matches.
 */
final class ImportFile
{
    /**
     * Saved mappings of active accounts whose shape matches the file exactly.
     *
     * @return Collection<int, ImportMapping>
     */
    public function matchingMappings(User $user, string $bytes): Collection
    {
        $signatures = [];

        return ImportMapping::query()
            ->ownedBy($user)
            ->whereHas('account', fn ($query) => $query->whereNull('archived_at')->whereIn('type', AccountType::importable()))
            ->with('account')
            ->orderBy('id')
            ->get()
            ->filter(function (ImportMapping $mapping) use ($bytes, &$signatures): bool {
                $settings = "{$mapping->encoding}|{$mapping->delimiter}|{$mapping->skip_lines}";
                $signatures[$settings] ??= CsvReader::signature(
                    $mapping->encoding,
                    $mapping->delimiter,
                    $mapping->skip_lines,
                    CsvReader::read($bytes, $mapping->encoding, $mapping->delimiter, $mapping->skip_lines)['header'],
                );

                return $signatures[$settings] === $mapping->signature;
            })
            ->values();
    }

    /**
     * @param  Collection<int, ImportMapping>  $mappings  mappings of one shape, from matchingMappings()
     *
     * @throws ImportRejected when any selected row fails to parse; nothing is written.
     */
    public function __invoke(User $user, string $bytes, string $filename, Collection $mappings): ImportBatch
    {
        $first = $mappings->first();

        if ($first === null) {
            throw new ImportRejected('No saved mapping matches this file.');
        }

        $file = CsvReader::read($bytes, $first->encoding, $first->delimiter, $first->skip_lines);
        $parsed = $mappings->mapWithKeys(fn (ImportMapping $mapping): array => [$mapping->id => []])->all();
        $errors = [];
        $unmapped = 0;

        foreach ($file['rows'] as $row) {
            if (count($row['cells']) !== count($file['header'])) {
                $errors[] = ['line' => $row['line'], 'mapping' => '', 'message' => sprintf('has %d columns, the header has %d', count($row['cells']), count($file['header']))];

                continue;
            }

            $selected = $mappings->filter(fn (ImportMapping $mapping): bool => $mapping->selects($row['cells']));

            if ($selected->isEmpty()) {
                $unmapped++;
            }

            foreach ($selected as $mapping) {
                $result = $mapping->parseRow($row['cells'], $row['line'], $mapping->account->currency);
                $parsed[$mapping->id][] = $result;

                foreach ($result->errors as $message) {
                    $errors[] = ['line' => $row['line'], 'mapping' => $mapping->name, 'message' => $message];
                }
            }
        }

        if ($errors !== []) {
            throw new ImportRejected(
                sprintf('%d of %d rows could not be read. Nothing was imported.', count(array_unique(array_column($errors, 'line'))), count($file['rows'])),
                $errors,
            );
        }

        return DB::transaction(function () use ($user, $filename, $first, $mappings, $parsed, $unmapped): ImportBatch {
            $batch = new ImportBatch(['filename' => $filename, 'signature' => $first->signature, 'summary' => []]);
            $batch->user_id = $user->id;
            $batch->save();

            $accounts = [];
            $currencyRows = [];

            foreach ($mappings as $mapping) {
                $accounts[] = $this->importRows($batch, $mapping, $parsed[$mapping->id], $currencyRows);
            }

            $counts = ['created' => 0, 'updated' => 0, 'skipped_duplicate' => 0, 'skipped_currency' => 0, 'removed_reverted' => 0, 'skipped_reverted' => 0];

            foreach ($accounts as $account) {
                foreach (array_keys($counts) as $key) {
                    $counts[$key] += $account[$key];
                }
            }

            $batch->summary = [
                'created' => $counts['created'],
                'updated' => $counts['updated'],
                'skipped_duplicate' => $counts['skipped_duplicate'],
                'skipped_currency' => $counts['skipped_currency'],
                'removed_reverted' => $counts['removed_reverted'],
                'skipped_reverted' => $counts['skipped_reverted'],
                'unmapped_rows' => $unmapped,
                'accounts' => $accounts,
                'currency_rows' => $currencyRows,
            ];
            $batch->save();

            return $batch;
        });
    }

    /**
     * @param  list<ParsedRow>  $rows
     * @param  list<array{line: int, account: string, date: string, description: string, amount: string, currency: string}>  $currencyRows
     * @return array{account_id: int, account: string, mapping: string, created: int, updated: int, skipped_duplicate: int, skipped_currency: int, removed_reverted: int, skipped_reverted: int, snapshot: array{date: string, balance: int}|null}
     */
    private function importRows(ImportBatch $batch, ImportMapping $mapping, array $rows, array &$currencyRows): array
    {
        $account = $mapping->account;
        $counts = ['created' => 0, 'updated' => 0, 'skipped_duplicate' => 0, 'skipped_currency' => 0, 'removed_reverted' => 0, 'skipped_reverted' => 0];

        $entries = [];
        $occurrences = [];

        foreach ($rows as $row) {
            if ($row->foreignCurrency) {
                $counts['skipped_currency']++;
                $currencyRows[] = [
                    'line' => $row->line,
                    'account' => $account->name,
                    'date' => $row->date,
                    'description' => $row->description,
                    'amount' => $row->rawAmount,
                    'currency' => (string) $row->currency,
                ];

                continue;
            }

            $identity = $row->identity();
            $key = json_encode($identity, JSON_THROW_ON_ERROR);
            $occurrences[$key] = ($occurrences[$key] ?? 0) + 1;
            $entries[] = [$row, hash('sha256', json_encode([...$identity, $occurrences[$key]], JSON_THROW_ON_ERROR))];
        }

        /** @var array<string, Transaction> $existing */
        $existing = [];

        foreach (array_chunk(array_column($entries, 1), 500) as $fingerprints) {
            Transaction::query()->where('account_id', $account->id)->whereIn('fingerprint', $fingerprints)->get()
                ->each(function (Transaction $transaction) use (&$existing): void {
                    $existing[(string) $transaction->fingerprint] = $transaction;
                });
        }

        $pending = Transaction::query()->where('account_id', $account->id)->where('status', TransactionStatus::Pending)->orderBy('id')->get();
        $claimed = [];
        $unmatched = [];

        // Pass 1: rows already imported under the same fingerprint.
        foreach ($entries as [$row, $fingerprint]) {
            $transaction = $existing[$fingerprint] ?? null;

            if ($transaction === null) {
                $unmatched[] = [$row, $fingerprint];

                continue;
            }

            $claimed[$transaction->id] = true;

            if ($row->status === TransactionStatus::Reverted) {
                $this->remove($batch, $transaction);
                $counts['removed_reverted']++;
            } elseif ($transaction->status === TransactionStatus::Pending && $row->status === TransactionStatus::Completed) {
                $this->update($batch, $transaction, $row, $fingerprint);
                $counts['updated']++;
            } else {
                $counts['skipped_duplicate']++;
            }
        }

        // Pass 2: a pending transaction whose amount, fee or status changed, or a new row.
        foreach ($unmatched as [$row, $fingerprint]) {
            $candidates = $pending->filter(fn (Transaction $transaction): bool => ! isset($claimed[$transaction->id])
                && $transaction->date === $row->date
                && $transaction->time === $row->time
                && $this->sameDescription($transaction->description, $row->description));
            $transaction = $candidates->first(fn (Transaction $transaction): bool => $transaction->amount === $row->amount) ?? $candidates->first();

            if ($transaction !== null) {
                $claimed[$transaction->id] = true;

                if ($row->status === TransactionStatus::Reverted) {
                    $this->remove($batch, $transaction);
                    $counts['removed_reverted']++;
                } else {
                    $this->update($batch, $transaction, $row, $fingerprint);
                    $counts['updated']++;
                }

                continue;
            }

            if ($row->status === TransactionStatus::Reverted) {
                $counts['skipped_reverted']++;

                continue;
            }

            $this->create($batch, $mapping, $row, $fingerprint);
            $counts['created']++;
        }

        return [
            'account_id' => $account->id,
            'account' => $account->name,
            'mapping' => $mapping->name,
            ...$counts,
            'snapshot' => $this->snapshot($batch, $mapping, array_column($entries, 0)),
        ];
    }

    private function create(ImportBatch $batch, ImportMapping $mapping, ParsedRow $row, string $fingerprint): void
    {
        $transaction = new Transaction([
            'account_id' => $mapping->account_id,
            'import_batch_id' => $batch->id,
            'date' => $row->date,
            'time' => $row->time,
            'description' => $row->description,
            'amount' => $row->amount,
            'fee' => $row->fee,
            'balance_after' => $row->balance,
            'currency' => $mapping->account->currency,
            'status' => $row->status,
            'fingerprint' => $fingerprint,
        ]);
        $transaction->user_id = $batch->user_id;
        $transaction->save();

        $batch->changes()->create(['subject_type' => 'transaction', 'subject_id' => $transaction->id, 'action' => 'created']);
    }

    private function update(ImportBatch $batch, Transaction $transaction, ParsedRow $row, string $fingerprint): void
    {
        $batch->changes()->create([
            'subject_type' => 'transaction',
            'subject_id' => $transaction->id,
            'action' => 'updated',
            'before' => $transaction->only(UndoImportBatch::IMPORTED_TRANSACTION_FIELDS),
        ]);

        $transaction->fill([
            'amount' => $row->amount,
            'fee' => $row->fee,
            'status' => $row->status,
            'balance_after' => $row->balance ?? $transaction->balance_after,
            'fingerprint' => $fingerprint,
        ])->save();
    }

    private function remove(ImportBatch $batch, Transaction $transaction): void
    {
        $batch->changes()->create([
            'subject_type' => 'transaction',
            'subject_id' => $transaction->id,
            'action' => 'removed',
            'before' => $transaction->getAttributes(),
        ]);

        $transaction->delete();
    }

    /**
     * The balance of the last row that has one, in file order, becomes a snapshot. Its date is the
     * latest completed date up to that row, so every completed row it includes is on or before it.
     *
     * @param  list<ParsedRow>  $rows
     * @return array{date: string, balance: int}|null
     */
    private function snapshot(ImportBatch $batch, ImportMapping $mapping, array $rows): ?array
    {
        $latest = null;
        $snapshot = null;

        foreach ($rows as $row) {
            if ($row->status === TransactionStatus::Completed) {
                $latest = max($latest ?? $row->date, $row->date);
            }

            if ($row->balance !== null && $row->status !== TransactionStatus::Reverted) {
                $snapshot = ['date' => max($latest ?? $row->date, $row->date), 'balance' => $row->balance];
            }
        }

        if ($snapshot === null) {
            return null;
        }

        $existing = BalanceSnapshot::query()->where('account_id', $mapping->account_id)->where('date', $snapshot['date'])->first();

        if ($existing !== null && $existing->balance === $snapshot['balance'] && $existing->source === SnapshotSource::Import) {
            return $snapshot;
        }

        if ($existing !== null) {
            $batch->changes()->create([
                'subject_type' => 'snapshot',
                'subject_id' => $existing->id,
                'action' => 'updated',
                'before' => $existing->only(['balance', 'source', 'import_batch_id']),
            ]);

            $existing->fill(['balance' => $snapshot['balance'], 'source' => SnapshotSource::Import, 'import_batch_id' => $batch->id])->save();

            return $snapshot;
        }

        $created = new BalanceSnapshot([
            'account_id' => $mapping->account_id,
            'import_batch_id' => $batch->id,
            'date' => $snapshot['date'],
            'balance' => $snapshot['balance'],
            'source' => SnapshotSource::Import,
        ]);
        $created->user_id = $batch->user_id;
        $created->save();

        $batch->changes()->create(['subject_type' => 'snapshot', 'subject_id' => $created->id, 'action' => 'created']);

        return $snapshot;
    }

    private function sameDescription(string $a, string $b): bool
    {
        $normalize = fn (string $value): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));

        return $normalize($a) === $normalize($b);
    }
}
