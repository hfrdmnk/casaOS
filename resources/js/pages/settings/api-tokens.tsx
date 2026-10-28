import { Form, Head, router, usePage } from '@inertiajs/react';
import { Check, Copy, KeyRound, Trash2 } from 'lucide-react';
import { useState } from 'react';
import ApiTokenController from '@/actions/App/Http/Controllers/Settings/ApiTokenController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useClipboard } from '@/hooks/use-clipboard';
import { index } from '@/routes/api-tokens';

type ApiToken = {
    id: number;
    name: string;
    access: 'read' | 'write';
    created_at_diff: string | null;
    last_used_at_diff: string | null;
};

type NewToken = {
    name: string;
    plain_text: string;
};

type Props = {
    tokens: ApiToken[];
    mcpUrl: string;
};

const accessOptions = [
    {
        value: 'read',
        label: 'Read only',
        description:
            'List accounts, balances, transactions, rules and previews. Cannot change anything.',
    },
    {
        value: 'write',
        label: 'Read and write',
        description:
            'Also categorize, create and apply rules, pair transfers, import and undo imports.',
    },
] as const;

function CopyField({ label, value }: { label: string; value: string }) {
    const [copiedText, copy] = useClipboard();
    const Icon = copiedText === value ? Check : Copy;

    return (
        <div className="flex items-stretch overflow-hidden rounded-md border border-border">
            <pre
                aria-label={label}
                className="flex-1 bg-muted/50 p-3 font-mono text-xs break-all whitespace-pre-wrap"
            >
                {value}
            </pre>
            <button
                type="button"
                onClick={() => copy(value)}
                className="border-l border-border px-3 hover:bg-muted"
            >
                <Icon className="size-4" />
                <span className="sr-only">Copy {label}</span>
            </button>
        </div>
    );
}

function ConnectionSnippets({
    mcpUrl,
    token,
}: {
    mcpUrl: string;
    token: string;
}) {
    const claude = `claude mcp add --transport http casaos-finance ${mcpUrl} \\\n  --header "Authorization: Bearer ${token}"`;
    const amp = JSON.stringify(
        {
            'amp.mcpServers': {
                'casaos-finance': {
                    url: mcpUrl,
                    headers: { Authorization: `Bearer ${token}` },
                },
            },
        },
        null,
        4,
    );

    return (
        <div className="space-y-4">
            <div className="space-y-2">
                <p className="text-sm font-medium">Claude Code</p>
                <CopyField label="Claude Code command" value={claude} />
            </div>
            <div className="space-y-2">
                <p className="text-sm font-medium">Amp (settings.json)</p>
                <CopyField label="Amp settings" value={amp} />
            </div>
        </div>
    );
}

function TokenItem({ token }: { token: ApiToken }) {
    const [revoking, setRevoking] = useState(false);

    const revoke = () => {
        setRevoking(true);
        router.delete(ApiTokenController.destroy.url(token.id), {
            preserveScroll: true,
            onFinish: () => setRevoking(false),
        });
    };

    return (
        <div
            className="flex items-center justify-between gap-4 border-b p-4 last:border-b-0"
            data-test="api-token"
        >
            <div className="flex items-center gap-4">
                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-muted">
                    <KeyRound className="h-5 w-5 text-muted-foreground" />
                </div>
                <div className="space-y-1">
                    <div className="flex items-center gap-2.5">
                        <p className="font-medium tracking-tight">
                            {token.name}
                        </p>
                        <Badge variant="secondary">
                            {token.access === 'write'
                                ? 'Read and write'
                                : 'Read only'}
                        </Badge>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Created {token.created_at_diff}
                        <span className="mx-1 text-muted-foreground/50">/</span>
                        {token.last_used_at_diff
                            ? `Last used ${token.last_used_at_diff}`
                            : 'Never used'}
                    </p>
                </div>
            </div>

            <Dialog>
                <DialogTrigger asChild>
                    <Button
                        variant="ghost"
                        size="sm"
                        className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                    >
                        <Trash2 className="h-4 w-4" />
                        <span className="sr-only">Revoke {token.name}</span>
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogTitle>Revoke token</DialogTitle>
                    <DialogDescription>
                        Revoke "{token.name}"? Agents using it lose access at
                        once and get 401 from then on. This cannot be undone.
                    </DialogDescription>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="secondary">Cancel</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            onClick={revoke}
                            disabled={revoking}
                        >
                            {revoking ? 'Revoking...' : 'Revoke token'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

export default function ApiTokens({ tokens, mcpUrl }: Props) {
    const newToken = usePage().flash.token as NewToken | undefined;

    return (
        <>
            <Head title="API tokens" />

            <h1 className="sr-only">API tokens</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Create API token"
                    description="Give your own AI agent (Amp, Claude Code) access to your finances over MCP"
                />

                <Form
                    {...ApiTokenController.store.form()}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    className="space-y-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Token name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    maxLength={100}
                                    placeholder="Claude Code on laptop"
                                />
                                <InputError message={errors.name} />
                            </div>

                            <fieldset className="grid gap-3">
                                <legend className="mb-2 text-sm font-medium">
                                    Access
                                </legend>
                                {accessOptions.map((option) => (
                                    <label
                                        key={option.value}
                                        className="flex cursor-pointer items-start gap-3 rounded-md border border-border p-3 has-checked:border-primary has-checked:bg-muted/50"
                                    >
                                        <input
                                            type="radio"
                                            name="access"
                                            value={option.value}
                                            defaultChecked={
                                                option.value === 'read'
                                            }
                                            className="mt-1 accent-primary"
                                        />
                                        <span className="space-y-0.5">
                                            <span className="block text-sm font-medium">
                                                {option.label}
                                            </span>
                                            <span className="block text-sm text-muted-foreground">
                                                {option.description}
                                            </span>
                                        </span>
                                    </label>
                                ))}
                                <InputError message={errors.access} />
                            </fieldset>

                            <Button
                                disabled={processing}
                                data-test="create-token-button"
                            >
                                Create token
                            </Button>
                        </>
                    )}
                </Form>
            </div>

            {newToken && (
                <div
                    className="space-y-4 rounded-lg border border-green-600/40 bg-green-50 p-4 dark:bg-green-950/30"
                    data-test="new-token"
                >
                    <div className="space-y-1">
                        <p className="font-medium">
                            Token "{newToken.name}" created
                        </p>
                        <p className="text-sm text-muted-foreground">
                            Copy it now. It is shown only once and cannot be
                            recovered; create a new token if you lose it.
                        </p>
                    </div>
                    <CopyField label="New token" value={newToken.plain_text} />
                    <ConnectionSnippets
                        mcpUrl={mcpUrl}
                        token={newToken.plain_text}
                    />
                </div>
            )}

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Tokens"
                    description="Revoke a token you no longer use or that may have leaked"
                />

                <div className="overflow-hidden rounded-lg border border-border">
                    {tokens.length > 0 ? (
                        tokens.map((token) => (
                            <TokenItem key={token.id} token={token} />
                        ))
                    ) : (
                        <div className="p-8 text-center">
                            <p className="font-medium">No tokens yet</p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Create one above to connect an agent
                            </p>
                        </div>
                    )}
                </div>
            </div>

            {!newToken && (
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Connect an agent"
                        description={`The finance MCP server is at ${mcpUrl}. Replace <token> with a token you created.`}
                    />
                    <ConnectionSnippets mcpUrl={mcpUrl} token="<token>" />
                </div>
            )}
        </>
    );
}

ApiTokens.layout = {
    breadcrumbs: [
        {
            title: 'API tokens',
            href: index(),
        },
    ],
};
