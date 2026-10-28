import { Badge } from '@/components/ui/badge';

export function StatusBadge({ status }: { status: string }) {
    if (status === 'completed') {
        return null;
    }

    return <Badge variant="outline">{status}</Badge>;
}
