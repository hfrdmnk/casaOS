import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeftRight, WandSparkles } from 'lucide-react';
import type { FormEvent } from 'react';
import RuleController from '@/actions/App/Http/Controllers/Finance/RuleController';
import TransactionController from '@/actions/App/Http/Controllers/Finance/TransactionController';
import TransferController from '@/actions/App/Http/Controllers/Finance/TransferController';
import { DataTable, Td, Th } from '@/components/finance/data-table';
import { Money } from '@/components/finance/money';
import { NativeSelect } from '@/components/finance/native-select';
import { StatusBadge } from '@/components/finance/status-badge';
import {
    SubscriptionActions,
    SubscriptionMarker,
} from '@/components/finance/subscription';
import { TransferActions, TransferMarker } from '@/components/finance/transfer';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/finance/transactions';
import { index as transfers } from '@/routes/finance/transfers';
import type {
    IntervalOption,
    Option,
    Paginated,
    TransactionRow,
} from '@/types/finance';

type Filters = {
    account: string;
    category: string;
    from: string;
    to: string;
    search: string;
    transfer: string;
    subscription: string;
};

export default function Transactions({
    transactions,
    filters,
    suggestions_count,
    accounts,
    categories,
    payees,
    subscriptions,
    intervals,
}: {
    transactions: Paginated<TransactionRow>;
    filters: Filters;
    suggestions_count: number;
    accounts: Option[];
    categories: Option[];
    payees: Option[];
    subscriptions: Option[];
    intervals: IntervalOption[];
}) {
    function applyFilters(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        const query = Object.fromEntries(
            [...data.entries()].filter(([, value]) => value !== ''),
        );

        router.get(index.url(), query, { preserveState: true });
    }

    function assign(
        transaction: TransactionRow,
        field: 'category_id' | 'payee_id',
        value: string,
    ) {
        router.patch(
            TransactionController.update.url(transaction.id),
            { [field]: value === '' ? null : Number(value) },
            { preserveScroll: true, preserveState: true },
        );
    }

    return (
        <>
            <Head title="Transactions" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Transactions"
                        description="Every imported transaction, newest first. Transfers are neither income nor spending."
                    />
                    <div className="flex items-center gap-2">
                        {suggestions_count > 0 && (
                            <Button variant="link" asChild>
                                <Link
                                    href={transfers()}
                                    data-test="suggestions-link"
                                >
                                    {suggestions_count} transfer{' '}
                                    {suggestions_count === 1
                                        ? 'suggestion'
                                        : 'suggestions'}
                                </Link>
                            </Button>
                        )}
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    TransferController.find.url(),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <ArrowLeftRight /> Find transfers
                        </Button>
                    </div>
                </div>

                <form
                    onSubmit={applyFilters}
                    className="grid gap-3 sm:grid-cols-2 lg:grid-cols-8"
                    aria-label="Filters"
                >
                    <div className="grid gap-1 lg:col-span-2">
                        <Label htmlFor="filter-search">Search</Label>
                        <Input
                            id="filter-search"
                            name="search"
                            defaultValue={filters.search}
                            placeholder="Description or payee contains…"
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="filter-account">Account</Label>
                        <NativeSelect
                            id="filter-account"
                            name="account"
                            defaultValue={filters.account}
                        >
                            <option value="">All accounts</option>
                            {accounts.map((account) => (
                                <option key={account.id} value={account.id}>
                                    {account.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="filter-category">Category</Label>
                        <NativeSelect
                            id="filter-category"
                            name="category"
                            defaultValue={filters.category}
                        >
                            <option value="">All categories</option>
                            <option value="none">Uncategorized</option>
                            {categories.map((category) => (
                                <option key={category.id} value={category.id}>
                                    {category.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="filter-transfer">Transfers</Label>
                        <NativeSelect
                            id="filter-transfer"
                            name="transfer"
                            defaultValue={filters.transfer}
                        >
                            <option value="">All transactions</option>
                            <option value="yes">Transfers</option>
                            <option value="no">Not transfers</option>
                            <option value="suggested">
                                Suggested transfers
                            </option>
                        </NativeSelect>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="filter-subscription">
                            Subscriptions
                        </Label>
                        <NativeSelect
                            id="filter-subscription"
                            name="subscription"
                            defaultValue={filters.subscription}
                        >
                            <option value="">All transactions</option>
                            <option value="any">Subscription payments</option>
                            <option value="none">Not subscriptions</option>
                            {subscriptions.map((subscription) => (
                                <option
                                    key={subscription.id}
                                    value={subscription.id}
                                >
                                    {subscription.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="filter-from">From</Label>
                        <Input
                            id="filter-from"
                            type="date"
                            name="from"
                            defaultValue={filters.from}
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="filter-to">To</Label>
                        <Input
                            id="filter-to"
                            type="date"
                            name="to"
                            defaultValue={filters.to}
                        />
                    </div>
                    <div className="flex gap-2 lg:col-span-8">
                        <Button type="submit">Apply filters</Button>
                        <Button variant="ghost" asChild>
                            <Link href={index()}>Clear</Link>
                        </Button>
                    </div>
                </form>

                <p
                    className="text-sm text-muted-foreground"
                    data-test="result-count"
                >
                    {transactions.total === 0
                        ? 'No transactions match.'
                        : `${transactions.from}–${transactions.to} of ${transactions.total}`}
                </p>

                {transactions.total > 0 && (
                    <DataTable>
                        <thead>
                            <tr>
                                <Th>Date</Th>
                                <Th>Description</Th>
                                <Th>Account</Th>
                                <Th>Payee</Th>
                                <Th>Category</Th>
                                <Th className="text-right">Fee</Th>
                                <Th className="text-right">Amount</Th>
                                <Th />
                            </tr>
                        </thead>
                        <tbody>
                            {transactions.data.map((transaction) => (
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
                                        {transaction.payee && (
                                            <div className="text-xs text-muted-foreground">
                                                {transaction.description}
                                            </div>
                                        )}
                                        <SubscriptionMarker
                                            transaction={transaction}
                                        />
                                    </Td>
                                    <Td className="whitespace-nowrap text-muted-foreground">
                                        {transaction.account}
                                    </Td>
                                    <Td>
                                        <NativeSelect
                                            aria-label={`Payee for ${transaction.description}`}
                                            className="h-8 min-w-32"
                                            value={transaction.payee_id ?? ''}
                                            onChange={(event) =>
                                                assign(
                                                    transaction,
                                                    'payee_id',
                                                    event.target.value,
                                                )
                                            }
                                        >
                                            <option value="">No payee</option>
                                            {payees.map((payee) => (
                                                <option
                                                    key={payee.id}
                                                    value={payee.id}
                                                >
                                                    {payee.name}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                        <SourceNote
                                            source={transaction.payee_source}
                                        />
                                    </Td>
                                    <Td>
                                        {transaction.is_transfer ? (
                                            <TransferMarker
                                                transaction={transaction}
                                            />
                                        ) : (
                                            <>
                                                <NativeSelect
                                                    aria-label={`Category for ${transaction.description}`}
                                                    className="h-8 min-w-40"
                                                    value={
                                                        transaction.category_id ??
                                                        ''
                                                    }
                                                    onChange={(event) =>
                                                        assign(
                                                            transaction,
                                                            'category_id',
                                                            event.target.value,
                                                        )
                                                    }
                                                >
                                                    <option value="">
                                                        Uncategorized
                                                    </option>
                                                    {categories.map(
                                                        (category) => (
                                                            <option
                                                                key={
                                                                    category.id
                                                                }
                                                                value={
                                                                    category.id
                                                                }
                                                            >
                                                                {category.name}
                                                            </option>
                                                        ),
                                                    )}
                                                </NativeSelect>
                                                <SourceNote
                                                    source={
                                                        transaction.category_source
                                                    }
                                                />
                                            </>
                                        )}
                                        {transaction.suggested_transfer && (
                                            <Link
                                                href={transfers()}
                                                className="mt-0.5 block text-xs text-amber-700 underline dark:text-amber-400"
                                            >
                                                suggested transfer
                                            </Link>
                                        )}
                                    </Td>
                                    <Td className="text-right text-muted-foreground">
                                        {transaction.fee !== 0 && (
                                            <Money value={transaction.fee} />
                                        )}
                                    </Td>
                                    <Td className="text-right whitespace-nowrap">
                                        <Money
                                            value={transaction.amount}
                                            signed
                                        />
                                    </Td>
                                    <Td className="text-right whitespace-nowrap">
                                        <SubscriptionActions
                                            transaction={transaction}
                                            subscriptions={subscriptions}
                                            intervals={intervals}
                                        />
                                        <TransferActions
                                            transaction={transaction}
                                        />
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            className="size-8"
                                            title="Create rule from this transaction"
                                            asChild
                                        >
                                            <Link
                                                href={RuleController.create({
                                                    query: {
                                                        transaction:
                                                            transaction.id,
                                                    },
                                                })}
                                                aria-label={`Create rule from ${transaction.description}`}
                                            >
                                                <WandSparkles />
                                            </Link>
                                        </Button>
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </DataTable>
                )}

                {transactions.last_page > 1 && (
                    <nav
                        className="flex items-center justify-between"
                        aria-label="Pagination"
                    >
                        <Button
                            variant="outline"
                            disabled={transactions.prev_page_url === null}
                            asChild={transactions.prev_page_url !== null}
                        >
                            {transactions.prev_page_url === null ? (
                                <span>Previous</span>
                            ) : (
                                <Link
                                    href={transactions.prev_page_url}
                                    preserveScroll
                                >
                                    Previous
                                </Link>
                            )}
                        </Button>
                        <span className="text-sm text-muted-foreground">
                            Page {transactions.current_page} of{' '}
                            {transactions.last_page}
                        </span>
                        <Button
                            variant="outline"
                            disabled={transactions.next_page_url === null}
                            asChild={transactions.next_page_url !== null}
                        >
                            {transactions.next_page_url === null ? (
                                <span>Next</span>
                            ) : (
                                <Link
                                    href={transactions.next_page_url}
                                    preserveScroll
                                >
                                    Next
                                </Link>
                            )}
                        </Button>
                    </nav>
                )}
            </div>
        </>
    );
}

function SourceNote({ source }: { source: TransactionRow['category_source'] }) {
    if (source === null) {
        return null;
    }

    return (
        <div className="mt-0.5 text-xs text-muted-foreground">
            {source === 'rule' ? 'set by rule' : 'set by hand'}
        </div>
    );
}

Transactions.layout = {
    breadcrumbs: [{ title: 'Transactions', href: index() }],
};
