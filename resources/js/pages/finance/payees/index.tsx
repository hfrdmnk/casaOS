import { Form, Head, router } from '@inertiajs/react';
import { useState } from 'react';
import PayeeController from '@/actions/App/Http/Controllers/Finance/PayeeController';
import { DataTable, Td, Th } from '@/components/finance/data-table';
import { NativeSelect } from '@/components/finance/native-select';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/finance/payees';

type PayeeRow = { id: number; name: string; transactions_count: number };

export default function Payees({ payees }: { payees: PayeeRow[] }) {
    const [editing, setEditing] = useState<{
        id: number;
        mode: 'rename' | 'merge';
    } | null>(null);
    const [into, setInto] = useState('');

    return (
        <>
            <Head title="Payees" />
            <div className="max-w-3xl space-y-6 p-4">
                <Heading
                    title="Payees"
                    description="Clean merchant names. Rules assign them; the transaction list shows the payee instead of the raw description."
                />

                <Form
                    {...PayeeController.store.form()}
                    resetOnSuccess
                    options={{ preserveScroll: true }}
                    className="flex items-end gap-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid flex-1 gap-2">
                                <Label htmlFor="new-payee">New payee</Label>
                                <Input
                                    id="new-payee"
                                    name="name"
                                    required
                                    placeholder="e.g. Coop"
                                />
                                <InputError message={errors.name} />
                            </div>
                            <Button disabled={processing}>Add payee</Button>
                        </>
                    )}
                </Form>

                {payees.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No payees yet.
                    </p>
                ) : (
                    <DataTable>
                        <thead>
                            <tr>
                                <Th>Name</Th>
                                <Th className="text-right">Transactions</Th>
                                <Th />
                            </tr>
                        </thead>
                        <tbody>
                            {payees.map((payee) => (
                                <tr key={payee.id}>
                                    <Td>
                                        {editing?.id === payee.id &&
                                        editing.mode === 'rename' ? (
                                            <Form
                                                {...PayeeController.update.form(
                                                    payee.id,
                                                )}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                                onSuccess={() =>
                                                    setEditing(null)
                                                }
                                                className="flex items-start gap-2"
                                            >
                                                {({ processing, errors }) => (
                                                    <>
                                                        <div className="grid flex-1 gap-1">
                                                            <Input
                                                                name="name"
                                                                aria-label="Payee name"
                                                                defaultValue={
                                                                    payee.name
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
                                                            disabled={
                                                                processing
                                                            }
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
                                        ) : editing?.id === payee.id &&
                                          editing.mode === 'merge' ? (
                                            <div className="flex items-center gap-2">
                                                <span className="whitespace-nowrap">
                                                    Merge {payee.name} into
                                                </span>
                                                <NativeSelect
                                                    aria-label={`Merge ${payee.name} into`}
                                                    className="h-8"
                                                    value={into}
                                                    onChange={(event) =>
                                                        setInto(
                                                            event.target.value,
                                                        )
                                                    }
                                                >
                                                    <option value="">
                                                        Choose…
                                                    </option>
                                                    {payees
                                                        .filter(
                                                            (other) =>
                                                                other.id !==
                                                                payee.id,
                                                        )
                                                        .map((other) => (
                                                            <option
                                                                key={other.id}
                                                                value={other.id}
                                                            >
                                                                {other.name}
                                                            </option>
                                                        ))}
                                                </NativeSelect>
                                                <Button
                                                    size="sm"
                                                    disabled={into === ''}
                                                    onClick={() =>
                                                        router.post(
                                                            PayeeController.merge.url(
                                                                payee.id,
                                                            ),
                                                            {
                                                                into: Number(
                                                                    into,
                                                                ),
                                                            },
                                                            {
                                                                preserveScroll: true,
                                                                onSuccess: () =>
                                                                    setEditing(
                                                                        null,
                                                                    ),
                                                            },
                                                        )
                                                    }
                                                >
                                                    Merge
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        setEditing(null)
                                                    }
                                                >
                                                    Cancel
                                                </Button>
                                            </div>
                                        ) : (
                                            payee.name
                                        )}
                                    </Td>
                                    <Td className="text-right tabular-nums">
                                        {payee.transactions_count}
                                    </Td>
                                    <Td className="text-right whitespace-nowrap">
                                        {editing?.id !== payee.id && (
                                            <>
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        setEditing({
                                                            id: payee.id,
                                                            mode: 'rename',
                                                        })
                                                    }
                                                >
                                                    Rename
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    disabled={payees.length < 2}
                                                    onClick={() => {
                                                        setInto('');
                                                        setEditing({
                                                            id: payee.id,
                                                            mode: 'merge',
                                                        });
                                                    }}
                                                >
                                                    Merge…
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() => {
                                                        if (
                                                            window.confirm(
                                                                `Delete ${payee.name}? Its transactions lose their payee.`,
                                                            )
                                                        ) {
                                                            router.delete(
                                                                PayeeController.destroy.url(
                                                                    payee.id,
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
                )}
            </div>
        </>
    );
}

Payees.layout = {
    breadcrumbs: [{ title: 'Payees', href: index() }],
};
