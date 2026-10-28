import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

export function DataTable({ className, ...props }: ComponentProps<'table'>) {
    return (
        <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
            <table
                className={cn('w-full text-left text-sm', className)}
                {...props}
            />
        </div>
    );
}

export function Th({ className, ...props }: ComponentProps<'th'>) {
    return (
        <th
            className={cn(
                'border-b bg-muted/40 px-3 py-2 text-xs font-medium whitespace-nowrap text-muted-foreground',
                className,
            )}
            {...props}
        />
    );
}

export function Td({ className, ...props }: ComponentProps<'td'>) {
    return (
        <td
            className={cn('border-b px-3 py-2 align-middle', className)}
            {...props}
        />
    );
}
