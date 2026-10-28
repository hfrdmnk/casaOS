import { Head, Link, router } from '@inertiajs/react';
import type { FormEvent } from 'react';
import TransactionController from '@/actions/App/Http/Controllers/Finance/TransactionController';
import { DataTable, Td, Th } from '@/components/finance/data-table';
import { Money } from '@/components/finance/money';
import { NativeSelect } from '@/components/finance/native-select';
import { StatusBadge } from '@/components/finance/status-badge';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/finance/transactions';
import type { Option, Paginated, TransactionRow } from '@/types/finance';

type Filters = {
    account: string;
    category: string;
    from: string;
    to: string;
    search: string;
};

export default function Transactions({
    transactions,
    filters,
    accounts,
    categories,
}: {
    transactions: Paginated<TransactionRow>;
    filters: Filters;
    accounts: Option[];
    categories: Option[];
}) {
    function applyFilters(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        const query = Object.fromEntries(
            [...data.entries()].filter(([, value]) => value !== ''),
        );

        router.get(index.url(), query, { preserveState: true });
    }

    function categorize(transaction: TransactionRow, value: string) {
        router.patch(
            TransactionController.update.url(transaction.id),
            { category_id: value === '' ? null : Number(value) },
            { preserveScroll: true, preserveState: true },
        );
    }

    return (
        <>
            <Head title="Transactions" />
            <div className="space-y-6 p-4">
                <Heading
                    title="Transactions"
                    description="Every imported transaction, newest first"
                />

                <form
                    onSubmit={applyFilters}
                    className="grid gap-3 sm:grid-cols-2 lg:grid-cols-6"
                    aria-label="Filters"
                >
                    <div className="grid gap-1 lg:col-span-2">
                        <Label htmlFor="filter-search">Search</Label>
                        <Input
                            id="filter-search"
                            name="search"
                            defaultValue={filters.search}
                            placeholder="Description contains…"
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
                    <div className="flex gap-2 lg:col-span-6">
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
                                <Th>Category</Th>
                                <Th className="text-right">Fee</Th>
                                <Th className="text-right">Amount</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {transactions.data.map((transaction) => (
                                <tr key={transaction.id}>
                                    <Td className="whitespace-nowrap text-muted-foreground">
                                        {transaction.date}
                                    </Td>
                                    <Td>
                                        {transaction.description}{' '}
                                        <StatusBadge
                                            status={transaction.status}
                                        />
                                    </Td>
                                    <Td className="whitespace-nowrap text-muted-foreground">
                                        {transaction.account}
                                    </Td>
                                    <Td>
                                        <NativeSelect
                                            aria-label={`Category for ${transaction.description}`}
                                            className="h-8 min-w-40"
                                            value={
                                                transaction.category_id ?? ''
                                            }
                                            onChange={(event) =>
                                                categorize(
                                                    transaction,
                                                    event.target.value,
                                                )
                                            }
                                        >
                                            <option value="">
                                                Uncategorized
                                            </option>
                                            {categories.map((category) => (
                                                <option
                                                    key={category.id}
                                                    value={category.id}
                                                >
                                                    {category.name}
                                                </option>
                                            ))}
                                        </NativeSelect>
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

Transactions.layout = {
    breadcrumbs: [{ title: 'Transactions', href: index() }],
};
