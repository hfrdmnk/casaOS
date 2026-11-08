import { Link, usePage } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { UserMenuContent } from '@/components/user-menu-content';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as accounts } from '@/routes/finance/accounts';
import { index as categories } from '@/routes/finance/categories';
import { index as importPage } from '@/routes/finance/import';
import { index as payees } from '@/routes/finance/payees';
import { index as rules } from '@/routes/finance/rules';
import { index as subscriptions } from '@/routes/finance/subscriptions';
import { index as transactions } from '@/routes/finance/transactions';
import { index as transfers } from '@/routes/finance/transfers';
import type { NavItem } from '@/types';

const primaryNavItems: NavItem[] = [
    { title: 'Overview', href: dashboard() },
    { title: 'Transactions', href: transactions() },
    { title: 'Accounts', href: accounts(), matchPrefix: true },
    { title: 'Transfers', href: transfers() },
];

const moreNavItems: NavItem[] = [
    { title: 'Subscriptions', href: subscriptions() },
    { title: 'Categories', href: categories() },
    { title: 'Payees', href: payees() },
    { title: 'Rules', href: rules(), matchPrefix: true },
    { title: 'Import', href: importPage(), matchPrefix: true },
];

const navLinkStyles =
    'inline-flex h-8 items-center gap-1 rounded-md px-2.5 text-[13px] text-faint transition-colors duration-150 outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring data-[current]:text-foreground';

export function AppHeader() {
    const { auth } = usePage().props;
    const getInitials = useInitials();
    const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();
    const isActive = (item: NavItem) =>
        item.matchPrefix
            ? isCurrentOrParentUrl(item.href)
            : isCurrentUrl(item.href);
    const isMoreActive = moreNavItems.some(isActive);

    return (
        <header className="grid h-16 grid-cols-[1fr_auto_1fr] items-center px-5 sm:px-7">
            <Link
                href={dashboard()}
                prefetch
                className="justify-self-start rounded-md outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
                <AppLogo />
            </Link>

            {auth.user && (
                <nav aria-label="Main" className="flex items-center gap-0.5">
                    {primaryNavItems.map((item) => (
                        <Link
                            key={item.title}
                            href={item.href}
                            prefetch
                            data-current={isActive(item) || undefined}
                            aria-current={isActive(item) ? 'page' : undefined}
                            className={cn(
                                navLinkStyles,
                                'hidden md:inline-flex',
                            )}
                        >
                            {item.title}
                        </Link>
                    ))}
                    <DropdownMenu>
                        <DropdownMenuTrigger
                            className={navLinkStyles}
                            data-current={isMoreActive || undefined}
                        >
                            <span className="hidden md:inline">More</span>
                            <span className="md:hidden">Menu</span>
                            <ChevronDown className="size-3.5" />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="center" className="w-48">
                            {primaryNavItems.map((item) => (
                                <DropdownMenuItem
                                    key={item.title}
                                    asChild
                                    className="md:hidden"
                                >
                                    <Link href={item.href}>{item.title}</Link>
                                </DropdownMenuItem>
                            ))}
                            <DropdownMenuSeparator className="md:hidden" />
                            {moreNavItems.map((item) => (
                                <DropdownMenuItem
                                    key={item.title}
                                    asChild
                                    className={cn(
                                        isActive(item) &&
                                            'bg-accent font-medium',
                                    )}
                                >
                                    <Link
                                        href={item.href}
                                        aria-current={
                                            isActive(item) ? 'page' : undefined
                                        }
                                    >
                                        {item.title}
                                    </Link>
                                </DropdownMenuItem>
                            ))}
                        </DropdownMenuContent>
                    </DropdownMenu>
                </nav>
            )}

            {auth.user && (
                <DropdownMenu>
                    <DropdownMenuTrigger
                        className="justify-self-end rounded-full outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        aria-label="Account menu"
                    >
                        <Avatar className="size-7 overflow-hidden rounded-full">
                            <AvatarImage
                                src={auth.user.avatar}
                                alt={auth.user.name}
                            />
                            <AvatarFallback className="bg-accent text-[11px] font-medium text-muted-foreground shadow-[inset_0_0_0_1px_var(--border)]">
                                {getInitials(auth.user.name)}
                            </AvatarFallback>
                        </Avatar>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent className="w-56" align="end">
                        <UserMenuContent user={auth.user} />
                    </DropdownMenuContent>
                </DropdownMenu>
            )}
        </header>
    );
}
