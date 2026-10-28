import { Head, Link, router } from '@inertiajs/react';
import { Undo2 } from 'lucide-react';
import ImportBatchController from '@/actions/App/Http/Controllers/Finance/ImportBatchController';
import { DataTable, Td, Th } from '@/components/finance/data-table';
import { Money } from '@/components/finance/money';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { show as showAccount } from '@/routes/finance/accounts';
import { show } from '@/routes/finance/batches';
import { index } from '@/routes/finance/import';
import { index as subscriptions } from '@/routes/finance/subscriptions';
import { index as transfers } from '@/routes/finance/transfers';
import type { Batch } from '@/types/finance';

export default function ShowBatch({ batch }: { batch: Batch }) {
    const { summary } = batch;
    const totals: [string, number][] = [
        ['Created', summary.created],
        ['Updated', summary.updated],
        ['Skipped as duplicate', summary.skipped_duplicate],
        ['Skipped as not CHF', summary.skipped_currency],
        ['Removed as reverted', summary.removed_reverted],
        ['Set by rules', summary.categorized_by_rules ?? 0],
        ['Paired as transfers', summary.paired_as_transfers ?? 0],
        ['Linked to subscriptions', summary.linked_to_subscriptions ?? 0],
    ];

    return (
        <>
            <Head title={`Import of ${batch.filename}`} />
            <div className="space-y-8 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <h1 className="flex items-center gap-2 text-xl font-semibold tracking-tight">
                            Import of {batch.filename}
                            {batch.undone_at && (
                                <Badge variant="secondary">Undone</Badge>
                            )}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {new Date(batch.created_at).toLocaleString('de-CH')}
                        </p>
                    </div>
                    {!batch.undone_at && (
                        <div className="space-y-1 text-right">
                            <Button
                                variant="outline"
                                disabled={batch.blocked_by !== null}
                                onClick={() => {
                                    if (
                                        window.confirm(
                                            'Undo this import? Everything it created, updated or removed is reverted.',
                                        )
                                    ) {
                                        router.post(
                                            ImportBatchController.undo.url(
                                                batch.id,
                                            ),
                                        );
                                    }
                                }}
                            >
                                <Undo2 /> Undo import
                            </Button>
                            {batch.blocked_by && (
                                <p className="text-xs text-muted-foreground">
                                    Undo the later import of{' '}
                                    <Link
                                        href={show(batch.blocked_by.id)}
                                        className="underline"
                                    >
                                        {batch.blocked_by.filename}
                                    </Link>{' '}
                                    first.
                                </p>
                            )}
                        </div>
                    )}
                </div>

                <dl
                    className="grid gap-4 sm:grid-cols-4 lg:grid-cols-7"
                    data-test="batch-summary"
                >
                    {totals.map(([label, value]) => (
                        <div key={label} className="rounded-xl border p-4">
                            <dt className="text-sm text-muted-foreground">
                                {label}
                            </dt>
                            <dd className="text-2xl font-semibold tabular-nums">
                                {value}
                            </dd>
                        </div>
                    ))}
                </dl>

                {(summary.transfer_suggestions ?? 0) > 0 &&
                    !batch.undone_at && (
                        <p className="text-sm" data-test="transfer-suggestions">
                            {summary.transfer_suggestions}{' '}
                            {summary.transfer_suggestions === 1
                                ? 'possible transfer pair needs'
                                : 'possible transfer pairs need'}{' '}
                            a decision.{' '}
                            <Link href={transfers()} className="underline">
                                Review suggestions
                            </Link>
                        </p>
                    )}

                {(summary.subscription_suggestions ?? 0) > 0 &&
                    !batch.undone_at && (
                        <p
                            className="text-sm"
                            data-test="subscription-suggestions"
                        >
                            {summary.subscription_suggestions}{' '}
                            {summary.subscription_suggestions === 1
                                ? 'possible subscription needs'
                                : 'possible subscriptions need'}{' '}
                            a decision.{' '}
                            <Link href={subscriptions()} className="underline">
                                Review subscriptions
                            </Link>
                        </p>
                    )}

                {(summary.unmapped_rows > 0 ||
                    summary.skipped_reverted > 0) && (
                    <p className="text-sm text-muted-foreground">
                        {summary.unmapped_rows > 0 &&
                            `${summary.unmapped_rows} ${summary.unmapped_rows === 1 ? 'row' : 'rows'} matched no mapping's row filter. `}
                        {summary.skipped_reverted > 0 &&
                            `${summary.skipped_reverted} reverted ${summary.skipped_reverted === 1 ? 'row' : 'rows'} had nothing to remove.`}
                    </p>
                )}

                <section className="space-y-4">
                    <Heading variant="small" title="Per account" />
                    <DataTable>
                        <thead>
                            <tr>
                                <Th>Account</Th>
                                <Th>Mapping</Th>
                                <Th className="text-right">Created</Th>
                                <Th className="text-right">Updated</Th>
                                <Th className="text-right">Duplicates</Th>
                                <Th className="text-right">Not CHF</Th>
                                <Th className="text-right">Reverted</Th>
                                <Th className="text-right">Snapshot</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {summary.accounts.map((account) => (
                                <tr key={account.mapping}>
                                    <Td className="font-medium">
                                        <Link
                                            href={showAccount(
                                                account.account_id,
                                            )}
                                            className="hover:underline"
                                        >
                                            {account.account}
                                        </Link>
                                    </Td>
                                    <Td>{account.mapping}</Td>
                                    <Td className="text-right tabular-nums">
                                        {account.created}
                                    </Td>
                                    <Td className="text-right tabular-nums">
                                        {account.updated}
                                    </Td>
                                    <Td className="text-right tabular-nums">
                                        {account.skipped_duplicate}
                                    </Td>
                                    <Td className="text-right tabular-nums">
                                        {account.skipped_currency}
                                    </Td>
                                    <Td className="text-right tabular-nums">
                                        {account.removed_reverted}
                                    </Td>
                                    <Td className="text-right whitespace-nowrap">
                                        {account.snapshot ? (
                                            <>
                                                <Money
                                                    value={
                                                        account.snapshot.balance
                                                    }
                                                />{' '}
                                                <span className="text-muted-foreground">
                                                    on {account.snapshot.date}
                                                </span>
                                            </>
                                        ) : (
                                            '—'
                                        )}
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </DataTable>
                </section>

                {summary.currency_rows.length > 0 && (
                    <section className="space-y-4">
                        <Heading
                            variant="small"
                            title="Skipped rows in other currencies"
                        />
                        <DataTable>
                            <thead>
                                <tr>
                                    <Th>Line</Th>
                                    <Th>Account</Th>
                                    <Th>Date</Th>
                                    <Th>Description</Th>
                                    <Th className="text-right">Amount</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {summary.currency_rows.map((row) => (
                                    <tr key={`${row.account}-${row.line}`}>
                                        <Td>{row.line}</Td>
                                        <Td>{row.account}</Td>
                                        <Td>{row.date}</Td>
                                        <Td>{row.description}</Td>
                                        <Td className="text-right whitespace-nowrap">
                                            {row.currency} {row.amount}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </DataTable>
                    </section>
                )}
            </div>
        </>
    );
}

ShowBatch.layout = {
    breadcrumbs: [{ title: 'Import', href: index() }],
};
