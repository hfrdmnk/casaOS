import { http, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatMoney } from '@/lib/money';
import type { AccountTypeOption } from '@/types/finance';
import { DataTable, Td, Th } from './data-table';
import { NativeSelect } from './native-select';

export type MappingValues = {
    name: string;
    account_id: string;
    new_account_name: string;
    new_account_type: string;
    encoding: string;
    delimiter: string;
    skip_lines: number;
    date_column: string;
    date_format: string;
    description_column: string;
    amount_mode: 'signed' | 'split';
    amount_column: string;
    debit_column: string;
    credit_column: string;
    invert_sign: boolean;
    decimal_separator: string;
    thousands_separator: string;
    fee_column: string;
    balance_column: string;
    currency_column: string;
    status_column: string;
    status_map: Record<string, string>;
    filter_column: string;
    filter_value: string;
};

type ParsedPreviewRow = {
    line: number;
    errors: string[];
    foreign_currency: boolean;
    currency: string | null;
    date: string;
    time: string | null;
    description: string;
    amount: number;
    fee: number;
    balance: number | null;
    status: string;
};

type Preview = {
    header: string[];
    rows: string[][];
    row_count: number;
    distinct: string[][];
    parsed: null | {
        selected: number;
        failed: number;
        foreign_currency: number;
        statuses: Record<string, number>;
        last_balance: number | null;
        errors: { line: number; messages: string[] }[];
        rows: ParsedPreviewRow[];
    };
};

export const DATE_FORMATS = [
    { value: 'Y-m-d H:i:s', example: '2026-10-27 18:27:32' },
    { value: 'Y-m-d', example: '2026-10-27' },
    { value: 'd.m.Y', example: '27.10.2026' },
    { value: 'd.m.Y H:i', example: '27.10.2026 18:27' },
    { value: 'd.m.Y H:i:s', example: '27.10.2026 18:27:32' },
    { value: 'd.m.y', example: '27.10.26' },
    { value: 'd/m/Y', example: '27/10/2026' },
    { value: 'm/d/Y', example: '10/27/2026' },
];

const DELIMITERS = [
    { value: ',', label: 'Comma ,' },
    { value: ';', label: 'Semicolon ;' },
    { value: '\t', label: 'Tab' },
    { value: '|', label: 'Pipe |' },
];

const STATUSES = ['completed', 'pending', 'reverted'];

/**
 * Pick a column whose header matches one of the patterns, as a starting guess.
 */
function guessColumn(header: string[], patterns: RegExp[]): string {
    for (const pattern of patterns) {
        const index = header.findIndex((name) => pattern.test(name));

        if (index !== -1) {
            return String(index);
        }
    }

    return '';
}

function guessDateFormat(sample: string): string {
    const patterns: [RegExp, string][] = [
        [/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/, 'Y-m-d H:i:s'],
        [/^\d{4}-\d{2}-\d{2}$/, 'Y-m-d'],
        [/^\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}:\d{2}$/, 'd.m.Y H:i:s'],
        [/^\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}$/, 'd.m.Y H:i'],
        [/^\d{2}\.\d{2}\.\d{4}$/, 'd.m.Y'],
        [/^\d{2}\.\d{2}\.\d{2}$/, 'd.m.y'],
        [/^\d{2}\/\d{2}\/\d{4}$/, 'd/m/Y'],
    ];

    return patterns.find(([pattern]) => pattern.test(sample.trim()))?.[1] ?? '';
}

/**
 * Guesses for a freshly read header, used until the user changes a field.
 */
