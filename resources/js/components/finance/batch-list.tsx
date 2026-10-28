import { Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { show } from '@/routes/finance/batches';
import type { Batch } from '@/types/finance';
import { DataTable, Td, Th } from './data-table';

export function BatchList({ batches }: { batches: Batch[] }) {
    if (batches.length === 0) {
        return <p className="text-sm text-muted-foreground">No imports yet.</p>;
    }

    return (
        <DataTable>
            <thead>
                <tr>
                    <Th>Imported</Th>
                    <Th>File</Th>
                    <Th className="text-right">Created</Th>
                    <Th className="text-right">Updated</Th>
                    <Th className="text-right">Duplicates</Th>
                    <Th className="text-right">Not CHF</Th>
                    <Th className="text-right">Reverted</Th>
                </tr>
            </thead>
            <tbody>
                {batches.map((batch) => (
                    <tr
                        key={batch.id}
                        className={batch.undone_at ? 'opacity-60' : ''}
                    >
                        <Td className="whitespace-nowrap text-muted-foreground">
                            {new Date(batch.created_at).toLocaleString('de-CH')}
                        </Td>
                        <Td>
                            <Link
                                href={show(batch.id)}
                                className="font-medium hover:underline"
                            >
                                {batch.filename}
                            </Link>
                            {batch.undone_at && (
                                <Badge variant="secondary" className="ml-2">
                                    Undone
                                </Badge>
                            )}
                        </Td>
                        <Td className="text-right tabular-nums">
                            {batch.summary.created}
                        </Td>
                        <Td className="text-right tabular-nums">
                            {batch.summary.updated}
                        </Td>
                        <Td className="text-right tabular-nums">
                            {batch.summary.skipped_duplicate}
                        </Td>
                        <Td className="text-right tabular-nums">
                            {batch.summary.skipped_currency}
                        </Td>
                        <Td className="text-right tabular-nums">
                            {batch.summary.removed_reverted}
                        </Td>
                    </tr>
                ))}
            </tbody>
        </DataTable>
    );
}
