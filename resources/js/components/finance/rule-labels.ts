import { formatMoney } from '@/lib/money';
import type { Option, RuleAction, RuleCondition } from '@/types/finance';

export type RuleOptions = {
    accounts: Option[];
    categories: Option[];
    payees: Option[];
};

export const FIELDS: {
    value: RuleCondition['field'];
    label: string;
    operators: RuleCondition['operator'][];
}[] = [
    {
        value: 'description',
        label: 'Description',
        operators: ['contains', 'equals', 'starts_with', 'regex'],
    },
    { value: 'payee', label: 'Payee', operators: ['is'] },
    {
        value: 'amount',
        label: 'Amount',
        operators: ['equals', 'greater_than', 'less_than', 'between'],
    },
    {
        value: 'absolute_amount',
        label: 'Absolute amount',
        operators: ['equals', 'greater_than', 'less_than', 'between'],
    },
    { value: 'account', label: 'Account', operators: ['is'] },
];

export const OPERATORS: Record<RuleCondition['operator'], string> = {
    contains: 'contains',
    equals: 'equals',
    starts_with: 'starts with',
    regex: 'matches regex',
    is: 'is',
    greater_than: 'is greater than',
    less_than: 'is less than',
    between: 'is between',
};

export const ACTIONS: { value: RuleAction['type']; label: string }[] = [
    { value: 'set_category', label: 'Set category' },
    { value: 'set_payee', label: 'Set payee' },
];

function nameOf(options: Option[], id: unknown): string {
    return (
        options.find((option) => option.id === Number(id))?.name ?? '(deleted)'
    );
}

export function describeCondition(
    condition: RuleCondition,
    options: RuleOptions,
): string {
    const field =
        FIELDS.find((item) => item.value === condition.field)?.label ??
        condition.field;
    const operator = OPERATORS[condition.operator];
    const value = condition.value;

    switch (condition.field) {
        case 'description':
            return `${field} ${operator} “${String(value)}”`;
        case 'payee':
            return `${field} ${operator} ${nameOf(options.payees, value)}`;
        case 'account':
            return `${field} ${operator} ${nameOf(options.accounts, value)}`;
        default:
            return Array.isArray(value)
                ? `${field} ${operator} ${formatMoney(value[0])} and ${formatMoney(value[1])}`
                : `${field} ${operator} ${formatMoney(Number(value))}`;
    }
}

export function describeAction(
    action: RuleAction,
    options: RuleOptions,
): string {
    return action.type === 'set_category'
        ? `Category → ${nameOf(options.categories, action.value)}`
        : `Payee → ${nameOf(options.payees, action.value)}`;
}
