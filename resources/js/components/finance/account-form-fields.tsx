import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { AccountTypeOption } from '@/types/finance';
import { NativeSelect } from './native-select';

export function AccountFormFields({
    types,
    errors,
    defaults,
}: {
    types: AccountTypeOption[];
    errors: Partial<Record<string, string>>;
    defaults?: { name: string; type: string };
}) {
    return (
        <>
            <div className="grid gap-2">
                <Label htmlFor="account-name">Name</Label>
                <Input
                    id="account-name"
                    name="name"
                    required
                    defaultValue={defaults?.name}
                    placeholder="e.g. Revolut Current"
                />
                <InputError message={errors.name} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="account-type">Type</Label>
                <NativeSelect
                    id="account-type"
                    name="type"
                    defaultValue={defaults?.type ?? 'checking'}
                >
                    {types.map((type) => (
                        <option key={type.value} value={type.value}>
                            {type.label}
                            {type.importable ? '' : ' (balance only)'}
                        </option>
                    ))}
                </NativeSelect>
                <InputError message={errors.type} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="account-currency">Currency</Label>
                <Input id="account-currency" value="CHF" disabled readOnly />
                <p className="text-xs text-muted-foreground">
                    CHF is the only currency for now.
                </p>
            </div>
        </>
    );
}
