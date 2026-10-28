import { Head, Link, router, useHttp, usePage } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import RuleController from '@/actions/App/Http/Controllers/Finance/RuleController';
import { DataTable, Td, Th } from '@/components/finance/data-table';
import { Money } from '@/components/finance/money';
import { NativeSelect } from '@/components/finance/native-select';
import { ACTIONS, FIELDS, OPERATORS } from '@/components/finance/rule-labels';
import type { RuleOptions } from '@/components/finance/rule-labels';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/finance/rules';
import type {
    Rule,
    RuleAction,
    RuleCondition,
    TransactionRow,
} from '@/types/finance';

type RuleInput = {
    name: string;
    enabled: boolean;
    conditions: RuleCondition[];
    actions: RuleAction[];
};

/** Conditions as typed: amounts stay text (CHF) until they are sent as Rappen. */
type ConditionDraft = {
    field: RuleCondition['field'];
    operator: RuleCondition['operator'];
    value: string;
    upper: string;
};

/** `value` is an id, or `new` for a payee created from `name` on save. */
type ActionDraft = { type: RuleAction['type']; value: string; name: string };

type Preview = { count: number; sample: TransactionRow[] };

const isAmount = (field: RuleCondition['field']) =>
    field === 'amount' || field === 'absolute_amount';

function toCHF(rappen: unknown): string {
    return typeof rappen === 'number' ? (rappen / 100).toFixed(2) : '';
}

function toRappen(text: string): number | string {
    const number = Number(text.trim().replace(',', '.'));

    return text.trim() === '' || Number.isNaN(number)
        ? text
        : Math.round(number * 100);
}

function draftCondition(condition: RuleCondition): ConditionDraft {
    const value = condition.value;

    if (isAmount(condition.field)) {
        return {
            ...condition,
            value: toCHF(Array.isArray(value) ? value[0] : value),
            upper: Array.isArray(value) ? toCHF(value[1]) : '',
        };
    }

    return { ...condition, value: String(value), upper: '' };
}

function payloadCondition(draft: ConditionDraft) {
    if (isAmount(draft.field)) {
        return {
            field: draft.field,
            operator: draft.operator,
            value:
                draft.operator === 'between'
                    ? [toRappen(draft.value), toRappen(draft.upper)]
                    : toRappen(draft.value),
        };
    }

    if (draft.field === 'description') {
        return {
            field: draft.field,
            operator: draft.operator,
            value: draft.value,
        };
    }

    return {
        field: draft.field,
        operator: draft.operator,
        value: draft.value === '' ? '' : Number(draft.value),
    };
}

function payloadAction(draft: ActionDraft) {
    if (draft.value === 'new') {
        return { type: draft.type, name: draft.name };
    }

    return {
        type: draft.type,
        value: draft.value === '' ? null : Number(draft.value),
    };
}

