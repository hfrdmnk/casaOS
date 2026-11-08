import { usePage } from '@inertiajs/react';

import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    const { name } = usePage().props;

    return (
        <span className="flex items-center gap-2 text-[15px] font-medium tracking-[-0.01em]">
            <AppLogoIcon className="size-2 text-foreground" />
            {name}
        </span>
    );
}
