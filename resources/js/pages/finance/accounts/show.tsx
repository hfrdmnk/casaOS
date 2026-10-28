import { Form, Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, Archive, ArchiveRestore, Pencil } from 'lucide-react';
import AccountController from '@/actions/App/Http/Controllers/Finance/AccountController';
import BalanceSnapshotController from '@/actions/App/Http/Controllers/Finance/BalanceSnapshotController';
import { AccountFormFields } from '@/components/finance/account-form-fields';
import { DataTable, Td, Th } from '@/components/finance/data-table';
import { Money } from '@/components/finance/money';
import { StatusBadge } from '@/components/finance/status-badge';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index, show } from '@/routes/finance/accounts';
import { edit as editMapping } from '@/routes/finance/mappings';
import { destroy as destroySnapshot } from '@/routes/finance/snapshots';
import { index as transactionsIndex } from '@/routes/finance/transactions';
import type {
    AccountSummary,
    AccountTypeOption,
    Option,
    TransactionRow,
} from '@/types/finance';

type SnapshotRow = {
    id: number;
    date: string;
    balance: number;
    source: 'manual' | 'import';
    expected: number | null;
    difference: number | null;
};

export default function ShowAccount({
    account,
    types,
    snapshots,
    mappings,
    recentTransactions,
}: {
    account: AccountSummary;
    types: AccountTypeOption[];
    snapshots: SnapshotRow[];
    mappings: Option[];
    recentTransactions: TransactionRow[];
}) {
    const today = new Date().toISOString().slice(0, 10);

    return (
        <>
            <Head title={account.name} />
            <div className="space-y-8 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <h1 className="flex items-center gap-2 text-xl font-semibold tracking-tight">
                            {account.name}
                            {account.archived && (
                                <Badge variant="secondary">Archived</Badge>
                            )}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {account.type_label} · {account.currency}
                            {!account.importable && ' · balance only'}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Dialog>
                            <DialogTrigger asChild>
                                <Button variant="outline">
                                    <Pencil /> Edit
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogTitle>Edit account</DialogTitle>
                                <DialogDescription>
                                    Rename the account or change its type.
                                </DialogDescription>
                                <Form
                                    {...AccountController.update.form(
                                        account.id,
                                    )}
                                    className="space-y-4"
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <AccountFormFields
                                                types={types}
                                                errors={errors}
                                                defaults={account}
                                            />
                                            <DialogFooter className="gap-2">
                                                <DialogClose asChild>
                                                    <Button
                                                        type="button"
                                                        variant="secondary"
                                                    >
                                                        Cancel
                                                    </Button>
                                                </DialogClose>
                                                <Button disabled={processing}>
                                                    Save
                                                </Button>
                                            </DialogFooter>
                                        </>
                                    )}
                                </Form>
                            </DialogContent>
                        </Dialog>
                        {account.archived ? (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.visit(
                                        AccountController.unarchive(account.id),
                                    )
                                }
                            >
                                <ArchiveRestore /> Restore
                            </Button>
                        ) : (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.visit(
                                        AccountController.archive(account.id),
                                    )
                                }
                            >
                                <Archive /> Archive
                            </Button>
                        )}
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <div className="rounded-xl border p-4">
                        <p className="text-sm text-muted-foreground">
                            Balance today
                        </p>
                        <p
                            className="text-2xl font-semibold"
                            data-test="account-balance"
                        >
                            <Money value={account.balance} />
                        </p>
                    </div>
                    <div className="rounded-xl border p-4">
                        <p className="text-sm text-muted-foreground">
                            Pending (not in balance)
                        </p>
                        <p className="text-2xl font-semibold">
                            <Money value={account.pending_total} />
                        </p>
                    </div>
                    <div className="rounded-xl border p-4">
                        <p className="text-sm text-muted-foreground">
                            Transactions
                        </p>
                        <p className="text-2xl font-semibold tabular-nums">
                            {account.importable
                                ? account.transactions_count
                                : '—'}
                        </p>
                    </div>
                </div>

                {account.mismatched_snapshots > 0 && (
                    <div
                        role="alert"
                        className="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-200/10 dark:bg-red-700/10 dark:text-red-100"
                    >
                        <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                        <p>
                            {account.mismatched_snapshots === 1
                                ? 'One snapshot disagrees'
                                : `${account.mismatched_snapshots} snapshots disagree`}{' '}
                            with the balance computed from the snapshot before
                            it and the transactions in between. See the
                            difference below.
                        </p>
                    </div>
                )}

                <section className="space-y-4">
                    <Heading
                        variant="small"
                        title="Balance snapshots"
                        description="The closing balance on a day. Imports add one automatically when the file has a balance column."
                    />
                    <Form
                        {...BalanceSnapshotController.store.form(account.id)}
                        resetOnSuccess
                        options={{ preserveScroll: true }}
                        className="flex flex-wrap items-end gap-3"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="snapshot-date">Date</Label>
                                    <Input
                                        id="snapshot-date"
                                        type="date"
                                        name="date"
                                        defaultValue={today}
                                        required
                                    />
                                    <InputError message={errors.date} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="snapshot-balance">
                                        Balance (CHF)
                                    </Label>
                                    <Input
                                        id="snapshot-balance"
                                        name="balance"
                                        inputMode="decimal"
                                        placeholder="1234.50"
                                        required
                                    />
                                    <InputError message={errors.balance} />
                                </div>
                                <Button disabled={processing}>
                                    Save balance
                                </Button>
                            </>
                        )}
                    </Form>

                    {snapshots.length > 0 && (
                        <DataTable>
                            <thead>
                                <tr>
                                    <Th>Date</Th>
                                    <Th>Source</Th>
                                    <Th className="text-right">Snapshot</Th>
                                    <Th className="text-right">Computed</Th>
                                    <Th className="text-right">Difference</Th>
                                    <Th />
                                </tr>
                            </thead>
                            <tbody>
                                {snapshots.map((snapshot) => (
                                    <tr key={snapshot.id}>
                                        <Td>{snapshot.date}</Td>
                                        <Td className="text-muted-foreground">
                                            {snapshot.source}
                                        </Td>
                                        <Td className="text-right">
                                            <Money value={snapshot.balance} />
                                        </Td>
                                        <Td className="text-right text-muted-foreground">
                                            {snapshot.expected === null ? (
                                                'opening'
                                            ) : (
                                                <Money
                                                    value={snapshot.expected}
                                                />
                                            )}
                                        </Td>
                                        <Td className="text-right">
                                            {snapshot.difference === null ? (
                                                '—'
                                            ) : snapshot.difference === 0 ? (
                                                <span className="text-muted-foreground">
                                                    matches
                                                </span>
                                            ) : (
                                                <Money
                                                    value={snapshot.difference}
                                                    className="font-medium text-red-600 dark:text-red-400"
                                                />
                                            )}
                                        </Td>
                                        <Td className="text-right">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    router.visit(
                                                        destroySnapshot(
                                                            snapshot.id,
                                                        ),
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Delete
                                            </Button>
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </DataTable>
                    )}
                </section>

                {account.importable && (
                    <section className="space-y-4">
                        <Heading
                            variant="small"
                            title="Import mappings"
                            description="Saved mappings that import bank files into this account."
                        />
                        {mappings.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                None yet. Upload a file on the Import page.
                            </p>
                        ) : (
                            <ul className="space-y-1 text-sm">
                                {mappings.map((mapping) => (
                                    <li key={mapping.id}>
                                        <Link
                                            href={editMapping(mapping.id)}
                                            className="hover:underline"
                                        >
                                            {mapping.name}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                )}

                {account.importable && (
                    <section className="space-y-4">
                        <div className="flex items-end justify-between">
                            <Heading
                                variant="small"
                                title="Recent transactions"
                            />
                            <Link
                                href={transactionsIndex({
                                    query: { account: account.id },
                                })}
                                className="text-sm hover:underline"
                            >
                                All transactions
                            </Link>
                        </div>
                        <DataTable>
                            <tbody>
                                {recentTransactions.map((transaction) => (
                                    <tr key={transaction.id}>
                                        <Td className="whitespace-nowrap text-muted-foreground">
                                            {transaction.date}
                                        </Td>
                                        <Td>
                                            {transaction.payee ??
                                                transaction.description}{' '}
                                            <StatusBadge
                                                status={transaction.status}
                                            />
                                        </Td>
                                        <Td className="text-muted-foreground">
                                            {transaction.category}
                                        </Td>
                                        <Td className="text-right">
                                            <Money
                                                value={transaction.amount}
                                                signed
                                            />
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

ShowAccount.layout = (props: { account: AccountSummary }) => ({
    breadcrumbs: [
        { title: 'Accounts', href: index() },
        { title: props.account.name, href: show(props.account.id) },
    ],
});
