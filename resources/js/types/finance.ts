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
};

export type FieldSource = 'manual' | 'rule';

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
    type: 'set_category' | 'set_payee';
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
