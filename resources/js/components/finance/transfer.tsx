import { router, useHttp } from '@inertiajs/react';
import { ArrowLeftRight, Ellipsis } from 'lucide-react';
import { useEffect, useState } from 'react';
import TransferController from '@/actions/App/Http/Controllers/Finance/TransferController';
import { Money } from '@/components/finance/money';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import type { TransactionRow, TransferSource } from '@/types/finance';

const SOURCE_LABELS: Record<TransferSource, string> = {
    auto: 'paired automatically',
    rule: 'marked by rule',
    manual: 'set by hand',
};

/**
 * Shown instead of the category on a transfer: the counterpart's account, or that there is none.
 */
export function TransferMarker({
    transaction,
}: {
    transaction: TransactionRow;
}) {
    const { counterpart } = transaction;

    return (
        <div data-test="transfer-marker">
            <Badge variant="secondary" className="gap-1">
                <ArrowLeftRight className="size-3" />
                {counterpart
                    ? `Transfer ${transaction.amount < 0 ? 'to' : 'from'} ${counterpart.account}`
                    : 'Transfer, no counterpart'}
            </Badge>
            {transaction.transfer_source && (
                <div className="mt-0.5 text-xs text-muted-foreground">
                    {SOURCE_LABELS[transaction.transfer_source]}
                </div>
            )}
        </div>
    );
}

const preserve = { preserveScroll: true, preserveState: true } as const;

/**
 * The transfer menu of a transaction row: pair with a counterpart, unpair, mark without a
 * counterpart, unmark.
 */
export function TransferActions({
    transaction,
}: {
    transaction: TransactionRow;
}) {
    const [pairing, setPairing] = useState(false);
    const paired = transaction.counterpart !== null;

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        size="icon"
                        variant="ghost"
                        className="size-8"
                        aria-label={`Transfer actions for ${transaction.description}`}
                    >
                        <Ellipsis />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    {paired ? (
                        <DropdownMenuItem
                            onSelect={() =>
                                router.delete(
                                    TransferController.unpair.url(
                                        transaction.id,
                                    ),
                                    preserve,
                                )
                            }
                        >
                            Unpair
                        </DropdownMenuItem>
                    ) : (
                        <DropdownMenuItem onSelect={() => setPairing(true)}>
                            Pair with…
                        </DropdownMenuItem>
                    )}
                    {!transaction.is_transfer && (
                        <DropdownMenuItem
                            onSelect={() =>
                                router.post(
                                    TransferController.mark.url(transaction.id),
                                    {},
                                    preserve,
                                )
                            }
                        >
                            Mark as transfer (no counterpart)
                        </DropdownMenuItem>
                    )}
                    {transaction.is_transfer && (
                        <DropdownMenuItem
                            onSelect={() =>
                                router.delete(
                                    TransferController.unmark.url(
                                        transaction.id,
                                    ),
                                    preserve,
                                )
                            }
                        >
                            Not a transfer
                        </DropdownMenuItem>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>
            {pairing && (
                <PairDialog
                    transaction={transaction}
                    onClose={() => setPairing(false)}
                />
            )}
        </>
    );
}

function PairDialog({
    transaction,
    onClose,
}: {
    transaction: TransactionRow;
    onClose: () => void;
}) {
    const [search, setSearch] = useState('');
    const [error, setError] = useState<string | null>(null);
    const http = useHttp<
        Record<string, never>,
        { candidates: TransactionRow[] }
    >({});

    useEffect(() => {
        const timer = window.setTimeout(
            () =>
                http
                    .get(
                        TransferController.candidates.url(transaction.id, {
                            query: search.trim() ? { search } : {},
                        }),
                    )
                    .catch(() => {}),
            search ? 300 : 0,
        );

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search, transaction.id]);

    function pair(candidate: TransactionRow) {
        router.post(
            TransferController.pair.url(transaction.id),
            { counterpart_id: candidate.id },
            {
                ...preserve,
                onSuccess: onClose,
                onError: (errors) =>
                    setError(errors.transfer ?? errors.counterpart_id ?? null),
            },
        );
    }

    const candidates = http.response?.candidates ?? [];

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Pair as transfer</DialogTitle>
                    <DialogDescription>
                        {transaction.date} · {transaction.account} ·{' '}
                        {transaction.description} ·{' '}
                        <Money value={transaction.amount} signed />
                    </DialogDescription>
                </DialogHeader>
                <Input
                    aria-label="Search counterparts"
                    placeholder="Search description, payee or amount…"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                />
                <p
                    className="text-xs text-muted-foreground"
                    data-test="candidate-count"
                >
                    {http.processing
                        ? 'Searching…'
                        : search.trim()
                          ? `${candidates.length} unpaired ${candidates.length === 1 ? 'transaction' : 'transactions'} in other accounts`
                          : candidates.length === 0
                            ? 'No opposite amount within 3 days. Search for a counterpart.'
                            : `${candidates.length} ${candidates.length === 1 ? 'candidate' : 'candidates'}: opposite amount within 3 days, closest first`}
                </p>
                {error && <p className="text-sm text-destructive">{error}</p>}
                <ul className="max-h-80 divide-y overflow-y-auto">
                    {candidates.map((candidate) => (
                        <li
                            key={candidate.id}
                            className="flex items-center justify-between gap-3 py-2 text-sm"
                        >
                            <div className="min-w-0">
                                <div className="truncate">
                                    {candidate.payee ?? candidate.description}
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    {candidate.date}{' '}
                                    {candidate.time?.slice(0, 5)} ·{' '}
                                    {candidate.account}
                                    {candidate.is_transfer &&
                                        ' · transfer, no counterpart'}
                                </div>
                            </div>
                            <div className="flex items-center gap-3">
                                <Money value={candidate.amount} signed />
                                <Button
                                    size="sm"
                                    variant="outline"
                                    aria-label={`Pair with ${candidate.description} on ${candidate.date} in ${candidate.account}`}
                                    onClick={() => pair(candidate)}
                                >
                                    Pair
                                </Button>
                            </div>
                        </li>
                    ))}
                </ul>
            </DialogContent>
        </Dialog>
    );
}
