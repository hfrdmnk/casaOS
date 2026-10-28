import { Head, router } from '@inertiajs/react';
import ImportMappingController from '@/actions/App/Http/Controllers/Finance/ImportMappingController';
import { MappingForm, submitMapping } from '@/components/finance/mapping-form';
import type { MappingValues } from '@/components/finance/mapping-form';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/finance/import';
import type { AccountTypeOption } from '@/types/finance';

type SavedMapping = {
    id: number;
    account_id: number;
    name: string;
    encoding: string;
    delimiter: string;
    skip_lines: number;
    header: string[];
    date_column: number;
    date_format: string;
    description_column: number;
    amount_mode: 'signed' | 'split';
    amount_column: number | null;
    debit_column: number | null;
    credit_column: number | null;
    invert_sign: boolean;
    decimal_separator: string;
    thousands_separator: string | null;
    fee_column: number | null;
    balance_column: number | null;
    currency_column: number | null;
    status_column: number | null;
    status_map: Record<string, string> | null;
    filter_column: number | null;
    filter_value: string | null;
};

const column = (value: number | null) => (value === null ? '' : String(value));

export default function EditMapping({
    mapping,
    accounts,
    types,
}: {
    mapping: SavedMapping;
    accounts: { id: number; name: string }[];
    types: AccountTypeOption[];
}) {
    const initial: MappingValues = {
        name: mapping.name,
        account_id: String(mapping.account_id),
        new_account_name: '',
        new_account_type: types[0]?.value ?? 'checking',
        encoding: mapping.encoding,
        delimiter: mapping.delimiter,
        skip_lines: mapping.skip_lines,
        date_column: column(mapping.date_column),
        date_format: mapping.date_format,
        description_column: column(mapping.description_column),
        amount_mode: mapping.amount_mode,
        amount_column: column(mapping.amount_column),
        debit_column: column(mapping.debit_column),
        credit_column: column(mapping.credit_column),
        invert_sign: mapping.invert_sign,
        decimal_separator: mapping.decimal_separator,
        thousands_separator: mapping.thousands_separator ?? '',
        fee_column: column(mapping.fee_column),
        balance_column: column(mapping.balance_column),
        currency_column: column(mapping.currency_column),
        status_column: column(mapping.status_column),
        status_map: mapping.status_map ?? {},
        filter_column: column(mapping.filter_column),
        filter_value: mapping.filter_value ?? '',
    };

    return (
        <>
            <Head title={`Edit mapping ${mapping.name}`} />
            <div className="space-y-8 p-4">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        title={`Edit mapping ${mapping.name}`}
                        description="Changes apply to the next import; transactions already imported stay as they are."
                    />
                    <Button
                        variant="destructive"
                        onClick={() => {
                            if (
                                window.confirm(
                                    `Delete the mapping ${mapping.name}? Its transactions stay.`,
                                )
                            ) {
                                router.delete(
                                    ImportMappingController.destroy.url(
                                        mapping.id,
                                    ),
                                );
                            }
                        }}
                    >
                        Delete mapping
                    </Button>
                </div>
                <MappingForm
                    initial={initial}
                    savedHeader={mapping.header}
                    accounts={accounts}
                    types={types}
                    lockShape
                    submitLabel="Save changes"
                    onSubmit={(payload) =>
                        submitMapping(
                            'put',
                            ImportMappingController.update.url(mapping.id),
                            payload,
                        )
                    }
                />
            </div>
        </>
    );
}

EditMapping.layout = {
    breadcrumbs: [{ title: 'Import', href: index() }],
};
