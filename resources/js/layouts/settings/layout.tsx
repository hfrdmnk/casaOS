import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { toUrl } from '@/lib/utils';
import { index as apiTokens } from '@/routes/api-tokens';
import { edit as editAppearance } from '@/routes/appearance';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Profile',
        href: edit(),
        icon: null,
    },
    {
        title: 'Security',
        href: editSecurity(),
        icon: null,
    },
    {
        title: 'Appearance',
        href: editAppearance(),
        icon: null,
    },
    {
        title: 'API tokens',
        href: apiTokens(),
        icon: null,
    },
];

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <div className="space-y-16">
            <Heading
                title="Settings"
                description="Your profile, sign-in security, appearance and API tokens."
            >
                <nav
                    className="-mx-2.5 flex flex-wrap gap-0.5"
                    aria-label="Settings"
                >
                    {sidebarNavItems.map((item, index) => (
                        <Link
                            key={`${toUrl(item.href)}-${index}`}
                            href={item.href}
                            data-current={
                                isCurrentOrParentUrl(item.href) || undefined
                            }
                            aria-current={
                                isCurrentOrParentUrl(item.href)
                                    ? 'page'
                                    : undefined
                            }
                            className="flex h-8 items-center rounded-md px-2.5 text-sm text-faint transition-colors duration-150 hover:text-foreground data-[current]:bg-accent data-[current]:text-foreground"
                        >
                            {item.title}
                        </Link>
                    ))}
                </nav>
            </Heading>

            <section className="space-y-16">{children}</section>
        </div>
    );
}