function guessFromHeader(
    header: string[],
    rows: string[][],
    distinct: string[][],
): Partial<MappingValues> {
    const date = guessColumn(header, [
        /^started date$/i,
        /^(booking |transaction )?date$/i,
        /date|datum/i,
    ]);
    const amount = guessColumn(header, [/^amount$/i, /amount|betrag/i]);
    const debit = guessColumn(header, [/debit|belastung|lastschrift/i]);
    const credit = guessColumn(header, [/credit|gutschrift/i]);
    const split = amount === '' && debit !== '' && credit !== '';
    const amountSample = rows
        .map((row) => row[Number(split ? debit : amount)] ?? '')
        .find((value) => value.trim() !== '');
    const fallbackSample = rows
        .map((row) => row[Number(split ? credit : amount)] ?? '')
        .find((value) => value.trim() !== '');
    const sample = amountSample ?? fallbackSample ?? '';

    const status = guessColumn(header, [/^state$/i, /^status$/i]);
    const statusMap = Object.fromEntries(
        (status === '' ? [] : (distinct[Number(status)] ?? []))
            .map((value) => [value, value.toLowerCase()])
            .filter(([, guess]) => STATUSES.includes(guess)),
    );

    return {
        date_column: date,
        date_format:
            date === '' ? '' : guessDateFormat(rows[0]?.[Number(date)] ?? ''),
        description_column: guessColumn(header, [
            /^description$/i,
            /description|text|buchungstext|beschreibung|details/i,
        ]),
        amount_mode: split ? 'split' : 'signed',
        amount_column: amount,
        debit_column: split ? debit : '',
        credit_column: split ? credit : '',
        decimal_separator: /,\d{1,2}$/.test(sample.trim()) ? ',' : '.',
        fee_column: guessColumn(header, [/^fee$/i, /fee|gebühr/i]),
        balance_column: guessColumn(header, [/^balance$/i, /balance|saldo/i]),
        currency_column: guessColumn(header, [
            /^currency$/i,
            /currency|währung/i,
        ]),
        status_column: status,
        status_map: statusMap,
    };
}

function toPayload(values: MappingValues, parse: boolean) {
    const nullable = (value: string) => (value === '' ? null : Number(value));

    return {
        ...values,
        parse,
        account_id: nullable(values.account_id),
        new_account_name:
            values.account_id === '' ? values.new_account_name : '',
        new_account_type:
            values.account_id === '' ? values.new_account_type : '',
        date_column: nullable(values.date_column),
        description_column: nullable(values.description_column),
        amount_column: nullable(values.amount_column),
        debit_column: nullable(values.debit_column),
        credit_column: nullable(values.credit_column),
        thousands_separator:
            values.thousands_separator === ''
                ? null
                : values.thousands_separator,
        fee_column: nullable(values.fee_column),
        balance_column: nullable(values.balance_column),
        currency_column: nullable(values.currency_column),
        status_column: nullable(values.status_column),
        status_map: values.status_column === '' ? null : values.status_map,
        filter_column: nullable(values.filter_column),
        filter_value: values.filter_column === '' ? null : values.filter_value,
    };
}

function Field({
    id,
    label,
    error,
    hint,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    hint?: string;
    children: ReactNode;
}) {
    return (
        <div className="grid content-start gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            {children}
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            <InputError message={error} />
        </div>
    );
}

/**
 * The mapper. With `previewUrl` it reads the uploaded file live as settings change; without one
 * (editing a saved mapping) it only offers the saved header's columns.
 */
