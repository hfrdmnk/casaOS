import { Head, Link } from '@inertiajs/react';
import { ArrowRight, ChevronLeft, ChevronRight, Plus } from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';
import { Fragment, useState } from 'react';
import { Button } from '@/components/ui/button';
import { formatAmount, formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
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

/**
 * Categories have no stored colour, so each gets a hue from its id. All hues
 * share the --category-l and --category-c tokens, so no category looks louder
 * than another.
 */
const categoryHues = [255, 145, 300, 70, 345, 215, 25, 110, 180];
const accountGroupHues = [185, 265, 300, 70];

function hue(degrees: number): CSSProperties {
    return {
        backgroundColor: `oklch(var(--category-l) var(--category-c) ${degrees})`,
    };
}

function categorySwatch(id: number | null): CSSProperties {
    return id === null
        ? { backgroundColor: 'var(--uncategorized)' }
        : hue(categoryHues[(id - 1) % categoryHues.length]);
}

function shiftMonth(month: string, by: number): string {
    const [year, number] = month.split('-').map(Number);
    const date = new Date(Date.UTC(year, number - 1 + by, 1));

    return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`;
}

function formatMonth(
    month: string,
    options: Intl.DateTimeFormatOptions,
): string {
    const [year, number] = month.split('-').map(Number);

    return new Date(Date.UTC(year, number - 1, 1)).toLocaleDateString('en-GB', {
        ...options,
        timeZone: 'UTC',
    });
}

function formatDate(
    date: string,
    options: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'short' },
): string {
    return new Date(`${date}T00:00:00Z`).toLocaleDateString('en-GB', {
        ...options,
        timeZone: 'UTC',
    });
}

function share(amount: number, total: number): string {
    return `${total === 0 ? 0 : Math.round((amount / total) * 100)}%`;
}

export default function Dashboard({ summary }: { summary: DashboardSummary }) {
    return (
        <>
            <Head title="Dashboard" />
            <h1 className="sr-only">Overview</h1>
            <p className="text-[13px] text-faint">
                {formatDate(summary.today, {
                    weekday: 'long',
                    day: 'numeric',
                    month: 'long',
                })}
            </p>
            {summary.has_accounts ? (
                <Overview summary={summary} />
            ) : (
                <EmptyState />
            )}
        </>
    );
}

function Lede({
    children,
    ...props
}: {
    children: ReactNode;
    'data-test'?: string;
}) {
    return (
        <p
            className="mt-3.5 text-2xl leading-[1.42] tracking-[-0.018em] text-pretty text-faint [&_a]:text-link [&_a]:underline [&_a]:decoration-link/35 [&_a]:underline-offset-[0.2em] [&_a]:transition-[text-decoration-color] [&_a]:duration-150 [&_a]:hover:decoration-current [&_strong]:font-normal [&_strong]:text-foreground [&_strong]:tabular-nums"
            {...props}
        >
            {children}
        </p>
    );
}

function EmptyState() {
    return (
        <div data-test="dashboard-empty">
            <Lede>
                <strong>Welcome to casaOS.</strong> Your net worth, spending and
                subscriptions show up here once you have accounts. Import a bank
                CSV to create transactions, or add an account and enter its
                balance.
            </Lede>
            <div className="mt-8 flex flex-wrap gap-2">
                <Button asChild>
                    <Link href={importPage()}>Import a CSV</Link>
                </Button>
                <Button variant="outline" asChild>
                    <Link href={accountsIndex()}>Add an account</Link>
                </Button>
            </div>
        </div>
    );
}

function Section({
    title,
    aside,
    children,
    ...props
}: {
    title: string;
    aside?: ReactNode;
    children: ReactNode;
    'data-test'?: string;
}) {
    return (
        <section className="mt-22" {...props}>
            <div className="mb-4.5 flex min-h-7 items-center justify-between gap-3">
                <h2 className="text-[13px] font-medium">{title}</h2>
                {aside}
            </div>
            {children}
        </section>
    );
}

const asideLink =
    'inline-flex items-center gap-1 text-[13px] text-faint transition-colors duration-150 hover:text-foreground [&_svg]:size-3.5';

const rowLink =
    '-mx-2 grid h-11 items-center gap-3 border-t px-2 transition-colors duration-150 hover:bg-accent';

function Overview({ summary }: { summary: DashboardSummary }) {
    const { net_worth, account_groups, spending, subscriptions, attention } =
        summary;
    const [highlighted, setHighlighted] = useState<string | null>(null);
    const charges = spending.categories.reduce((sum, c) => sum + c.count, 0);
    const isCurrentMonth = spending.month === summary.today.slice(0, 7);
    const assetGroups = account_groups.filter((group) => group.total > 0);
    const assetTotal = assetGroups.reduce((sum, g) => sum + g.total, 0);
    const dim = (key: string) =>
        highlighted !== null && highlighted !== key && 'opacity-30';

    const attentionLinks = [
        {
            count: attention.uncategorized,
            label:
                attention.uncategorized === 1 ? 'transaction' : 'transactions',
            rest: 'need a category',
            href: transactionsIndex({
                query: { category: 'none', transfer: 'no' },
            }),
            test: 'attention-uncategorized',
        },
        {
            count: attention.transfer_suggestions,
            label:
                attention.transfer_suggestions === 1 ? 'transfer' : 'transfers',
            rest: 'wait for confirmation',
            href: transfersIndex(),
            test: 'attention-transfers',
        },
        {
            count: attention.subscription_suggestions,
            label:
                attention.subscription_suggestions === 1
                    ? 'possible subscription'
                    : 'possible subscriptions',
            rest: 'wait for review',
            href: subscriptionsIndex(),
            test: 'attention-subscriptions',
        },
    ].filter((item) => item.count > 0);

    return (
        <>
            <Lede data-test="needs-attention">
                <strong>
                    {isCurrentMonth
                        ? `${formatMonth(spending.month, { month: 'long' })} so far:`
                        : `In ${formatMonth(spending.month, { month: 'long', year: 'numeric' })}:`}
                </strong>{' '}
                you spent <strong>{formatMoney(spending.total)}</strong> across{' '}
                {charges} {charges === 1 ? 'charge' : 'charges'}.{' '}
                {attentionLinks.length === 0
                    ? 'Nothing is waiting for review.'
                    : attentionLinks.map((item, index) => (
                          <Fragment key={item.test}>
                              {index > 0 &&
                                  (index === attentionLinks.length - 1
                                      ? ' and '
                                      : ', ')}
                              <Link href={item.href} data-test={item.test}>
                                  {item.count} {item.label}
                              </Link>{' '}
                              {item.rest}
                              {index === attentionLinks.length - 1 && '.'}
                          </Fragment>
                      ))}{' '}
                Net worth stands at{' '}
                <strong>{formatMoney(net_worth.total)}</strong>.
            </Lede>

            <Section
                title="Spending"
                data-test="spending"
                aside={
                    <div className="flex items-center gap-0.5 text-[13px] text-muted-foreground">
                        <MonthLink
                            month={shiftMonth(spending.month, -1)}
                            label="Previous month"
                        >
                            <ChevronLeft />
                        </MonthLink>
                        <span className="min-w-[74px] text-center tabular-nums">
                            {formatMonth(spending.month, {
                                month: 'short',
                                year: 'numeric',
                            })}
                        </span>
                        <MonthLink
                            month={shiftMonth(spending.month, 1)}
                            label="Next month"
                        >
                            <ChevronRight />
                        </MonthLink>
                    </div>
                }
            >
                {spending.categories.length === 0 ? (
                    <div className="grid h-59 place-content-center gap-1 border-y text-center">
                        <p className="font-medium">
                            No spending in{' '}
                            {formatMonth(spending.month, {
                                month: 'long',
                                year: 'numeric',
                            })}
                        </p>
                        <p className="text-[13px] text-faint">
                            Import a statement that covers this month.
                        </p>
                        <Link
                            href={importPage()}
                            className="mt-2 text-[13px] text-link hover:underline"
                        >
                            Import CSV
                        </Link>
                    </div>
                ) : (
                    <>
                        <div
                            className="flex h-1.5 gap-[3px]"
                            aria-hidden="true"
                        >
                            {spending.categories.map((row) => (
                                <span
                                    key={row.category_id ?? 'none'}
                                    className={cn(
                                        'min-w-1.5 rounded-full transition-opacity duration-150',
                                        dim(String(row.category_id)),
                                    )}
                                    style={{
                                        flexGrow: row.amount,
                                        ...categorySwatch(row.category_id),
                                    }}
                                />
                            ))}
                        </div>
                        <ul
                            className="mt-7 border-b"
                            onPointerLeave={() => setHighlighted(null)}
                        >
                            {spending.categories.map((row) => (
                                <li
                                    key={row.category_id ?? 'none'}
                                    data-test="spending-category"
                                >
                                    <Link
                                        href={transactionsIndex({
                                            query: {
                                                category:
                                                    row.category_id ?? 'none',
                                                transfer: 'no',
                                                from: spending.from,
                                                to: spending.to,
                                            },
                                        })}
                                        onPointerEnter={() =>
                                            setHighlighted(
                                                String(row.category_id),
                                            )
                                        }
                                        className={cn(
                                            rowLink,
                                            'grid-cols-[6px_minmax(0,1fr)_40px_96px]',
                                            dim(String(row.category_id)),
                                        )}
                                    >
                                        <span
                                            className="size-1.5 rounded-full"
                                            style={categorySwatch(
                                                row.category_id,
                                            )}
                                        />
                                        <span className="truncate">
                                            {row.category}
                                            <span className="ml-2 text-[13px] text-faint tabular-nums">
                                                {row.count}
                                            </span>
                                        </span>
                                        <span className="text-right text-[13px] text-faint tabular-nums">
                                            {share(row.amount, spending.total)}
                                        </span>
                                        <span className="text-right tabular-nums">
                                            {formatAmount(row.amount)}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </>
                )}
                <div className="flex justify-between pt-3.5 text-[13px] text-faint">
                    <span>Excludes transfers between your accounts</span>
                    <span className="font-medium text-foreground tabular-nums">
                        {formatMoney(spending.total)}
                    </span>
                </div>
            </Section>

            <Section
                title="Coming up"
                data-test="recurring"
                aside={
                    <Link href={subscriptionsIndex()} className={asideLink}>
                        {subscriptions.active}{' '}
                        {subscriptions.active === 1
                            ? 'subscription'
                            : 'subscriptions'}{' '}
                        ·{' '}
                        <span className="tabular-nums">
                            {formatMoney(subscriptions.monthly_total)}
                        </span>{' '}
                        a month
                        <ArrowRight />
                    </Link>
                }
            >
                {subscriptions.upcoming.length === 0 ? (
                    <p className="border-y py-3 text-[13px] text-faint">
                        {subscriptions.active === 0
                            ? 'No subscriptions yet.'
                            : 'Nothing due in the next 30 days.'}
                    </p>
                ) : (
                    <ul className="border-b" data-test="upcoming">
                        {subscriptions.upcoming.map((upcoming) => (
                            <li
                                key={upcoming.id}
                                className="grid h-11 grid-cols-[56px_minmax(0,1fr)_auto] items-center gap-3 border-t"
                            >
                                <span className="text-[13px] text-faint tabular-nums">
                                    {formatDate(upcoming.next_date)}
                                </span>
                                <span className="truncate">
                                    {upcoming.name}
                                    {upcoming.overdue && (
                                        <span className="ml-2 text-xs text-warning">
                                            Overdue
                                        </span>
                                    )}
                                </span>
                                <span className="tabular-nums">
                                    {formatAmount(upcoming.amount)}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Section>

            <Section
                title="Net worth"
                data-test="net-worth"
                aside={
                    <Link href={accountsIndex()} className={asideLink}>
                        <Plus />
                        Add account
                    </Link>
                }
            >
                <p className="text-[44px] leading-none font-medium tracking-[-0.035em] tabular-nums">
                    {formatAmount(net_worth.total)}
                    <span className="ml-2 text-[15px] font-normal tracking-normal text-faint">
                        CHF
                    </span>
                </p>
                {net_worth.stale_since && (
                    <p
                        className="mt-3 text-[13px] text-warning"
                        data-test="stale-since"
                    >
                        Includes a balance last updated{' '}
                        {formatDate(net_worth.stale_since, {
                            day: 'numeric',
                            month: 'short',
                            year: 'numeric',
                        })}
                    </p>
                )}
                {assetGroups.length > 1 && (
                    <>
                        <div
                            className="mt-7 flex h-1 gap-[3px]"
                            aria-hidden="true"
                        >
                            {assetGroups.map((group, index) => (
                                <span
                                    key={group.type}
                                    className="min-w-1 rounded-full"
                                    style={{
                                        flexGrow: group.total,
                                        ...hue(
                                            accountGroupHues[
                                                index % accountGroupHues.length
                                            ],
                                        ),
                                    }}
                                />
                            ))}
                        </div>
                        <ul className="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-[13px] text-muted-foreground">
                            {assetGroups.map((group, index) => (
                                <li
                                    key={group.type}
                                    className="flex items-center gap-[7px]"
                                >
                                    <span
                                        className="size-1.5 rounded-full"
                                        style={hue(
                                            accountGroupHues[
                                                index % accountGroupHues.length
                                            ],
                                        )}
                                    />
                                    {group.label}
                                    <span className="tabular-nums">
                                        {share(group.total, assetTotal)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </>
                )}
                <div className="mt-7" data-test="balances">
                    {account_groups.map((group) => (
                        <div key={group.type}>
                            <div className="flex justify-between pt-5 pb-2 text-xs text-faint first:pt-0">
                                <span>{group.label}</span>
                                <span className="tabular-nums">
                                    {formatMoney(group.total)}
                                </span>
                            </div>
                            <ul className="border-b">
                                {group.accounts.map((account) => (
                                    <li
                                        key={account.id}
                                        data-test="account-balance"
                                    >
                                        <Link
                                            href={accountShow(account.id)}
                                            className={cn(
                                                rowLink,
                                                'grid-cols-[minmax(0,1fr)_auto]',
                                            )}
                                        >
                                            <span className="flex min-w-0 items-center gap-2">
                                                <span className="truncate">
                                                    {account.name}
                                                </span>
                                                {account.mismatched_snapshots >
                                                    0 && (
                                                    <span
                                                        className="text-xs whitespace-nowrap text-warning"
                                                        data-test="reconciliation-flag"
                                                    >
                                                        Balance differs
                                                    </span>
                                                )}
                                            </span>
                                            <span
                                                className={cn(
                                                    'tabular-nums',
                                                    account.balance < 0 &&
                                                        'text-muted-foreground',
                                                )}
                                            >
                                                {formatAmount(account.balance)}
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </div>
            </Section>
        </>
    );
}

function MonthLink({
    month,
    label,
    children,
}: {
    month: string;
    label: string;
    children: ReactNode;
}) {
    return (
        <Link
            href={dashboard({ query: { month } })}
            preserveScroll
            aria-label={label}
            className="grid size-7 place-items-center rounded-md text-faint transition-colors duration-150 hover:bg-accent hover:text-foreground [&_svg]:size-3.5"
        >
            {children}
        </Link>
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
