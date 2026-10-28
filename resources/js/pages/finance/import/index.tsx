import { Form, Head, Link } from '@inertiajs/react';
import { Upload } from 'lucide-react';
import ImportController from '@/actions/App/Http/Controllers/Finance/ImportController';
import { BatchList } from '@/components/finance/batch-list';
import { DataTable, Td, Th } from '@/components/finance/data-table';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/finance/import';
import { edit } from '@/routes/finance/mappings';
import type { Batch, MappingSummary } from '@/types/finance';

export default function ImportIndex({
    mappings,
    batches,
}: {
    mappings: MappingSummary[];
    batches: Batch[];
}) {
    return (
        <>
            <Head title="Import" />
            <div className="space-y-8 p-4">
                <Heading
                    title="Import"
                    description="Upload a bank CSV. A file whose shape matches saved mappings imports straight away; any other file opens the mapper."
                />

                <Form
                    {...ImportController.store.form()}
                    className="flex flex-wrap items-end gap-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="import-file">CSV file</Label>
                                <Input
                                    id="import-file"
                                    type="file"
                                    name="file"
                                    accept=".csv,.txt,text/csv"
                                    required
                                />
                                <InputError message={errors.file} />
                            </div>
                            <Button disabled={processing}>
                                <Upload /> Upload
                            </Button>
                        </>
                    )}
                </Form>

                <section className="space-y-4">
                    <Heading
                        variant="small"
                        title="Saved mappings"
                        description="Every mapping matching a file's header imports the rows its filter selects."
                    />
                    {mappings.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            None yet. Upload a file to create one.
                        </p>
                    ) : (
                        <DataTable>
                            <thead>
                                <tr>
                                    <Th>Mapping</Th>
                                    <Th>Account</Th>
                                    <Th>Row filter</Th>
                                    <Th>Header</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {mappings.map((mapping) => (
                                    <tr key={mapping.id}>
                                        <Td className="font-medium">
                                            <Link
                                                href={edit(mapping.id)}
                                                className="hover:underline"
                                            >
                                                {mapping.name}
                                            </Link>
                                        </Td>
                                        <Td>{mapping.account}</Td>
                                        <Td className="text-muted-foreground">
                                            {mapping.filter
                                                ? `${mapping.filter.column} = ${mapping.filter.value}`
                                                : 'all rows'}
                                        </Td>
                                        <Td className="max-w-96 truncate text-xs text-muted-foreground">
                                            {mapping.header}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </DataTable>
                    )}
                </section>

                <section className="space-y-4">
                    <Heading variant="small" title="Recent imports" />
                    <BatchList batches={batches} />
                </section>
            </div>
        </>
    );
}

ImportIndex.layout = {
    breadcrumbs: [{ title: 'Import', href: index() }],
};
