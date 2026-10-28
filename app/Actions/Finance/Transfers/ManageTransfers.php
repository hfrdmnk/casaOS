<?php

namespace App\Actions\Finance\Transfers;

use App\Models\Finance\Transaction;
use App\Models\Finance\TransferSource;
use App\Models\Finance\TransferSuggestion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Everything a user does with transfers: find pairs, pair and unpair by hand, mark or unmark a
 * transfer without a counterpart, and confirm or dismiss suggestions. The web controllers and
 * later MCP tools call this class, so they enforce the same rules with the same messages (thrown
 * as a ValidationException keyed `transfer`). A transaction of another user is not found.
 *
 * Every manual action sets the source to manual on the rows the user decided, so rules and
 * automatic pairing never change them again. Transfers carry no category: marking or pairing
 * clears it.
 */
final class ManageTransfers
{
    public function __construct(private PairTransfers $pairing) {}

    /**
     * "Find transfers": pair what is unambiguous across all the user's transactions and refresh
     * the open suggestions.
     *
     * @return array{paired: int, suggested: int}
     */
    public function find(User $user): array
    {
        return ($this->pairing)($user);
    }

    /**
     * Pair two transactions by hand: money leaving one account and arriving in another. Neither
     * may be paired already. Amounts need not match exactly.
     *
     * @throws ValidationException
     */
    public function pair(Transaction $transaction, Transaction $counterpart): void
    {
        $this->ensureSameUser($transaction, $counterpart);

        $error = match (true) {
            $transaction->is($counterpart) || $transaction->account_id === $counterpart->account_id => 'Pick a transaction in another account.',
            ($transaction->amount < 0) === ($counterpart->amount < 0) || $transaction->amount === 0 || $counterpart->amount === 0 => 'A transfer pairs money leaving one account with money arriving in another.',
            $transaction->transfer_counterpart_id !== null => 'This transaction is already paired. Unpair it first.',
            $counterpart->transfer_counterpart_id !== null => 'That transaction is already paired. Unpair it first.',
            default => null,
        };

        if ($error !== null) {
            throw ValidationException::withMessages(['transfer' => $error]);
        }

        DB::transaction(function () use ($transaction, $counterpart): void {
            $this->decide($transaction, true, $counterpart->id);
            $this->decide($counterpart, true, $transaction->id);
        });
    }

    /**
     * Unpair a pair by hand. Both sides stay transfers without a counterpart (so neither counts as
     * income or spending) and are never paired automatically again; unmark one to make it a plain
     * transaction, or pair it with the right counterpart.
     *
     * @throws ValidationException
     */
    public function unpair(Transaction $transaction): void
    {
        $counterpart = $transaction->transferCounterpart;

        if ($counterpart === null) {
            throw ValidationException::withMessages(['transfer' => 'This transaction is not paired.']);
        }

        DB::transaction(function () use ($transaction, $counterpart): void {
            $this->decide($transaction, true, null);
            $this->decide($counterpart, true, null);
        });
    }

    /**
     * Mark a transfer to or from an account the app does not track.
     *
     * @throws ValidationException
     */
    public function markWithoutCounterpart(Transaction $transaction): void
    {
        if ($transaction->transfer_counterpart_id !== null) {
            throw ValidationException::withMessages(['transfer' => 'This transaction is paired. Unpair it first.']);
        }

        DB::transaction(fn () => $this->decide($transaction, true, null));
    }

