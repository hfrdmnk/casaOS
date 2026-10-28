import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';

export function Money({
    value,
    signed = false,
    className,
}: {
    value: number;
    signed?: boolean;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'tabular-nums',
                signed && value > 0 && 'text-emerald-600 dark:text-emerald-400',
                className,
            )}
        >
            {formatMoney(value)}
        </span>
    );
}