export default function EditRule({
    rule,
    initial,
    ...options
}: RuleOptions & { rule: Rule | null; initial: RuleInput }) {
    const errors = usePage().props.errors as Record<string, string>;
    const [name, setName] = useState(initial.name);
    const [enabled, setEnabled] = useState(initial.enabled);
    const [conditions, setConditions] = useState<ConditionDraft[]>(
        initial.conditions.map(draftCondition),
    );
    const [actions, setActions] = useState<ActionDraft[]>(
        initial.actions.map((action) => ({
            type: action.type,
            value: action.value == null ? '' : String(action.value),
            name: '',
        })),
    );
    const [processing, setProcessing] = useState(false);
    const preview = useHttp<
        { conditions: ReturnType<typeof payloadCondition>[] },
        Preview
    >({ conditions: [] });

    const payload = JSON.stringify(conditions.map(payloadCondition));

    useEffect(() => {
        const timer = window.setTimeout(
            () => preview.setData('conditions', JSON.parse(payload)),
            300,
        );

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [payload]);

    useEffect(() => {
        if (preview.data.conditions.length > 0) {
            preview.post(RuleController.matches.url()).catch(() => {});
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [preview.data]);

    function updateCondition(index: number, change: Partial<ConditionDraft>) {
        setConditions((current) =>
            current.map((condition, i) => {
                if (i !== index) {
                    return condition;
                }

                const next = { ...condition, ...change };

                if (change.field && change.field !== condition.field) {
                    const field = FIELDS.find(
                        (item) => item.value === change.field,
                    );
                    next.operator = field?.operators[0] ?? 'contains';
                    next.value = '';
                    next.upper = '';
                }

                return next;
            }),
        );
    }

    function updateAction(index: number, change: Partial<ActionDraft>) {
        setActions((current) =>
            current.map((action, i) =>
                i === index
                    ? {
                          ...action,
                          ...change,
                          ...(change.type && change.type !== action.type
                              ? { value: '', name: '' }
                              : {}),
                      }
                    : action,
            ),
        );
    }

    function save(event: FormEvent) {
        event.preventDefault();

        const data = {
            name,
            enabled,
            conditions: conditions.map(payloadCondition),
            actions: actions.map(payloadAction),
        };
        const visit = {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
        };

        if (rule) {
            router.put(RuleController.update.url(rule.id), data, visit);
        } else {
            router.post(RuleController.store.url(), data, visit);
        }
    }

    const unusedAction = ACTIONS.find(
        (action) => !actions.some((item) => item.type === action.value),
    );

    return (
        <>
            <Head title={rule ? 'Edit rule' : 'New rule'} />
            <div className="grid gap-8 p-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <form onSubmit={save} className="space-y-6" aria-label="Rule">
                    <Heading
                        title={rule ? 'Edit rule' : 'New rule'}
                        description="Conditions compare descriptions case-insensitively. Amounts are in CHF and signed: spending is negative."
                    />

                    <div className="grid gap-2">
                        <Label htmlFor="rule-name">Name (optional)</Label>
                        <Input
                            id="rule-name"
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                            placeholder="e.g. Groceries"
                        />
                        <InputError message={errors.name} />
                    </div>

                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            className="size-4"
                            checked={enabled}
                            onChange={(event) =>
                                setEnabled(event.target.checked)
                            }
                        />
                        Enabled
                    </label>

                    <fieldset className="space-y-3">
                        <legend className="mb-2 text-sm font-medium">
                            If all of these match
                        </legend>
                        {conditions.map((condition, i) => {
                            const field = FIELDS.find(
                                (item) => item.value === condition.field,
                            );
                            const n = i + 1;

                            return (
                                <div key={i} className="space-y-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <NativeSelect
                                            aria-label={`Condition ${n} field`}
                                            className="w-40"
                                            value={condition.field}
                                            onChange={(event) =>
                                                updateCondition(i, {
                                                    field: event.target
                                                        .value as RuleCondition['field'],
                                                })
                                            }
                                        >
                                            {FIELDS.map((item) => (
                                                <option
                                                    key={item.value}
                                                    value={item.value}
                                                >
                                                    {item.label}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                        <NativeSelect
                                            aria-label={`Condition ${n} operator`}
                                            className="w-40"
                                            value={condition.operator}
                                            onChange={(event) =>
                                                updateCondition(i, {
                                                    operator: event.target
                                                        .value as RuleCondition['operator'],
                                                })
                                            }
                                        >
                                            {field?.operators.map(
                                                (operator) => (
                                                    <option
                                                        key={operator}
                                                        value={operator}
                                                    >
                                                        {OPERATORS[operator]}
                                                    </option>
                                                ),
                                            )}
                                        </NativeSelect>
                                        {condition.field === 'payee' ||
                                        condition.field === 'account' ? (
                                            <NativeSelect
                                                aria-label={`Condition ${n} value`}
                                                className="w-48"
                                                value={condition.value}
                                                onChange={(event) =>
                                                    updateCondition(i, {
                                                        value: event.target
                                                            .value,
                                                    })
                                                }
                                            >
                                                <option value="">
                                                    Choose…
                                                </option>
                                                {(condition.field === 'payee'
                                                    ? options.payees
                                                    : options.accounts
                                                ).map((option) => (
                                                    <option
                                                        key={option.id}
                                                        value={option.id}
                                                    >
                                                        {option.name}
                                                    </option>
                                                ))}
                                            </NativeSelect>
                                        ) : (
                                            <Input
                                                aria-label={`Condition ${n} value`}
                                                className="w-48 flex-1"
                                                inputMode={
                                                    isAmount(condition.field)
                                                        ? 'decimal'
                                                        : undefined
                                                }
                                                placeholder={
                                                    isAmount(condition.field)
                                                        ? 'CHF, e.g. -50.00'
                                                        : ''
                                                }
                                                value={condition.value}
                                                onChange={(event) =>
                                                    updateCondition(i, {
                                                        value: event.target
                                                            .value,
                                                    })
                                                }
                                            />
                                        )}
                                        {condition.operator === 'between' && (
                                            <>
                                                <span className="text-sm text-muted-foreground">
                                                    and
                                                </span>
                                                <Input
                                                    aria-label={`Condition ${n} upper value`}
                                                    className="w-32"
                                                    inputMode="decimal"
                                                    value={condition.upper}
                                                    onChange={(event) =>
                                                        updateCondition(i, {
                                                            upper: event.target
                                                                .value,
                                                        })
                                                    }
                                                />
                                            </>
                                        )}
                                        <Button
                                            type="button"
                                            size="icon"
                                            variant="ghost"
                                            aria-label={`Remove condition ${n}`}
                                            disabled={conditions.length === 1}
                                            onClick={() =>
                                                setConditions((current) =>
                                                    current.filter(
                                                        (_, j) => j !== i,
                                                    ),
                                                )
                                            }
                                        >
                                            <Trash2 />
                                        </Button>
                                    </div>
                                    <InputError
                                        message={
                                            errors[`conditions.${i}.value`] ??
                                            errors[`conditions.${i}.operator`]
                                        }
                                    />
                                </div>
                            );
                        })}
                        <InputError message={errors.conditions} />
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                setConditions((current) => [
                                    ...current,
                                    {
                                        field: 'description',
                                        operator: 'contains',
                                        value: '',
                                        upper: '',
                                    },
                                ])
                            }
                        >
                            <Plus /> Add condition
                        </Button>
                    </fieldset>

                    <fieldset className="space-y-3">
                        <legend className="mb-2 text-sm font-medium">
                            Then
                        </legend>
                        {actions.map((action, i) => {
                            const n = i + 1;
                            const choices =
                                action.type === 'set_category'
                                    ? options.categories
                                    : options.payees;

                            return (
                                <div key={i} className="space-y-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <NativeSelect
                                            aria-label={`Action ${n}`}
                                            className="w-40"
                                            value={action.type}
                                            onChange={(event) =>
                                                updateAction(i, {
                                                    type: event.target
                                                        .value as RuleAction['type'],
                                                })
                                            }
                                        >
                                            {ACTIONS.map((item) => (
                                                <option
                                                    key={item.value}
                                                    value={item.value}
                                                >
                                                    {item.label}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                        <NativeSelect
                                            aria-label={`Action ${n} value`}
                                            className="w-44"
                                            value={action.value}
                                            onChange={(event) =>
                                                updateAction(i, {
                                                    value: event.target.value,
                                                })
                                            }
                                        >
                                            <option value="">Choose…</option>
                                            {action.type === 'set_payee' && (
                                                <option value="new">
                                                    New payee…
                                                </option>
                                            )}
                                            {choices.map((option) => (
                                                <option
                                                    key={option.id}
                                                    value={option.id}
                                                >
                                                    {option.name}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                        {action.value === 'new' && (
                                            <Input
                                                aria-label={`Action ${n} new payee name`}
                                                className="w-36"
                                                placeholder="Payee name"
                                                value={action.name}
                                                onChange={(event) =>
                                                    updateAction(i, {
                                                        name: event.target
                                                            .value,
                                                    })
                                                }
                                            />
                                        )}
                                        <Button
                                            type="button"
                                            size="icon"
                                            variant="ghost"
                                            aria-label={`Remove action ${n}`}
                                            onClick={() =>
                                                setActions((current) =>
                                                    current.filter(
                                                        (_, j) => j !== i,
                                                    ),
                                                )
                                            }
                                        >
                                            <Trash2 />
                                        </Button>
                                    </div>
                                    <InputError
                                        message={
                                            errors[`actions.${i}.value`] ??
                                            errors[`actions.${i}.type`] ??
                                            errors[`actions.${i}.name`]
                                        }
                                    />
                                </div>
                            );
                        })}
                        <InputError message={errors.actions} />
                        {unusedAction && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    setActions((current) => [
                                        ...current,
                                        {
                                            type: unusedAction.value,
                                            value: '',
                                            name: '',
                                        },
                                    ])
                                }
                            >
                                <Plus /> Add action
                            </Button>
                        )}
                    </fieldset>

                    <div className="flex gap-2">
                        <Button disabled={processing}>
                            {rule ? 'Save rule' : 'Create rule'}
                        </Button>
                        <Button variant="ghost" asChild>
                            <Link href={index()}>Cancel</Link>
                        </Button>
                    </div>
                </form>

                <section
                    className="space-y-3"
                    aria-label="Matching transactions"
                >
                    <Heading
                        variant="small"
                        title="Matching transactions"
                        description="Transactions these conditions match right now. Saving does not change them; use Apply on the Rules page."
                    />
                    <p
                        className="text-sm text-muted-foreground"
                        data-test="preview-count"
                    >
                        {preview.hasErrors
                            ? 'Fix the conditions to see matches.'
                            : preview.response === null
                              ? 'Enter a condition to see matches.'
                              : `${preview.response.count} matching ${preview.response.count === 1 ? 'transaction' : 'transactions'}${preview.response.count > preview.response.sample.length ? `, showing ${preview.response.sample.length}` : ''}`}
                    </p>
                    {!preview.hasErrors &&
                        preview.response !== null &&
                        preview.response.sample.length > 0 && (
                            <DataTable>
                                <thead>
                                    <tr>
                                        <Th>Date</Th>
                                        <Th>Description</Th>
                                        <Th>Category</Th>
                                        <Th className="text-right">Amount</Th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {preview.response.sample.map((row) => (
                                        <tr key={row.id}>
                                            <Td className="whitespace-nowrap text-muted-foreground">
                                                {row.date}
                                            </Td>
                                            <Td>{row.description}</Td>
                                            <Td className="text-muted-foreground">
                                                {row.category ?? '—'}
                                            </Td>
                                            <Td className="text-right whitespace-nowrap">
                                                <Money
                                                    value={row.amount}
                                                    signed
                                                />
                                            </Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </DataTable>
                        )}
                </section>
            </div>
        </>
    );
}

EditRule.layout = {
    breadcrumbs: [{ title: 'Rules', href: index() }],
};
