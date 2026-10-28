import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/**
 * A native select styled like the shadcn Input: many small choices (columns, formats) stay fast
 * and keyboard-friendly.
 */
export function NativeSelect({
    className,
    ...props
}: ComponentProps<'select'>) {
    return (
        <select
            className={cn(
                'flex h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive dark:bg-input/30',
                className,
            )}
            {...props}
        />
    );
}