    /**
     * Not a transfer: the transaction counts as income or spending again and is never marked or
     * paired automatically. Its counterpart, if any, loses the pair (see release()).
     */
    public function unmark(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction): void {
            $counterpart = $transaction->transferCounterpart;
            $this->decide($transaction, false, null);

            if ($counterpart !== null) {
                $this->release($counterpart->refresh());
            }
        });
    }

    /**
     * Confirm a suggestion: the two sides become a pair, as if paired by hand.
     *
     * @throws ValidationException
     */
    public function confirm(TransferSuggestion $suggestion): void
    {
        DB::transaction(function () use ($suggestion): void {
            $this->pair($suggestion->outflow, $suggestion->inflow);
            $suggestion->delete();
        });
    }

    /**
     * Dismiss a suggestion for good: the two are never suggested or paired automatically again.
     */
    public function dismiss(TransferSuggestion $suggestion): void
    {
        $suggestion->forceFill(['dismissed_at' => now()])->save();
    }

    /**
     * Open suggestions whose two sides can still pair, oldest first.
     *
     * @return Collection<int, TransferSuggestion>
     */
    public function suggestions(User $user): Collection
    {
        return $this->openSuggestions(TransferSuggestion::query()->ownedBy($user))
            ->with(['outflow.account', 'inflow.account'])
            ->get()
            ->sortBy(fn (TransferSuggestion $suggestion): string => "{$suggestion->outflow->date} {$suggestion->outflow->time} ".str_pad((string) $suggestion->id, 12, '0', STR_PAD_LEFT))
            ->values();
    }

    /**
     * Open suggestions whose two sides can still pair.
     *
     * @param  Builder<TransferSuggestion>  $query
     * @return Builder<TransferSuggestion>
     */
    public function openSuggestions(Builder $query): Builder
    {
        return $query->whereNull('dismissed_at')
            ->whereHas('outflow', fn (Builder $query) => PairTransfers::eligible($query))
            ->whereHas('inflow', fn (Builder $query) => PairTransfers::eligible($query));
    }

    /**
     * Transactions the user can pair this one with: unpaired, in another account, with the
     * opposite sign. Without `$search`, the pairing candidates (opposite amount, within 3 days),
     * closest first; with it, any such transaction whose description, payee or amount matches.
     *
     * @return Collection<int, Transaction>
     */
    public function candidates(Transaction $transaction, ?string $search = null, int $limit = 20): Collection
    {
        $query = Transaction::query()->ownedBy($transaction->user)
            ->with(['account', 'payee'])
            ->whereKeyNot($transaction->id)
            ->where('account_id', '!=', $transaction->account_id)
            ->whereNull('transfer_counterpart_id')
            ->where('amount', $transaction->amount < 0 ? '>' : '<', 0);

        if ($search === null || trim($search) === '') {
            return $query->where('amount', -$transaction->amount)
                ->whereBetween('date', [
                    date('Y-m-d', (int) strtotime("{$transaction->date} -".PairTransfers::WINDOW_DAYS.' days')),
                    date('Y-m-d', (int) strtotime("{$transaction->date} +".PairTransfers::WINDOW_DAYS.' days')),
                ])
                ->get()
                ->sortBy(fn (Transaction $candidate): array => [...(PairTransfers::rank($transaction, $candidate) ?? [9, 0]), $candidate->id])
                ->take($limit)
                ->values();
        }

        $search = trim($search);
        $rappen = preg_match('/^\d+([.,]\d{1,2})?$/', $search) === 1 ? (int) round((float) str_replace(',', '.', $search) * 100) : null;

        return $query->where(fn (Builder $query) => $query
            ->whereLike('description', '%'.$search.'%', caseSensitive: false)
            ->orWhereHas('payee', fn (Builder $query) => $query->whereLike('name', '%'.$search.'%', caseSensitive: false))
            ->when($rappen !== null, fn (Builder $query) => $query->orWhere('amount', $rappen)->orWhere('amount', -$rappen)))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(500)
            ->get()
            ->sortBy(fn (Transaction $candidate): array => [abs(strtotime($candidate->date) - strtotime($transaction->date)), $candidate->id])
            ->take($limit)
            ->values();
    }

    /**
     * Break a transaction's pair without deciding anything by hand, e.g. because the import that
     * created one side is undone. The counterpart falls back: to a plain transaction when pairing
     * made it a transfer, else to a transfer without a counterpart. The transaction itself only
     * loses its counterpart; the caller decides what else happens to it.
     */
    public function detach(Transaction $transaction): void
    {
        $counterpart = $transaction->transferCounterpart;

        if ($counterpart === null) {
            return;
        }

        $transaction->forceFill(['transfer_counterpart_id' => null])->save();
        $this->release($counterpart->refresh());
    }

    /**
     * A transaction lost its counterpart: drop the link, and the transfer mark too when pairing
     * set it.
     */
    public function release(Transaction $transaction): void
    {
        $transaction->forceFill([
            'transfer_counterpart_id' => null,
            ...($transaction->transfer_source === TransferSource::Auto ? ['is_transfer' => false, 'transfer_source' => null] : []),
        ])->save();
    }

    /**
     * Apply a manual decision to one transaction and drop its open suggestions.
     */
    private function decide(Transaction $transaction, bool $isTransfer, ?int $counterpart): void
    {
        $transaction->forceFill([
            'is_transfer' => $isTransfer,
            'transfer_source' => TransferSource::Manual,
            'transfer_counterpart_id' => $counterpart,
            ...($isTransfer ? ['category_id' => null, 'category_source' => null] : []),
        ])->save();

        TransferSuggestion::query()->whereNull('dismissed_at')
            ->where(fn (Builder $query) => $query->where('outflow_id', $transaction->id)->orWhere('inflow_id', $transaction->id))
            ->delete();
    }

    private function ensureSameUser(Transaction $transaction, Transaction $counterpart): void
    {
        if ($transaction->user_id !== $counterpart->user_id) {
            throw (new ModelNotFoundException)->setModel(Transaction::class, [$counterpart->id]);
        }
    }
}
