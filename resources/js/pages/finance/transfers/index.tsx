import { Head, router } from '@inertiajs/react';
import { ArrowLeftRight, Check, X } from 'lucide-react';
import TransferController from '@/actions/App/Http/Controllers/Finance/TransferController';
import { DataTable, Td, Th } from '@/components/finance/data-table';
import { Money } from '@/components/finance/money';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/finance/transfers';
import type { TransactionRow, TransferSuggestion } from '@/types/finance';

export default function Transfers({
    suggestions,
    paired,
}: {
    suggestions: TransferSuggestion[];
    paired: number;
}) {
    const visit = { preserveScroll: true } as const;

    return (
        <>
            <Head title="Transfers" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Transfers"
                        description="Money moving between your accounts is neither income nor spending. Pairs that could match more than one transaction wait here for you."
                    />
                    <Button
                        variant="outline"
                        onClick={() =>
                            router.post(
                                TransferController.find.url(),
                                {},
                                visit,
                            )
                        }
                    >
                        <ArrowLeftRight /> Find transfers
                    </Button>
                </div>

                <p
                    className="text-sm text-muted-foreground"
                    data-test="transfer-counts"
                >
                    {paired} {paired === 1 ? 'pair' : 'pairs'} ·{' '}
                    {suggestions.length}{' '}
                    {suggestions.length === 1 ? 'suggestion' : 'suggestions'} to
                    review
                </p>

                {suggestions.length === 0 ? (
                    <p className="rounded-xl border p-6 text-sm text-muted-foreground">
                        No suggestions to review.
                    </p>
                ) : (
                    <DataTable>
                        <thead>
                            <tr>
                                <Th>Money leaving</Th>
                                <Th />
                                <Th>Money arriving</Th>
                                <Th className="text-right">Amount</Th>
                                <Th />
                            </tr>
                        </thead>
                        <tbody>
                            {suggestions.map((suggestion) => (
                                <tr key={suggestion.id} data-test="suggestion">
                                    <Td>
                                        <Side
                                            transaction={suggestion.outflow}
                                        />
                                    </Td>
                                    <Td className="text-muted-foreground">
                                        <ArrowLeftRight className="size-4" />
                                    </Td>
                                    <Td>
                                        <Side transaction={suggestion.inflow} />
                                    </Td>
                                    <Td className="text-right whitespace-nowrap">
                                        <Money
                                            value={suggestion.inflow.amount}
                                        />
                                    </Td>
                                    <Td className="text-right whitespace-nowrap">
                                        <Button
                                            size="sm"
                                            aria-label={`Confirm ${suggestion.outflow.account} ${suggestion.outflow.date} ${suggestion.outflow.time ?? ''} with ${suggestion.inflow.account} ${suggestion.inflow.date} ${suggestion.inflow.time ?? ''}`}
                                            onClick={() =>
                                                router.post(
                                                    TransferController.confirm.url(
                                                        suggestion.id,
                                                    ),
                                                    {},
                                                    visit,
                                                )
                                            }
                                        >
                                            <Check /> Confirm
                                        </Button>{' '}
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            aria-label={`Dismiss ${suggestion.outflow.account} ${suggestion.outflow.date} ${suggestion.outflow.time ?? ''} with ${suggestion.inflow.account} ${suggestion.inflow.date} ${suggestion.inflow.time ?? ''}`}
                                            onClick={() =>
                                                router.post(
                                                    TransferController.dismiss.url(
                                                        suggestion.id,
                                                    ),
                                                    {},
                                                    visit,
                                                )
                                            }
                                        >
                                            <X /> Dismiss
                                        </Button>
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </DataTable>
                )}
            </div>
        </>
    );
}

function Side({ transaction }: { transaction: TransactionRow }) {
    return (
        <div>
            <div className="font-medium">{transaction.account}</div>
            <div>{transaction.payee ?? transaction.description}</div>
            <div className="text-xs text-muted-foreground">
                {transaction.date} {transaction.time?.slice(0, 5)}
            </div>
        </div>
    );
}

Transfers.layout = {
    breadcrumbs: [{ title: 'Transfers', href: index() }],
};
