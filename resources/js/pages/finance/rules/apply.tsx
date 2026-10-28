import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import RuleController from '@/actions/App/Http/Controllers/Finance/RuleController';
import { DataTable, Td, Th } from '@/components/finance/data-table';
import { Money } from '@/components/finance/money';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/finance/rules';
import type { Rule, TransactionRow } from '@/types/finance';

type Change = { field: 'category' | 'payee'; from: string | null; to: string };

export default function ApplyRules({
    rule,
    transactions,
    changes,
    sample,
}: {
    rule: Rule | null;
    transactions: number;
    changes: { category: number; payee: number };
    sample: { transaction: TransactionRow; changes: Change[] }[];
}) {
    const [processing, setProcessing] = useState(false);
    const title = rule
        ? `Apply ${rule.name ?? `rule ${rule.position}`}`
        : 'Apply all rules';

    function apply() {
        const visit = {
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
        };

        if (rule) {
            router.post(RuleController.applyOne.url(rule.id), {}, visit);
        } else {
            router.post(RuleController.applyAll.url(), {}, visit);
        }
    }

    return (
        <>
            <Head title={title} />
            <div className="space-y-6 p-4">
                <Heading
                    title={title}
                    description={
                        rule
                            ? 'A dry run on the transactions this rule matches. All enabled rules run on them in order; values set by hand are left alone.'
                            : 'A dry run of every enabled rule on all existing transactions. Values set by hand are left alone.'
                    }
                />

                <dl
                    className="grid gap-4 sm:grid-cols-3"
                    data-test="apply-counts"
                >
                    {(
                        [
                            ['Transactions to change', transactions],
                            ['Category changes', changes.category],
                            ['Payee changes', changes.payee],
                        ] as const
                    ).map(([label, value]) => (
                        <div key={label} className="rounded-xl border p-4">
                            <dt className="text-xs text-muted-foreground">
                                {label}
                            </dt>
                            <dd className="text-2xl font-semibold tabular-nums">
                                {value}
                            </dd>
                        </div>
                    ))}
                </dl>

                <div className="flex gap-2">
                    <Button
                        onClick={apply}
                        disabled={processing || transactions === 0}
                    >
                        {transactions === 0
                            ? 'Nothing to change'
                            : `Apply to ${transactions} ${transactions === 1 ? 'transaction' : 'transactions'}`}
                    </Button>
                    <Button variant="ghost" asChild>
                        <Link href={index()}>Back to rules</Link>
                    </Button>
                </div>

                {sample.length > 0 && (
                    <section className="space-y-3">
                        <Heading
                            variant="small"
                            title={
                                sample.length < transactions
                                    ? `First ${sample.length} of ${transactions} changes`
                                    : 'Changes'
                            }
                        />
                        <DataTable>
                            <thead>
                                <tr>
                                    <Th>Date</Th>
                                    <Th>Description</Th>
                                    <Th>Account</Th>
                                    <Th className="text-right">Amount</Th>
                                    <Th>Change</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {sample.map(({ transaction, changes }) => (
                                    <tr key={transaction.id}>
                                        <Td className="whitespace-nowrap text-muted-foreground">
                                            {transaction.date}
                                        </Td>
                                        <Td>{transaction.description}</Td>
                                        <Td className="whitespace-nowrap text-muted-foreground">
                                            {transaction.account}
                                        </Td>
                                        <Td className="text-right whitespace-nowrap">
                                            <Money
                                                value={transaction.amount}
                                                signed
                                            />
                                        </Td>
                                        <Td>
                                            <ul>
                                                {changes.map((change) => (
                                                    <li key={change.field}>
                                                        <span className="text-muted-foreground capitalize">
                                                            {change.field}:
                                                        </span>{' '}
                                                        {change.from ?? '—'} →{' '}
                                                        <span className="font-medium">
                                                            {change.to}
                                                        </span>
                                                    </li>
                                                ))}
                                            </ul>
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

ApplyRules.layout = {
    breadcrumbs: [{ title: 'Rules', href: index() }],
};
