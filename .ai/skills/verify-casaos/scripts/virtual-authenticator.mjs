// Adds a WebAuthn virtual authenticator to the agent-browser page whose URL starts with
// <url-prefix> and keeps the DevTools session open, which keeps the authenticator alive,
// for <seconds>. Every 5 seconds it prints the stored credentials (rpId, resident, signCount).
//
// CDP_URL="$(agent-browser get cdp-url)" node virtual-authenticator.mjs http://localhost:8000 300 > "$RUN/authenticator.log" &

const [prefix, seconds = '300'] = process.argv.slice(2);
const httpBase = process.env.CDP_URL.replace(
    /^ws:\/\/([^/]+)\/.*$/,
    'http://$1',
);
const targets = await (await fetch(`${httpBase}/json/list`)).json();
const page = targets.find(
    (target) => target.type === 'page' && target.url.startsWith(prefix),
);

if (!page) {
    console.error(
        `No page target starting with ${prefix}; open the app in agent-browser first.`,
    );
    process.exit(1);
}

const socket = new WebSocket(page.webSocketDebuggerUrl);
const pending = new Map();
let nextId = 1;

socket.onmessage = (event) => {
    const message = JSON.parse(event.data);
    pending.get(message.id)?.(message);
    pending.delete(message.id);
};

const send = (method, params = {}) =>
    new Promise((resolve) => {
        const id = nextId++;
        pending.set(id, resolve);
        socket.send(JSON.stringify({ id, method, params }));
    });

await new Promise((resolve) => (socket.onopen = resolve));
await send('WebAuthn.enable', { enableUI: false });

const added = await send('WebAuthn.addVirtualAuthenticator', {
    options: {
        protocol: 'ctap2',
        transport: 'internal',
        hasResidentKey: true,
        hasUserVerification: true,
        isUserVerified: true,
        automaticPresenceSimulation: true,
    },
});
const authenticatorId = added.result?.authenticatorId;
console.log(
    JSON.stringify({
        target: page.url,
        authenticatorId,
        error: added.error ?? null,
    }),
);

const report = setInterval(async () => {
    const stored = await send('WebAuthn.getCredentials', { authenticatorId });
    const credentials = (stored.result?.credentials ?? []).map(
        ({ rpId, isResidentCredential, signCount }) => ({
            rpId,
            isResidentCredential,
            signCount,
        }),
    );
    console.log(JSON.stringify({ credentials }));
}, 5000);

setTimeout(
    () => {
        clearInterval(report);
        socket.close();
        process.exit(0);
    },
    Number(seconds) * 1000,
);
