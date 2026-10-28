import { Head, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2 } from 'lucide-react';
import ImportController from '@/actions/App/Http/Controllers/Finance/ImportController';
import ImportMappingController from '@/actions/App/Http/Controllers/Finance/ImportMappingController';
import { MappingForm, submitMapping } from '@/components/finance/mapping-form';
import type { MappingValues } from '@/components/finance/mapping-form';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/finance/import';
import type { AccountTypeOption, MappingSummary } from '@/types/finance';

type Rejection = {
    message: string;
    errors: { line: number; mapping: string; message: string }[];
    error_count: number;
};

export default function ImportShow({
    upload,
    detected,
    lines,
    matching,
    accounts,
    types,
    rejection,
}: {
    upload: { id: string; filename: string };
    detected: { encoding: string; delimiter: string; skip_lines: number };
    lines: string[];
    matching: MappingSummary[];
    accounts: { id: number; name: string }[];
    types: AccountTypeOption[];
    rejection: Rejection | null;
}) {
    const initial: MappingValues = {
        name: '',
        account_id: '',
        new_account_name: '',
        new_account_type: types[0]?.value ?? 'checking',
        ...detected,
        date_column: '',
        date_format: '',
        description_column: '',
        amount_mode: 'signed',
        amount_column: '',
        debit_column: '',
        credit_column: '',
        invert_sign: false,
        decimal_separator: '.',
        thousands_separator: '',
        fee_column: '',
        balance_column: '',
        currency_column: '',
        status_column: '',
        status_map: {},
        filter_column: '',
        filter_value: '',
    };

    return (
        <>
            <Head title={`Map ${upload.filename}`} />
            <div className="space-y-8 p-4">
                <Heading
                    title={`Map ${upload.filename}`}
                    description={
                        matching.length === 0
                            ? "No saved mapping matched this file's shape. Map its columns to an account; for a file that holds several accounts, save one mapping per account with a row filter."
                            : 'Saved mappings match this file. Import it, or add a mapping for another account in the same file.'
                    }
                />

                {rejection && (
                    <div
                        role="alert"
                        className="space-y-2 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-200/10 dark:bg-red-700/10 dark:text-red-100"
                    >
                        <p className="flex items-center gap-2 font-medium">
                            <AlertTriangle className="size-4" />
                            {rejection.message}
                        </p>
                        <ul className="max-h-64 space-y-0.5 overflow-y-auto">
                            {rejection.errors.map((error, position) => (
                                <li key={position}>
                                    Line {error.line}
                                    {error.mapping &&
                                        ` (${error.mapping})`}:{' '}
                                    {error.message}
                                </li>
                            ))}
                        </ul>
                        {rejection.error_count > rejection.errors.length && (
                            <p>
                                …and{' '}
                                {rejection.error_count -
                                    rejection.errors.length}{' '}
                                more.
                            </p>
                        )}
                    </div>
                )}

                {matching.length > 0 && (
                    <div className="space-y-3 rounded-xl border p-4">
                        <p className="flex items-center gap-2 text-sm font-medium">
                            <CheckCircle2 className="size-4 text-emerald-600" />
                            {matching.length === 1
                                ? '1 saved mapping matches this file:'
                                : `${matching.length} saved mappings match this file:`}
                        </p>
                        <ul className="list-inside list-disc text-sm">
                            {matching.map((mapping) => (
                                <li key={mapping.id}>
                                    {mapping.name} → {mapping.account}
                                    {mapping.filter &&
                                        ` (${mapping.filter.column} = ${mapping.filter.value})`}
                                </li>
                            ))}
                        </ul>
                        <p className="text-sm text-muted-foreground">
                            Add another mapping below, or import the file now.
                        </p>
                        <Button
                            onClick={() =>
                                router.post(
                                    ImportController.commit.url(upload.id),
                                )
                            }
                        >
                            Import now
                        </Button>
                    </div>
                )}

                <details className="rounded-xl border p-4 text-xs">
                    <summary className="cursor-pointer text-sm font-medium">
                        First lines of the file
                    </summary>
                    <pre className="mt-2 overflow-x-auto">
                        {lines.join('\n')}
                    </pre>
                </details>

                <MappingForm
                    initial={initial}
                    previewUrl={ImportController.preview.url(upload.id)}
                    accounts={accounts}
                    types={types}
                    submitLabel="Save mapping"
                    onSubmit={(payload) =>
                        submitMapping(
                            'post',
                            ImportMappingController.store.url(upload.id),
                            payload,
                        )
                    }
                />
            </div>
        </>
    );
}

ImportShow.layout = {
    breadcrumbs: [{ title: 'Import', href: index() }],
};
