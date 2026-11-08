import { useState } from 'react';

/**
 * Tracks one `router.post`/`router.visit` so its button can disable itself and
 * say what is happening while the request runs. Spread `callbacks` into the
 * visit options.
 */
export function usePendingVisit() {
    const [pending, setPending] = useState(false);

    return {
        pending,
        callbacks: {
            onStart: () => setPending(true),
            onFinish: () => setPending(false),
        },
    };
}
