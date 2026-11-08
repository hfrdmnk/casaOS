import type { ReactNode } from 'react';

/**
 * The default variant is the page header: the title and description read as
 * one sentence in the dashboard's lede style, with any actions below it. The
 * small variant titles a section, like the dashboard's section headings.
 */
export default function Heading({
    title,
    description,
    variant = 'default',
    children,
}: {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
    children?: ReactNode;
}) {
    if (variant === 'small') {
        return (
            <header className="space-y-1">
                <h2 className="text-[13px] font-medium">{title}</h2>
                {description && (
                    <p className="text-[13px] text-pretty text-faint">
                        {description}
                    </p>
                )}
            </header>
        );
    }

    return (
        <header className="space-y-8">
            <div className="max-w-[568px] text-2xl leading-[1.42] tracking-[-0.018em] text-pretty text-faint">
                <h1 className="inline font-normal text-foreground">{title}.</h1>
                {description && <> {description}</>}
            </div>
            {children && (
                <div className="flex flex-wrap items-center gap-2">
                    {children}
                </div>
            )}
        </header>
    );
}
