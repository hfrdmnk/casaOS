import { Head, Link, usePage } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { dashboard, login } from '@/routes';

const messages: Record<number, { title: string; description: string }> = {
    403: {
        title: 'You can’t open this page',
        description: 'It belongs to another account or needs other rights.',
    },
    404: {
        title: 'This page doesn’t exist',
        description:
            'The link may be out of date, or the item was deleted or merged.',
    },
    500: {
        title: 'Something broke on our side',
        description:
            'The error was logged. Go back to the overview and try again.',
    },
    503: {
        title: 'casaOS is down for maintenance',
        description: 'It will be back in a few minutes.',
    },
};

export default function Error({ status }: { status: number }) {
    const { title, description } = messages[status] ?? messages[500];
    const { auth } = usePage().props;

    return (
        <>
            <Head title={title} />
            <p className="mb-3.5 text-[13px] text-faint tabular-nums">
                Error {status}
            </p>
            <Heading title={title} description={description}>
                <Button asChild>
                    {auth.user ? (
                        <Link href={dashboard()}>Back to overview</Link>
                    ) : (
                        <Link href={login()}>Log in</Link>
                    )}
                </Button>
            </Heading>
        </>
    );
}
