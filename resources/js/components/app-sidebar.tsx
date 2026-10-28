import { Link } from '@inertiajs/react';
import {
    ArrowLeftRight,
    FileUp,
    LayoutGrid,
    ListOrdered,
    Repeat,
    Store,
    Tags,
    WalletCards,
    WandSparkles,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
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

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

const financeNavItems: NavItem[] = [
    {
        title: 'Accounts',
        href: accounts(),
        icon: WalletCards,
        matchPrefix: true,
    },
    { title: 'Transactions', href: transactions(), icon: ListOrdered },
    { title: 'Transfers', href: transfers(), icon: ArrowLeftRight },
    { title: 'Subscriptions', href: subscriptions(), icon: Repeat },
    { title: 'Categories', href: categories(), icon: Tags },
    { title: 'Payees', href: payees(), icon: Store },
    { title: 'Rules', href: rules(), icon: WandSparkles, matchPrefix: true },
    { title: 'Import', href: importPage(), icon: FileUp, matchPrefix: true },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
                <NavMain items={financeNavItems} label="Finance" />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
