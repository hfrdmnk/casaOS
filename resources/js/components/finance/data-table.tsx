import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

export function DataTable({ className, ...props }: ComponentProps<'table'>) {
    return (
        <div className="-mx-3 overflow-x-auto">
            <table
                className={cn(
                    'w-full text-left text-sm [&_tbody_tr]:transition-colors [&_tbody_tr]:duration-150 [&_tbody_tr]:hover:bg-accent',
                    className,
                )}
                {...props}
            />
        </div>
    );
}

export function Th({ className, ...props }: ComponentProps<'th'>) {
    return (
        <th
            className={cn(
                'border-b px-3 pt-0 pb-2 text-xs font-normal whitespace-nowrap text-faint',
                className,
            )}
            {...props}
        />
    );
}

export function Td({ className, ...props }: ComponentProps<'td'>) {
    return (
        <td
            className={cn('border-b px-3 py-2.5 align-middle', className)}
            {...props}
        />
    );
}
