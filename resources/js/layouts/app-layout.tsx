import { AppHeader } from '@/components/app-header';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { cn } from '@/lib/utils';
import type { AppLayoutProps } from '@/types';

export default function AppLayout({
    breadcrumbs = [],
    wide = false,
    children,
}: AppLayoutProps) {
    return (
        <div className="flex min-h-dvh w-full flex-col">
            <AppHeader />
            <main
                className={cn(
                    'mx-auto flex w-full flex-1 flex-col px-6 pt-12 pb-32 sm:px-8 md:pt-24',
                    wide ? 'max-w-6xl' : 'max-w-[632px]',
                )}
            >
                {breadcrumbs.length > 1 && (
                    <div className="mb-3.5 text-[13px]">
                        <Breadcrumbs breadcrumbs={breadcrumbs} />
                    </div>
                )}
                {children}
            </main>
        </div>
    );
}
