export type AccountTypeOption = {
    value: string;
    label: string;
    importable: boolean;
};

export type AccountSummary = {
    id: number;
    name: string;
    type: string;
    type_label: string;
    importable: boolean;
    currency: string;
    archived: boolean;
    balance: number;
    pending_total: number;
    transactions_count: number;
    latest_snapshot: { date: string; balance: number } | null;
    mismatched_snapshots: number;
};

export type TransactionRow = {
    id: number;
    account_id: number;
    account: string;
    date: string;
    time: string | null;
    description: string;
    amount: number;
    fee: number;
    currency: string;
    status: 'completed' | 'pending';
    category_id: number | null;
    category: string | null;
    category_source: FieldSource | null;
    payee_id: number | null;
    payee: string | null;
    payee_source: FieldSource | null;
    is_transfer: boolean;
    transfer_source: TransferSource | null;
    subscription_id: number | null;
    subscription: string | null;
    subscription_source: 'auto' | 'manual' | null;
    counterpart: {
        id: number;
        account: string;
        date: string;
        time: string | null;
        description: string;
        amount: number;
    } | null;
    /** Only on the transaction list: the row is in an open transfer suggestion. */
    suggested_transfer?: boolean;
};

export type FieldSource = 'manual' | 'rule';

export type TransferSource = 'manual' | 'rule' | 'auto';

export type TransferSuggestion = {
    id: number;
    outflow: TransactionRow;
    inflow: TransactionRow;
};

export type RuleCondition = {
    field: 'description' | 'payee' | 'amount' | 'absolute_amount' | 'account';
    operator:
        | 'contains'
        | 'equals'
        | 'starts_with'
        | 'regex'
        | 'is'
        | 'greater_than'
        | 'less_than'
        | 'between';
    value: string | number | number[];
};

export type RuleAction = {
    type: 'set_category' | 'set_payee' | 'mark_as_transfer';
    value?: number | null;
    name?: string;
};

export type Rule = {
    id: number;
    name: string | null;
    position: number;
    enabled: boolean;
    conditions: RuleCondition[];
    actions: RuleAction[];
};

export type Option = { id: number; name: string };

export type MappingSummary = {
    id: number;
    name: string;
    account_id: number;
    account: string;
    columns: number;
    header: string;
    filter: { column: string; value: string | null } | null;
};

export type BatchAccountSummary = {
    account_id: number;
    account: string;
    mapping: string;
    created: number;
    updated: number;
    skipped_duplicate: number;
    skipped_currency: number;
    removed_reverted: number;
    skipped_reverted: number;
    snapshot: { date: string; balance: number } | null;
};

export type Batch = {
    id: number;
    filename: string;
    created_at: string;
    undone_at: string | null;
    blocked_by: { id: number; filename: string } | null;
    summary: {
        created: number;
        updated: number;
        skipped_duplicate: number;
        skipped_currency: number;
        removed_reverted: number;
        skipped_reverted: number;
        unmapped_rows: number;
        categorized_by_rules?: number;
        paired_as_transfers?: number;
        transfer_suggestions?: number;
        linked_to_subscriptions?: number;
        subscription_suggestions?: number;
        accounts: BatchAccountSummary[];
        currency_rows: {
            line: number;
            account: string;
            date: string;
            description: string;
            amount: string;
            currency: string;
        }[];
    };
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type SubscriptionInterval =
    | 'weekly'
    | 'monthly'
    | 'quarterly'
    | 'yearly';

export type IntervalOption = { value: SubscriptionInterval; label: string };

export type SubscriptionPayment = {
    id: number;
    date: string;
    description: string;
    account: string;
    amount: number;
    status: 'completed' | 'pending';
};

export type Subscription = {
    id: number;
    name: string;
    payee_id: number | null;
    payee: string | null;
    description_match: string | null;
    account_id: number | null;
    account: string | null;
    amount: number;
    interval: SubscriptionInterval;
    monthly: number;
    next_date: string;
    status: 'active' | 'cancelled';
    source: 'manual' | 'detected';
    cancelled_at: string | null;
    payments: SubscriptionPayment[];
};

export type SubscriptionSuggestion = {
    id: number;
    name: string;
    match: string;
    account: string | null;
    amount: number;
    interval: SubscriptionInterval;
    monthly: number;
    next_date: string;
    charges: {
        id: number;
        date: string;
        amount: number;
        description: string;
    }[];
};

export type DashboardAccount = {
    id: number;
    name: string;
    type: string;
    type_label: string;
    balance: number;
    latest_snapshot_date: string | null;
    mismatched_snapshots: number;
    latest_difference: number | null;
};

export type DashboardSummary = {
    has_accounts: boolean;
    today: string;
    net_worth: { total: number; accounts: number; stale_since: string | null };
    account_groups: {
        type: string;
        label: string;
        total: number;
        accounts: DashboardAccount[];
    }[];
    spending: {
        month: string;
        from: string;
        to: string;
        total: number;
        categories: {
            category_id: number | null;
            category: string;
            amount: number;
            count: number;
        }[];
    };
    subscriptions: {
        monthly_total: number;
        active: number;
        upcoming: {
            id: number;
            name: string;
            amount: number;
            interval: SubscriptionInterval;
            next_date: string;
            overdue: boolean;
        }[];
        upcoming_total: number;
    };
    attention: {
        uncategorized: number;
        transfer_suggestions: number;
        subscription_suggestions: number;
    };
};
