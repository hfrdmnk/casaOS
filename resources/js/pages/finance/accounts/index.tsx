import { Form, Head, Link } from '@inertiajs/react';
import { AlertTriangle, Plus } from 'lucide-react';
import AccountController from '@/actions/App/Http/Controllers/Finance/AccountController';
import { AccountFormFields } from '@/components/finance/account-form-fields';
import { DataTable, Td, Th } from '@/components/finance/data-table';
import { Money } from '@/components/finance/money';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { index, show } from '@/routes/finance/accounts';
import type { AccountSummary, AccountTypeOption } from '@/types/finance';

export default function Accounts({
    accounts,
    types,
}: {
    accounts: AccountSummary[];
    types: AccountTypeOption[];
}) {
    return (
        <>
            <Head title="Accounts" />
            <div className="space-y-6 p-4">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        title="Accounts"
                        description="Balances today, from your snapshots and transactions"
                    />
                    <Dialog>
                        <DialogTrigger asChild>
                            <Button>
                                <Plus /> New account
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogTitle>New account</DialogTitle>
                            <DialogDescription>
                                Investment and crypto accounts track balance
                                snapshots only.
                            </DialogDescription>
                            <Form
                                {...AccountController.store.form()}
                                className="space-y-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <AccountFormFields
                                            types={types}
                                            errors={errors}
                                        />
                                        <DialogFooter className="gap-2">
                                            <DialogClose asChild>
                                                <Button
                                                    type="button"
                                                    variant="secondary"
                                                >
                                                    Cancel
                                                </Button>
                                            </DialogClose>
                                            <Button disabled={processing}>
                                                Create account
                                            </Button>
                                        </DialogFooter>
                                    </>
                                )}
                            </Form>
                        </DialogContent>
                    </Dialog>
                </div>

                {accounts.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                        No accounts yet. Create one, or import a bank file and
                        create it in the mapper.
                    </p>
                ) : (
                    <DataTable>
                        <thead>
                            <tr>
                                <Th>Account</Th>
                                <Th>Type</Th>
                                <Th className="text-right">Transactions</Th>
                                <Th>Last snapshot</Th>
                                <Th className="text-right">Balance</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {accounts.map((account) => (
                                <tr
                                    key={account.id}
                                    className={
                                        account.archived ? 'opacity-60' : ''
                                    }
                                >
                                    <Td className="font-medium">
                                        <Link
                                            href={show(account.id)}
                                            className="hover:underline"
                                        >
                                            {account.name}
                                        </Link>
                                        {account.archived && (
                                            <Badge
                                                variant="secondary"
                                                className="ml-2"
                                            >
                                                Archived
                                            </Badge>
                                        )}
                                        {account.mismatched_snapshots > 0 && (
                                            <Badge
                                                variant="destructive"
                                                className="ml-2"
                                            >
                                                <AlertTriangle />
                                                Balance differs
                                            </Badge>
                                        )}
                                    </Td>
                                    <Td>{account.type_label}</Td>
                                    <Td className="text-right tabular-nums">
                                        {account.importable
                                            ? account.transactions_count
                                            : '—'}
                                    </Td>
                                    <Td className="text-muted-foreground">
                                        {account.latest_snapshot?.date ?? '—'}
                                    </Td>
                                    <Td className="text-right font-medium">
                                        <Money value={account.balance} />
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

Accounts.layout = {
    breadcrumbs: [{ title: 'Accounts', href: index() }],
};
