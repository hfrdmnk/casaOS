import { Form, router } from '@inertiajs/react';
import { Repeat } from 'lucide-react';
import { useState } from 'react';
import SubscriptionController from '@/actions/App/Http/Controllers/Finance/SubscriptionController';
import { NativeSelect } from '@/components/finance/native-select';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
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
import { Label } from '@/components/ui/label';
import type {
    IntervalOption,
    Option,
    Subscription,
    TransactionRow,
} from '@/types/finance';

const preserve = { preserveScroll: true, preserveState: true } as const;

/**
 * Shown on a transaction linked to a subscription.
 */
export function SubscriptionMarker({
    transaction,
}: {
    transaction: TransactionRow;
}) {
    if (transaction.subscription === null) {
        return null;
    }

    return (
        <Badge
            variant="outline"
            className="mt-1 gap-1"
            data-test="subscription-marker"
            title={
                transaction.subscription_source === 'manual'
                    ? 'Linked by hand'
                    : 'Linked automatically'
            }
        >
            <Repeat className="size-3" />
            {transaction.subscription}
        </Badge>
    );
}

/**
 * The subscription menu of a charge: mark as subscription, link to one, unlink.
 */
export function SubscriptionActions({
    transaction,
    subscriptions,
    intervals,
}: {
    transaction: TransactionRow;
    subscriptions: Option[];
    intervals: IntervalOption[];
}) {
    const [marking, setMarking] = useState(false);

    if (transaction.is_transfer || transaction.amount >= 0) {
        return <span className="inline-block size-8" />;
    }

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        size="icon"
                        variant="ghost"
                        className="size-8"
                        aria-label={`Subscription actions for ${transaction.description}`}
                    >
                        <Repeat />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    {transaction.subscription_id === null ? (
                        <DropdownMenuItem onSelect={() => setMarking(true)}>
                            Mark as subscription…
                        </DropdownMenuItem>
                    ) : (
                        <DropdownMenuItem
                            onSelect={() =>
                                router.delete(
                                    SubscriptionController.unlink.url(
                                        transaction.id,
                                    ),
                                    preserve,
                                )
                            }
                        >
                            Unlink from {transaction.subscription}
                        </DropdownMenuItem>
                    )}
                    {subscriptions
                        .filter(
                            (subscription) =>
                                subscription.id !== transaction.subscription_id,
                        )
                        .map((subscription) => (
                            <DropdownMenuItem
                                key={subscription.id}
                                onSelect={() =>
                                    router.post(
                                        SubscriptionController.link.url(
                                            transaction.id,
                                        ),
                                        { subscription_id: subscription.id },
                                        preserve,
                                    )
                                }
                            >
                                Link to {subscription.name}
                            </DropdownMenuItem>
                        ))}
                </DropdownMenuContent>
            </DropdownMenu>
            {marking && (
                <SubscriptionDialog
                    transaction={transaction}
                    intervals={intervals}
                    onClose={() => setMarking(false)}
                />
            )}
        </>
    );
}

/**
 * Create a subscription from a transaction ("Mark as subscription"), or edit one.
 */
export function SubscriptionDialog({
    transaction,
    subscription,
    intervals,
    accounts = [],
    payees = [],
    onClose,
}: {
    transaction?: TransactionRow;
    subscription?: Subscription;
    intervals: IntervalOption[];
    accounts?: Option[];
    payees?: Option[];
    onClose: () => void;
}) {
    const editing = subscription !== undefined;
    const [interval, setInterval] = useState(
        subscription?.interval ?? 'monthly',
    );
    const action = editing
        ? SubscriptionController.update.form(subscription.id)
        : SubscriptionController.store.form();
    const amount = subscription?.amount ?? -(transaction?.amount ?? 0);

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {editing ? 'Edit subscription' : 'Mark as subscription'}
                    </DialogTitle>
                    <DialogDescription>
                        {editing
                            ? 'Changing how it matches links new matching charges; linked ones stay.'
                            : `Every charge ${transaction?.payee ? `with payee ${transaction.payee}` : 'with this description'} in ${transaction?.account} within ±10% of the amount is linked, and the next date is predicted from the newest one.`}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...action}
                    options={preserve}
                    onSuccess={onClose}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            {transaction && (
                                <input
                                    type="hidden"
                                    name="transaction_id"
                                    value={transaction.id}
                                />
                            )}
                            <div className="grid gap-2">
                                <Label htmlFor="subscription-name">Name</Label>
                                <Input
                                    id="subscription-name"
                                    name="name"
                                    defaultValue={
                                        subscription?.name ??
                                        transaction?.payee ??
                                        transaction?.description
                                    }
                                />
                                <InputError message={errors.name} />
                            </div>
                            <div className="grid grid-cols-2 gap-3">
                                <div className="grid gap-2">
                                    <Label htmlFor="subscription-amount">
                                        Amount (CHF)
                                    </Label>
                                    <Input
                                        id="subscription-amount"
                                        name="amount"
                                        inputMode="decimal"
                                        defaultValue={(amount / 100).toFixed(2)}
                                    />
                                    <InputError message={errors.amount} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="subscription-interval">
                                        Interval
                                    </Label>
                                    <NativeSelect
                                        id="subscription-interval"
                                        name="interval"
                                        value={interval}
                                        onChange={(event) =>
                                            setInterval(
                                                event.target
                                                    .value as typeof interval,
                                            )
                                        }
                                    >
                                        {intervals.map((option) => (
                                            <option
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                    <InputError message={errors.interval} />
                                </div>
                            </div>
                            {editing && (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="subscription-payee">
                                            Payee
                                        </Label>
                                        <NativeSelect
                                            id="subscription-payee"
                                            name="payee_id"
                                            defaultValue={
                                                subscription.payee_id ?? ''
                                            }
                                        >
                                            <option value="">
                                                No payee (match description)
                                            </option>
                                            {payees.map((payee) => (
                                                <option
                                                    key={payee.id}
                                                    value={payee.id}
                                                >
                                                    {payee.name}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="subscription-match">
                                            Description contains
                                        </Label>
                                        <Input
                                            id="subscription-match"
                                            name="description_match"
                                            defaultValue={
                                                subscription.description_match ??
                                                ''
                                            }
                                        />
                                        <InputError
                                            message={errors.description_match}
                                        />
                                    </div>
                                    <div className="grid grid-cols-2 gap-3">
                                        <div className="grid gap-2">
                                            <Label htmlFor="subscription-account">
                                                Account
                                            </Label>
                                            <NativeSelect
                                                id="subscription-account"
                                                name="account_id"
                                                defaultValue={
                                                    subscription.account_id ??
                                                    ''
                                                }
                                            >
                                                <option value="">
                                                    Any account
                                                </option>
                                                {accounts.map((account) => (
                                                    <option
                                                        key={account.id}
                                                        value={account.id}
                                                    >
                                                        {account.name}
                                                    </option>
                                                ))}
                                            </NativeSelect>
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="subscription-next">
                                                Next date
                                            </Label>
                                            <Input
                                                id="subscription-next"
                                                type="date"
                                                name="next_date"
                                                defaultValue={
                                                    subscription.next_date
                                                }
                                            />
                                            <InputError
                                                message={errors.next_date}
                                            />
                                        </div>
                                    </div>
                                </>
                            )}
                            <InputError message={errors.subscription} />
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={onClose}
                                >
                                    Close
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {editing ? 'Save' : 'Create subscription'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
