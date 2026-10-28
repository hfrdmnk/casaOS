import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ChevronLeft,
    ChevronRight,
    FileUp,
    WalletCards,
} from 'lucide-react';
import { Money } from '@/components/finance/money';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { dashboard } from '@/routes';
import {
    index as accountsIndex,
    show as accountShow,
} from '@/routes/finance/accounts';
import { index as importPage } from '@/routes/finance/import';
import { index as subscriptionsIndex } from '@/routes/finance/subscriptions';
import { index as transactionsIndex } from '@/routes/finance/transactions';
import { index as transfersIndex } from '@/routes/finance/transfers';
import type { DashboardSummary } from '@/types/finance';

function shiftMonth(month: string, by: number): string {
    const [year, number] = month.split('-').map(Number);
    const date = new Date(Date.UTC(year, number - 1 + by, 1));

    return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`;
}

function monthLabel(month: string): string {
    const [year, number] = month.split('-').map(Number);

    return new Date(Date.UTC(year, number - 1, 1)).toLocaleDateString('en-CH', {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    });
}

export default function Dashboard({ summary }: { summary: DashboardSummary }) {
    return (
        <>
            <Head title="Dashboard" />
            <div className="space-y-6 p-4">
                {summary.has_accounts ? (
                    <Overview summary={summary} />
                ) : (
                    <EmptyState />
                )}
            </div>
        </>
    );
}

function EmptyState() {
    return (
        <Card data-test="dashboard-empty" className="mx-auto max-w-xl">
            <CardHeader>
                <CardTitle>Welcome to casaOS</CardTitle>
                <CardDescription>
                    Your net worth, spending and subscriptions show up here once
                    you have accounts. Import a bank CSV to create transactions,
                    or add an account and enter its balance.
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-wrap gap-2">
                <Button asChild>
                    <Link href={importPage()}>
                        <FileUp /> Import a CSV
                    </Link>
                </Button>
                <Button variant="outline" asChild>
                    <Link href={accountsIndex()}>
                        <WalletCards /> Add an account
                    </Link>
                </Button>
            </CardContent>
        </Card>
    );
}

function Overview({ summary }: { summary: DashboardSummary }) {
    const { net_worth, account_groups, spending, subscriptions, attention } =
        summary;
    const largest = Math.max(1, ...spending.categories.map((c) => c.amount));
    const attentionItems = [
        {
            label: 'uncategorized transactions',
            count: attention.uncategorized,
            href: transactionsIndex({
                query: { category: 'none', transfer: 'no' },
            }),
            test: 'attention-uncategorized',
        },
        {
            label: 'transfer suggestions',
            count: attention.transfer_suggestions,
            href: transfersIndex(),
            test: 'attention-transfers',
        },
        {
            label: 'subscription suggestions',
            count: attention.subscription_suggestions,
            href: subscriptionsIndex(),
            test: 'attention-subscriptions',
        },
    ];

    return (
        <>
            <div className="grid gap-4 lg:grid-cols-3">
                <Card data-test="net-worth">
                    <CardHeader>
                        <CardDescription>Net worth</CardDescription>
                        <CardTitle className="text-3xl">
                            <Money value={net_worth.total} />
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-1 text-sm text-muted-foreground">
                        <p>
                            Today, across {net_worth.accounts}{' '}
                            {net_worth.accounts === 1 ? 'account' : 'accounts'}
                        </p>
                        {net_worth.stale_since && (
                            <p
                                className="flex items-center gap-1 text-amber-700 dark:text-amber-400"
                                data-test="stale-since"
                            >
                                <AlertTriangle className="size-4" />
                                Includes a balance last updated{' '}
                                <span className="whitespace-nowrap">
                                    {net_worth.stale_since}
                                </span>
                            </p>
                        )}
                    </CardContent>
                </Card>

                <Card data-test="recurring">
                    <CardHeader>
                        <CardDescription>
                            Subscriptions, next 30 days
                        </CardDescription>
                        <CardTitle className="text-3xl">
                            <Money value={subscriptions.monthly_total} />
                            <span className="text-base font-normal text-muted-foreground">
                                {' '}
                                / month
                            </span>
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2 text-sm">
                        {subscriptions.upcoming.length === 0 ? (
                            <p className="text-muted-foreground">
                                {subscriptions.active === 0
                                    ? 'No subscriptions yet.'
                                    : 'Nothing due in the next 30 days.'}
                            </p>
                        ) : (
                            <ul className="space-y-1" data-test="upcoming">
                                {subscriptions.upcoming.map((upcoming) => (
                                    <li
                                        key={upcoming.id}
                                        className="flex justify-between gap-2"
                                    >
                                        <span>
                                            <span className="text-muted-foreground tabular-nums">
                                                {upcoming.next_date}
                                            </span>{' '}
                                            {upcoming.name}
                                            {upcoming.overdue && (
                                                <Badge
                                                    variant="outline"
                                                    className="ml-1"
                                                >
                                                    overdue
                                                </Badge>
                                            )}
                                        </span>
                                        <Money value={upcoming.amount} />
                                    </li>
                                ))}
                            </ul>
                        )}
                        <Link
                            href={subscriptionsIndex()}
                            className="text-xs text-muted-foreground underline"
                        >
                            All subscriptions ({subscriptions.active} active)
                        </Link>
                    </CardContent>
                </Card>

                <Card data-test="needs-attention">
                    <CardHeader>
                        <CardDescription>Needs attention</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ul className="space-y-2 text-sm">
                            {attentionItems.map((item) => (
                                <li key={item.test} data-test={item.test}>
                                    {item.count === 0 ? (
                                        <span className="text-muted-foreground">
                                            No {item.label}
                                        </span>
                                    ) : (
                                        <Link
                                            href={item.href}
                                            className="font-medium underline"
                                        >
                                            {item.count} {item.label}
                                        </Link>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <Card data-test="spending">
                    <CardHeader className="flex flex-row items-center justify-between gap-2">
                        <div className="space-y-1.5">
                            <CardDescription>Spending</CardDescription>
                            <CardTitle>
                                {monthLabel(spending.month)} ·{' '}
                                <Money value={spending.total} />
                            </CardTitle>
                        </div>
                        <div className="flex gap-1">
                            <Button
                                size="icon"
                                variant="outline"
                                asChild
                                aria-label="Previous month"
                            >
                                <Link
                                    href={dashboard({
                                        query: {
                                            month: shiftMonth(
                                                spending.month,
                                                -1,
                                            ),
                                        },
                                    })}
                                    preserveScroll
                                >
                                    <ChevronLeft />
                                </Link>
                            </Button>
                            <Button
                                size="icon"
                                variant="outline"
                                asChild
                                aria-label="Next month"
                            >
                                <Link
                                    href={dashboard({
                                        query: {
                                            month: shiftMonth(
                                                spending.month,
                                                1,
                                            ),
                                        },
                                    })}
                                    preserveScroll
                                >
                                    <ChevronRight />
                                </Link>
                            </Button>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {spending.categories.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No spending this month.
                            </p>
                        ) : (
                            <ul className="space-y-3 text-sm">
                                {spending.categories.map((row) => (
                                    <li
                                        key={row.category_id ?? 'none'}
                                        data-test="spending-category"
                                    >
                                        <div className="flex justify-between gap-2">
                                            <Link
                                                href={transactionsIndex({
                                                    query: {
                                                        category:
                                                            row.category_id ??
                                                            'none',
                                                        transfer: 'no',
                                                        from: spending.from,
                                                        to: spending.to,
                                                    },
                                                })}
                                                className="hover:underline"
                                            >
                                                {row.category}{' '}
                                                <span className="text-xs text-muted-foreground">
                                                    {row.count}
                                                </span>
                                            </Link>
                                            <Money value={row.amount} />
                                        </div>
                                        <div className="mt-1 h-1.5 rounded-full bg-muted">
                                            <div
                                                className={
                                                    row.category_id === null
                                                        ? 'h-full rounded-full bg-muted-foreground/40'
                                                        : 'h-full rounded-full bg-primary'
                                                }
                                                style={{
                                                    width: `${(row.amount / largest) * 100}%`,
                                                }}
                                            />
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <p className="mt-4 text-xs text-muted-foreground">
                            Completed charges; transfers between your accounts
                            are not spending.
                        </p>
                    </CardContent>
                </Card>

                <Card data-test="balances">
                    <CardHeader>
                        <CardDescription>Balances</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4 text-sm">
                        {account_groups.map((group) => (
                            <div key={group.type} className="space-y-1">
                                <div className="flex justify-between font-medium">
                                    <span>{group.label}</span>
                                    <Money value={group.total} />
                                </div>
                                <ul className="space-y-1">
                                    {group.accounts.map((account) => (
                                        <li
                                            key={account.id}
                                            className="flex justify-between gap-2 pl-3 text-muted-foreground"
                                            data-test="account-balance"
                                        >
                                            <span>
                                                <Link
                                                    href={accountShow(
                                                        account.id,
                                                    )}
                                                    className="hover:underline"
                                                >
                                                    {account.name}
                                                </Link>
                                                {account.mismatched_snapshots >
                                                    0 && (
                                                    <Badge
                                                        variant="outline"
                                                        className="ml-2 border-amber-500 text-amber-700 dark:text-amber-400"
                                                        data-test="reconciliation-flag"
                                                    >
                                                        balance differs
                                                    </Badge>
                                                )}
                                            </span>
                                            <Money value={account.balance} />
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
