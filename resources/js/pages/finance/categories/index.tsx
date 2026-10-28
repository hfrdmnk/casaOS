import { Form, Head, router } from '@inertiajs/react';
import { useState } from 'react';
import CategoryController from '@/actions/App/Http/Controllers/Finance/CategoryController';
import { DataTable, Td, Th } from '@/components/finance/data-table';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/finance/categories';

type CategoryRow = { id: number; name: string; transactions_count: number };

export default function Categories({
    categories,
}: {
    categories: CategoryRow[];
}) {
    const [editing, setEditing] = useState<number | null>(null);

    return (
        <>
            <Head title="Categories" />
            <div className="max-w-3xl space-y-6 p-4">
                <Heading
                    title="Categories"
                    description="One category per transaction. Deleting a category leaves its transactions uncategorized."
                />

                <Form
                    {...CategoryController.store.form()}
                    resetOnSuccess
                    options={{ preserveScroll: true }}
                    className="flex items-end gap-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid flex-1 gap-2">
                                <Label htmlFor="new-category">
                                    New category
                                </Label>
                                <Input
                                    id="new-category"
                                    name="name"
                                    required
                                    placeholder="e.g. Travel"
                                />
                                <InputError message={errors.name} />
                            </div>
                            <Button disabled={processing}>Add category</Button>
                        </>
                    )}
                </Form>

                <DataTable>
                    <thead>
                        <tr>
                            <Th>Name</Th>
                            <Th className="text-right">Transactions</Th>
                            <Th />
                        </tr>
                    </thead>
                    <tbody>
                        {categories.map((category) => (
                            <tr key={category.id}>
                                <Td>
                                    {editing === category.id ? (
                                        <Form
                                            {...CategoryController.update.form(
                                                category.id,
                                            )}
                                            options={{ preserveScroll: true }}
                                            onSuccess={() => setEditing(null)}
                                            className="flex items-start gap-2"
                                        >
                                            {({ processing, errors }) => (
                                                <>
                                                    <div className="grid flex-1 gap-1">
                                                        <Input
                                                            name="name"
                                                            aria-label="Category name"
                                                            defaultValue={
                                                                category.name
                                                            }
                                                            autoFocus
                                                            required
                                                        />
                                                        <InputError
                                                            message={
                                                                errors.name
                                                            }
                                                        />
                                                    </div>
                                                    <Button
                                                        size="sm"
                                                        disabled={processing}
                                                    >
                                                        Save
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        type="button"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            setEditing(null)
                                                        }
                                                    >
                                                        Cancel
                                                    </Button>
                                                </>
                                            )}
                                        </Form>
                                    ) : (
                                        category.name
                                    )}
                                </Td>
                                <Td className="text-right tabular-nums">
                                    {category.transactions_count}
                                </Td>
                                <Td className="text-right whitespace-nowrap">
                                    {editing !== category.id && (
                                        <>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    setEditing(category.id)
                                                }
                                            >
                                                Rename
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => {
                                                    if (
                                                        window.confirm(
                                                            `Delete ${category.name}? Its transactions become uncategorized.`,
                                                        )
                                                    ) {
                                                        router.delete(
                                                            CategoryController.destroy.url(
                                                                category.id,
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
                                        </>
                                    )}
                                </Td>
                            </tr>
                        ))}
                    </tbody>
                </DataTable>
            </div>
        </>
    );
}

Categories.layout = {
    breadcrumbs: [{ title: 'Categories', href: index() }],
};
