import { Head, Link, router } from '@inertiajs/react';
import { Check, Repeat, ScanSearch, X } from 'lucide-react';
import { useState } from 'react';
import SubscriptionController from '@/actions/App/Http/Controllers/Finance/SubscriptionController';
import { DataTable, Td, Th } from '@/components/finance/data-table';
import { Money } from '@/components/finance/money';
import { SubscriptionDialog } from '@/components/finance/subscription';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/finance/subscriptions';
import { index as transactions } from '@/routes/finance/transactions';
import type {
    IntervalOption,
    Option,
    Subscription,
    SubscriptionSuggestion,
} from '@/types/finance';

const visit = { preserveScroll: true } as const;

export default function Subscriptions({
    active,
    cancelled,
    suggestions,
    monthly_total,
    intervals,
    accounts,
    payees,
}: {
    active: Subscription[];
    cancelled: Subscription[];
    suggestions: SubscriptionSuggestion[];
    monthly_total: number;
    intervals: IntervalOption[];
    accounts: Option[];
    payees: Option[];
}) {
    const [editing, setEditing] = useState<Subscription | null>(null);
    const intervalLabel = (value: string) =>
        intervals.find((interval) => interval.value === value)?.label ?? value;

    return (
        <>
            <Head title="Subscriptions" />
            <div className="space-y-8 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Subscriptions"
                        description="Recurring payments. New charges that match are linked on import and move the next date."
                    />
                    <Button
                        variant="outline"
                        onClick={() =>
                            router.post(
                                SubscriptionController.detect.url(),
                                {},
                                visit,
                            )
                        }
                    >
                        <ScanSearch /> Detect subscriptions
                    </Button>
                </div>

                <section className="space-y-3" aria-labelledby="active-heading">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 id="active-heading" className="font-semibold">
                            Active
                        </h2>
                        <p
                            className="text-sm text-muted-foreground"
                            data-test="monthly-total"
                        >
                            {active.length}{' '}
                            {active.length === 1
                                ? 'subscription'
                                : 'subscriptions'}{' '}
                            · <Money value={monthly_total} /> per month
                        </p>
                    </div>
                    {active.length === 0 ? (
                        <p className="rounded-xl border p-6 text-sm text-muted-foreground">
                            No active subscriptions. Confirm a suggestion below,
                            or use “Mark as subscription” on a charge in{' '}
                            <Link href={transactions()} className="underline">
                                Transactions
                            </Link>
                            .
                        </p>
                    ) : (
                        <SubscriptionTable
                            subscriptions={active}
                            intervalLabel={intervalLabel}
                            onEdit={setEditing}
                        />
                    )}
                </section>

                <section
                    className="space-y-3"
                    aria-labelledby="suggestions-heading"
                >
                    <h2 id="suggestions-heading" className="font-semibold">
                        Suggestions
                    </h2>
                    {suggestions.length === 0 ? (
                        <p className="rounded-xl border p-6 text-sm text-muted-foreground">
                            No suggestions. Detection needs at least 3 charges
                            of a similar amount at a regular interval.
                        </p>
                    ) : (
                        <DataTable>
                            <thead>
                                <tr>
                                    <Th>Suggested</Th>
                                    <Th>Charges</Th>
                                    <Th>Interval</Th>
                                    <Th>Next</Th>
                                    <Th className="text-right">Amount</Th>
                                    <Th />
                                </tr>
                            </thead>
                            <tbody>
                                {suggestions.map((suggestion) => (
                                    <tr
                                        key={suggestion.id}
                                        data-test="subscription-suggestion"
                                    >
                                        <Td>
                                            <div className="font-medium">
                                                {suggestion.name}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {suggestion.match}
                                                {suggestion.account &&
                                                    ` · ${suggestion.account}`}
                                            </div>
                                        </Td>
                                        <Td className="text-xs text-muted-foreground">
                                            {suggestion.charges.map(
                                                (charge) => (
                                                    <div
                                                        key={charge.id}
                                                        className="whitespace-nowrap"
                                                    >
                                                        {charge.date}{' '}
                                                        <Money
                                                            value={
                                                                charge.amount
                                                            }
                                                        />
                                                    </div>
                                                ),
                                            )}
                                        </Td>
                                        <Td>
                                            {intervalLabel(suggestion.interval)}
                                        </Td>
                                        <Td className="whitespace-nowrap">
                                            {suggestion.next_date}
                                        </Td>
                                        <Td className="text-right whitespace-nowrap">
                                            <Money value={suggestion.amount} />
                                        </Td>
                                        <Td className="text-right whitespace-nowrap">
                                            <Button
                                                size="sm"
                                                aria-label={`Confirm ${suggestion.name}`}
                                                onClick={() =>
                                                    router.post(
                                                        SubscriptionController.confirm.url(
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
                                                aria-label={`Dismiss ${suggestion.name}`}
                                                onClick={() =>
                                                    router.post(
                                                        SubscriptionController.dismiss.url(
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
                </section>

                {cancelled.length > 0 && (
                    <section
                        className="space-y-3"
                        aria-labelledby="cancelled-heading"
                    >
                        <h2 id="cancelled-heading" className="font-semibold">
                            Cancelled
                        </h2>
                        <SubscriptionTable
                            subscriptions={cancelled}
                            intervalLabel={intervalLabel}
                            onEdit={setEditing}
                        />
                    </section>
                )}
            </div>

            {editing && (
                <SubscriptionDialog
                    subscription={editing}
                    intervals={intervals}
                    accounts={accounts}
                    payees={payees}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

function SubscriptionTable({
    subscriptions,
    intervalLabel,
    onEdit,
}: {
    subscriptions: Subscription[];
    intervalLabel: (value: string) => string;
    onEdit: (subscription: Subscription) => void;
}) {
    return (
        <DataTable>
            <thead>
                <tr>
                    <Th>Name</Th>
                    <Th>Payments</Th>
                    <Th>Interval</Th>
                    <Th>Next</Th>
                    <Th className="text-right">Amount</Th>
                    <Th className="text-right">Per month</Th>
                    <Th />
                </tr>
            </thead>
            <tbody>
                {subscriptions.map((subscription) => (
                    <tr key={subscription.id} data-test="subscription">
                        <Td>
                            <div className="flex items-center gap-2 font-medium">
                                <Repeat className="size-4 text-muted-foreground" />
                                {subscription.name}
                                {subscription.source === 'detected' && (
                                    <Badge variant="secondary">detected</Badge>
                                )}
                            </div>
                            <div className="text-xs text-muted-foreground">
                                {subscription.payee
                                    ? `Payee ${subscription.payee}`
                                    : `Description contains “${subscription.description_match}”`}
                                {subscription.account &&
                                    ` · ${subscription.account}`}
                                {subscription.cancelled_at &&
                                    ` · cancelled ${subscription.cancelled_at}`}
                            </div>
                        </Td>
                        <Td className="text-xs text-muted-foreground">
                            {subscription.payments.length === 0
                                ? 'None yet'
                                : subscription.payments
                                      .slice(0, 4)
                                      .map((payment) => (
                                          <div
                                              key={payment.id}
                                              className="whitespace-nowrap"
                                          >
                                              {payment.date}{' '}
                                              <Money value={payment.amount} />
                                              {payment.status === 'pending' &&
                                                  ' (pending)'}
                                          </div>
                                      ))}
                            {subscription.payments.length > 4 && (
                                <Link
                                    href={transactions({
                                        query: {
                                            subscription: subscription.id,
                                        },
                                    })}
                                    className="underline"
                                >
                                    all {subscription.payments.length}
                                </Link>
                            )}
                        </Td>
                        <Td>{intervalLabel(subscription.interval)}</Td>
                        <Td className="whitespace-nowrap">
                            {subscription.status === 'active'
                                ? subscription.next_date
                                : '—'}
                        </Td>
                        <Td className="text-right whitespace-nowrap">
                            <Money value={subscription.amount} />
                        </Td>
                        <Td className="text-right whitespace-nowrap">
                            <Money value={subscription.monthly} />
                        </Td>
                        <Td className="text-right whitespace-nowrap">
                            <Button
                                size="sm"
                                variant="ghost"
                                aria-label={`Edit ${subscription.name}`}
                                onClick={() => onEdit(subscription)}
                            >
                                Edit
                            </Button>
                            {subscription.status === 'active' && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    aria-label={`Cancel ${subscription.name}`}
                                    onClick={() =>
                                        router.post(
                                            SubscriptionController.cancel.url(
                                                subscription.id,
                                            ),
                                            {},
                                            visit,
                                        )
                                    }
                                >
                                    Cancel
                                </Button>
                            )}
                            {subscription.status === 'cancelled' && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    aria-label={`Reactivate ${subscription.name}`}
                                    onClick={() =>
                                        router.patch(
                                            SubscriptionController.update.url(
                                                subscription.id,
                                            ),
                                            { status: 'active' },
                                            visit,
                                        )
                                    }
                                >
                                    Reactivate
                                </Button>
                            )}
                        </Td>
                    </tr>
                ))}
            </tbody>
        </DataTable>
    );
}

Subscriptions.layout = {
    breadcrumbs: [{ title: 'Subscriptions', href: index() }],
};