export function MappingForm({
    initial,
    savedHeader,
    previewUrl,
    accounts,
    types,
    submitLabel,
    onSubmit,
    lockShape = false,
}: {
    initial: MappingValues;
    savedHeader?: string[];
    previewUrl?: string;
    accounts: { id: number; name: string }[];
    types: AccountTypeOption[];
    submitLabel: string;
    onSubmit: (payload: ReturnType<typeof toPayload>) => void;
    lockShape?: boolean;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [values, setValues] = useState<MappingValues>(initial);
    const [preview, setPreview] = useState<Preview | null>(null);
    const [previewErrors, setPreviewErrors] = useState<Record<string, string>>(
        {},
    );
    const [guessed, setGuessed] = useState(savedHeader !== undefined);
    const header = preview?.header ?? savedHeader ?? [];

    const set = <K extends keyof MappingValues>(
        key: K,
        value: MappingValues[K],
    ) => setValues((current) => ({ ...current, [key]: value }));

    const ready =
        values.date_column !== '' &&
        values.date_format !== '' &&
        values.description_column !== '' &&
        (values.amount_mode === 'signed'
            ? values.amount_column !== ''
            : values.debit_column !== '' && values.credit_column !== '');

    useEffect(() => {
        if (previewUrl === undefined) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            http.getClient()
                .request({
                    method: 'post',
                    url: previewUrl,
                    data: toPayload(values, ready),
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    signal: controller.signal,
                })
                .then((response) => {
                    setPreview(JSON.parse(response.data) as Preview);
                    setPreviewErrors({});
                })
                .catch((error: unknown) => {
                    const response = (
                        error as { response?: { status: number; data: string } }
                    ).response;

                    if (response?.status === 422) {
                        const body = JSON.parse(response.data) as {
                            errors: Record<string, string[]>;
                        };
                        setPreviewErrors(
                            Object.fromEntries(
                                Object.entries(body.errors).map(
                                    ([key, messages]) => [key, messages[0]],
                                ),
                            ),
                        );
                    }
                });
        }, 250);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [previewUrl, values, ready]);

    useEffect(() => {
        if (guessed || preview === null || preview.header.length < 2) {
            return;
        }

        setGuessed(true);
        setValues((current) => ({
            ...current,
            ...guessFromHeader(preview.header, preview.rows, preview.distinct),
        }));
    }, [guessed, preview]);

    const distinct = (column: string): string[] =>
        column === '' ? [] : (preview?.distinct[Number(column)] ?? []);

    const columnSelect = (
        key: keyof MappingValues,
        label: string,
        optional = false,
        hint?: string,
    ) => (
        <Field
            id={`mapping-${key}`}
            label={label}
            error={errors[key] ?? previewErrors[key]}
            hint={hint}
        >
            <NativeSelect
                id={`mapping-${key}`}
                value={values[key] as string}
                onChange={(event) =>
                    set(key, event.target.value as MappingValues[typeof key])
                }
            >
                <option value="">
                    {optional ? 'None' : 'Choose a column'}
                </option>
                {header.map((name, index) => (
                    <option key={index} value={index}>
                        {name || `Column ${index + 1}`}
                    </option>
                ))}
            </NativeSelect>
        </Field>
    );

    const statusValues = distinct(values.status_column);
    const filterValues = distinct(values.filter_column);
    const parsed = preview?.parsed ?? null;

    return (
        <div className="space-y-8">
            <section className="space-y-4" aria-labelledby="mapping-file">
                <h3 id="mapping-file" className="font-medium">
                    1. File shape
                </h3>
                <div className="grid gap-4 sm:grid-cols-3">
                    <Field
                        id="mapping-encoding"
                        label="Encoding"
                        error={errors.encoding ?? previewErrors.encoding}
                    >
                        <NativeSelect
                            id="mapping-encoding"
                            value={values.encoding}
                            disabled={lockShape}
                            onChange={(event) =>
                                set('encoding', event.target.value)
                            }
                        >
                            {['UTF-8', 'Windows-1252', 'ISO-8859-1'].map(
                                (encoding) => (
                                    <option key={encoding}>{encoding}</option>
                                ),
                            )}
                        </NativeSelect>
                    </Field>
                    <Field
                        id="mapping-delimiter"
                        label="Delimiter"
                        error={errors.delimiter ?? previewErrors.delimiter}
                    >
                        <NativeSelect
                            id="mapping-delimiter"
                            value={values.delimiter}
                            disabled={lockShape}
                            onChange={(event) =>
                                set('delimiter', event.target.value)
                            }
                        >
                            {DELIMITERS.map((delimiter) => (
                                <option
                                    key={delimiter.value}
                                    value={delimiter.value}
                                >
                                    {delimiter.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                    <Field
                        id="mapping-skip_lines"
                        label="Lines to skip before the header"
                        error={errors.skip_lines ?? previewErrors.skip_lines}
                    >
                        <Input
                            id="mapping-skip_lines"
                            type="number"
                            min={0}
                            max={50}
                            value={values.skip_lines}
                            disabled={lockShape}
                            onChange={(event) =>
                                set('skip_lines', Number(event.target.value))
                            }
                        />
                    </Field>
                </div>
                {lockShape && (
                    <p className="text-xs text-muted-foreground">
                        The file shape is fixed for a saved mapping. A file with
                        a different shape gets its own mapping.
                    </p>
                )}
                {preview && (
                    <div className="space-y-2">
                        <p className="text-sm text-muted-foreground">
                            Header with {preview.header.length} columns,{' '}
                            {preview.row_count} data rows.
                        </p>
                        <DataTable className="text-xs" data-test="raw-preview">
                            <thead>
                                <tr>
                                    {preview.header.map((name, index) => (
                                        <Th key={index}>{name}</Th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {preview.rows.slice(0, 4).map((row, index) => (
                                    <tr key={index}>
                                        {row.map((cell, cellIndex) => (
                                            <Td
                                                key={cellIndex}
                                                className="max-w-56 truncate"
                                            >
                                                {cell}
                                            </Td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </DataTable>
                    </div>
                )}
            </section>

            <section className="space-y-4" aria-labelledby="mapping-columns">
                <h3 id="mapping-columns" className="font-medium">
                    2. Columns
                </h3>
                <div className="grid gap-4 sm:grid-cols-3">
                    {columnSelect('date_column', 'Date column')}
                    <Field
                        id="mapping-date_format"
                        label="Date format"
                        error={errors.date_format ?? previewErrors.date_format}
                        hint="PHP date format, e.g. d.m.Y"
                    >
                        <Input
                            id="mapping-date_format"
                            list="mapping-date-formats"
                            value={values.date_format}
                            onChange={(event) =>
                                set('date_format', event.target.value)
                            }
                        />
                        <datalist id="mapping-date-formats">
                            {DATE_FORMATS.map((format) => (
                                <option key={format.value} value={format.value}>
                                    {format.example}
                                </option>
                            ))}
                        </datalist>
                    </Field>
                    {columnSelect('description_column', 'Description column')}
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <Field id="mapping-amount_mode" label="Amount">
                        <NativeSelect
                            id="mapping-amount_mode"
                            value={values.amount_mode}
                            onChange={(event) =>
                                set(
                                    'amount_mode',
                                    event.target.value as 'signed' | 'split',
                                )
                            }
                        >
                            <option value="signed">One signed column</option>
                            <option value="split">
                                Separate debit and credit columns
                            </option>
                        </NativeSelect>
                    </Field>
                    {values.amount_mode === 'signed' ? (
                        columnSelect('amount_column', 'Amount column')
                    ) : (
                        <>
                            {columnSelect('debit_column', 'Debit column')}
                            {columnSelect('credit_column', 'Credit column')}
                        </>
                    )}
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <Field
                        id="mapping-decimal_separator"
                        label="Decimal separator"
                    >
                        <NativeSelect
                            id="mapping-decimal_separator"
                            value={values.decimal_separator}
                            onChange={(event) =>
                                set('decimal_separator', event.target.value)
                            }
                        >
                            <option value=".">Point .</option>
                            <option value=",">Comma ,</option>
                        </NativeSelect>
                    </Field>
                    <Field
                        id="mapping-thousands_separator"
                        label="Thousands separator"
                        error={
                            errors.thousands_separator ??
                            previewErrors.thousands_separator
                        }
                    >
                        <NativeSelect
                            id="mapping-thousands_separator"
                            value={values.thousands_separator}
                            onChange={(event) =>
                                set('thousands_separator', event.target.value)
                            }
                        >
                            <option value="">None</option>
                            <option value="'">Apostrophe '</option>
                            <option value=",">Comma ,</option>
                            <option value=".">Point .</option>
                            <option value=" ">Space</option>
                        </NativeSelect>
                    </Field>
                    <div className="flex items-center gap-2 pt-6">
                        <Checkbox
                            id="mapping-invert_sign"
                            checked={values.invert_sign}
                            onCheckedChange={(checked) =>
                                set('invert_sign', checked === true)
                            }
                        />
                        <Label htmlFor="mapping-invert_sign">Invert sign</Label>
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    {columnSelect(
                        'fee_column',
                        'Fee column',
                        true,
                        'Subtracted from the amount and kept.',
                    )}
                    {columnSelect(
                        'balance_column',
                        'Balance column',
                        true,
                        'The last balance becomes a snapshot.',
                    )}
                    {columnSelect(
                        'currency_column',
                        'Currency column',
                        true,
                        'Rows that are not CHF are skipped.',
                    )}
                </div>
            </section>

            <section className="space-y-4" aria-labelledby="mapping-rows">
                <h3 id="mapping-rows" className="font-medium">
                    3. Status and rows
                </h3>
                <div className="grid gap-4 sm:grid-cols-3">
                    {columnSelect('status_column', 'Status column', true)}
                    {values.status_column !== '' &&
                        (statusValues.length > 0
                            ? statusValues
                            : Object.keys(values.status_map)
                        ).map((value) => (
                            <Field
                                key={value}
                                id={`mapping-status-${value}`}
                                label={`Status “${value}” means`}
                                error={errors[`status_map.${value}`]}
                            >
                                <NativeSelect
                                    id={`mapping-status-${value}`}
                                    value={values.status_map[value] ?? ''}
                                    onChange={(event) =>
                                        set('status_map', {
                                            ...values.status_map,
                                            [value]: event.target.value,
                                        })
                                    }
                                >
                                    <option value="">Choose</option>
                                    {STATUSES.map((status) => (
                                        <option key={status} value={status}>
                                            {status}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Field>
                        ))}
                </div>
                <InputError
                    message={errors.status_map ?? previewErrors.status_map}
                />

                <div className="grid gap-4 sm:grid-cols-3">
                    {columnSelect(
                        'filter_column',
                        'Row filter column',
                        true,
                        'Only rows where this column equals the value go to this account.',
                    )}
                    {values.filter_column !== '' && (
                        <Field
                            id="mapping-filter_value"
                            label="Row filter value"
                            error={
                                errors.filter_value ??
                                previewErrors.filter_value
                            }
                        >
                            {filterValues.length > 0 ? (
                                <NativeSelect
                                    id="mapping-filter_value"
                                    value={values.filter_value}
                                    onChange={(event) =>
                                        set('filter_value', event.target.value)
                                    }
                                >
                                    <option value="">Choose a value</option>
                                    {filterValues.map((value) => (
                                        <option key={value} value={value}>
                                            {value}
                                        </option>
                                    ))}
                                </NativeSelect>
                            ) : (
                                <Input
                                    id="mapping-filter_value"
                                    value={values.filter_value}
                                    onChange={(event) =>
                                        set('filter_value', event.target.value)
                                    }
                                />
                            )}
                        </Field>
                    )}
                </div>
            </section>

            {previewUrl !== undefined && (
                <section
                    className="space-y-3"
                    aria-labelledby="mapping-preview"
                >
                    <h3 id="mapping-preview" className="font-medium">
                        4. Preview
                    </h3>
                    {parsed === null ? (
                        <p className="text-sm text-muted-foreground">
                            Choose the date, description and amount columns to
                            see the parsed rows.
                        </p>
                    ) : (
                        <>
                            <p className="text-sm" data-test="parse-summary">
                                {parsed.selected} rows selected ·{' '}
                                {parsed.failed === 0 ? (
                                    'all parse'
                                ) : (
                                    <span className="font-medium text-red-600 dark:text-red-400">
                                        {parsed.failed} fail to parse
                                    </span>
                                )}
                                {Object.entries(parsed.statuses).map(
                                    ([status, count]) =>
                                        ` · ${count} ${status}`,
                                )}
                                {parsed.foreign_currency > 0 &&
                                    ` · ${parsed.foreign_currency} not CHF (skipped)`}
                                {parsed.last_balance !== null &&
                                    ` · last balance ${formatMoney(parsed.last_balance)}`}
                            </p>
                            {parsed.errors.length > 0 && (
                                <ul className="space-y-1 text-sm text-red-600 dark:text-red-400">
                                    {parsed.errors.map((error) => (
                                        <li key={error.line}>
                                            Line {error.line}:{' '}
                                            {error.messages.join('; ')}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <DataTable
                                className="text-xs"
                                data-test="parsed-preview"
                            >
                                <thead>
                                    <tr>
                                        <Th>Line</Th>
                                        <Th>Date</Th>
                                        <Th>Description</Th>
                                        <Th>Status</Th>
                                        <Th className="text-right">Fee</Th>
                                        <Th className="text-right">Amount</Th>
                                        <Th className="text-right">Balance</Th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {parsed.rows.map((row) => (
                                        <tr
                                            key={row.line}
                                            className={
                                                row.errors.length > 0
                                                    ? 'bg-red-50 dark:bg-red-700/10'
                                                    : row.foreign_currency
                                                      ? 'opacity-50'
                                                      : ''
                                            }
                                        >
                                            <Td>{row.line}</Td>
                                            <Td className="whitespace-nowrap">
                                                {row.date} {row.time}
                                            </Td>
                                            <Td>{row.description}</Td>
                                            <Td>
                                                {row.foreign_currency
                                                    ? `${row.currency} (skipped)`
                                                    : row.status}
                                            </Td>
                                            <Td className="text-right tabular-nums">
                                                {row.fee !== 0 &&
                                                    formatMoney(row.fee)}
                                            </Td>
                                            <Td className="text-right tabular-nums">
                                                {formatMoney(row.amount)}
                                            </Td>
                                            <Td className="text-right tabular-nums">
                                                {row.balance !== null &&
                                                    formatMoney(row.balance)}
                                            </Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </DataTable>
                        </>
                    )}
                </section>
            )}

            <section className="space-y-4" aria-labelledby="mapping-save">
                <h3 id="mapping-save" className="font-medium">
                    {previewUrl !== undefined ? '5.' : '4.'} Account
                </h3>
                <div className="grid gap-4 sm:grid-cols-3">
                    <Field
                        id="mapping-name"
                        label="Mapping name"
                        error={errors.name}
                    >
                        <Input
                            id="mapping-name"
                            value={values.name}
                            placeholder="e.g. Revolut Current"
                            onChange={(event) =>
                                set('name', event.target.value)
                            }
                        />
                    </Field>
                    <Field
                        id="mapping-account_id"
                        label="Import into account"
                        error={errors.account_id}
                    >
                        <NativeSelect
                            id="mapping-account_id"
                            value={values.account_id}
                            onChange={(event) =>
                                set('account_id', event.target.value)
                            }
                        >
                            <option value="">New account…</option>
                            {accounts.map((account) => (
                                <option key={account.id} value={account.id}>
                                    {account.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                    {values.account_id === '' && (
                        <>
                            <Field
                                id="mapping-new_account_name"
                                label="New account name"
                                error={errors.new_account_name}
                            >
                                <Input
                                    id="mapping-new_account_name"
                                    value={values.new_account_name}
                                    onChange={(event) =>
                                        set(
                                            'new_account_name',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                id="mapping-new_account_type"
                                label="New account type"
                                error={errors.new_account_type}
                            >
                                <NativeSelect
                                    id="mapping-new_account_type"
                                    value={values.new_account_type}
                                    onChange={(event) =>
                                        set(
                                            'new_account_type',
                                            event.target.value,
                                        )
                                    }
                                >
                                    {types.map((type) => (
                                        <option
                                            key={type.value}
                                            value={type.value}
                                        >
                                            {type.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Field>
                        </>
                    )}
                </div>
                <Button
                    type="button"
                    onClick={() => onSubmit(toPayload(values, true))}
                    disabled={!ready}
                >
                    {submitLabel}
                </Button>
            </section>
        </div>
    );
}

export function submitMapping(
    method: 'post' | 'put',
    url: string,
    payload: ReturnType<typeof toPayload>,
    onSuccess?: () => void,
) {
    router[method](url, payload, {
        preserveScroll: true,
        preserveState: true,
        onSuccess,
    });
}
