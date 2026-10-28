import { Head, Link, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp } from 'lucide-react';
import RuleController from '@/actions/App/Http/Controllers/Finance/RuleController';
import { DataTable, Td, Th } from '@/components/finance/data-table';
import {
    describeAction,
    describeCondition,
} from '@/components/finance/rule-labels';
import type { RuleOptions } from '@/components/finance/rule-labels';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/finance/rules';
import type { Rule } from '@/types/finance';

export default function Rules({
    rules,
    ...options
}: RuleOptions & { rules: Rule[] }) {
    function move(position: number, offset: number) {
        const order = rules.map((rule) => rule.id);
        const [moved] = order.splice(position, 1);
        order.splice(position + offset, 0, moved);

        router.put(
            RuleController.reorder.url(),
            { order },
            { preserveScroll: true },
        );
    }

    function label(rule: Rule, number: number) {
        return rule.name ?? `Rule ${number}`;
    }

    return (
        <>
            <Head title="Rules" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Rules"
                        description="Every matching rule applies, top to bottom; a later rule overrides an earlier one. Values you set by hand are never changed."
                    />
                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <Link href={RuleController.reviewAll()}>
                                Apply all rules…
                            </Link>
                        </Button>
                        <Button asChild>
                            <Link href={RuleController.create()}>New rule</Link>
                        </Button>
                    </div>
                </div>

                {rules.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No rules yet. Create one here, or from a transaction
                        with “Create rule”.
                    </p>
                ) : (
                    <DataTable>
                        <thead>
                            <tr>
                                <Th className="w-20">Order</Th>
                                <Th>Rule</Th>
                                <Th>If all match</Th>
                                <Th>Then</Th>
                                <Th>Enabled</Th>
                                <Th />
                            </tr>
                        </thead>
                        <tbody>
                            {rules.map((rule, position) => (
                                <tr
                                    key={rule.id}
                                    className={rule.enabled ? '' : 'opacity-60'}
                                >
                                    <Td className="whitespace-nowrap">
                                        <span className="mr-1 inline-block w-5 text-muted-foreground tabular-nums">
                                            {position + 1}
                                        </span>
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            className="size-7"
                                            aria-label={`Move ${label(rule, position + 1)} up`}
                                            disabled={position === 0}
                                            onClick={() => move(position, -1)}
                                        >
                                            <ArrowUp />
                                        </Button>
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            className="size-7"
                                            aria-label={`Move ${label(rule, position + 1)} down`}
                                            disabled={
                                                position === rules.length - 1
                                            }
                                            onClick={() => move(position, 1)}
                                        >
                                            <ArrowDown />
                                        </Button>
                                    </Td>
                                    <Td className="font-medium">
                                        {label(rule, position + 1)}
                                    </Td>
                                    <Td>
                                        <ul className="space-y-0.5">
                                            {rule.conditions.map(
                                                (condition, i) => (
                                                    <li key={i}>
                                                        {describeCondition(
                                                            condition,
                                                            options,
                                                        )}
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    </Td>
                                    <Td>
                                        <ul className="space-y-0.5">
                                            {rule.actions.map((action, i) => (
                                                <li key={i}>
                                                    {describeAction(
                                                        action,
                                                        options,
                                                    )}
                                                </li>
                                            ))}
                                        </ul>
                                    </Td>
                                    <Td>
                                        <input
                                            type="checkbox"
                                            className="size-4"
                                            aria-label={`Enable ${label(rule, position + 1)}`}
                                            checked={rule.enabled}
                                            onChange={(event) =>
                                                router.patch(
                                                    RuleController.toggle.url(
                                                        rule.id,
                                                    ),
                                                    {
                                                        enabled:
                                                            event.target
                                                                .checked,
                                                    },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        />
                                    </Td>
                                    <Td className="text-right whitespace-nowrap">
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            asChild
                                        >
                                            <Link
                                                href={RuleController.edit(
                                                    rule.id,
                                                )}
                                            >
                                                Edit
                                            </Link>
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            asChild
                                        >
                                            <Link
                                                href={RuleController.reviewOne(
                                                    rule.id,
                                                )}
                                            >
                                                Apply…
                                            </Link>
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => {
                                                if (
                                                    window.confirm(
                                                        `Delete ${label(rule, position + 1)}? Transactions keep the values it set.`,
                                                    )
                                                ) {
                                                    router.delete(
                                                        RuleController.destroy.url(
                                                            rule.id,
                                                        ),
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    );
                                                }
                                            }}
                                        >
                                            Delete
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

Rules.layout = {
    breadcrumbs: [{ title: 'Rules', href: index() }],
};
