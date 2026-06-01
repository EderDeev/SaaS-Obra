import { spawn } from 'node:child_process';
import { mkdir, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';

const baseUrl = process.env.TUTORIAL_BASE_URL || 'http://127.0.0.1:8000';
const chromePath = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const debugPort = Number(process.env.CHROME_DEBUG_PORT || (9300 + Math.floor(Math.random() * 300)));
const outputDir = path.resolve('public/images/tutorials');
const profileDir = path.resolve('storage/app/tutorial-capture-chrome');

const screenshots = [
    ['login', '/login', false],
    ['contratos', '/t/demo/contracts', true],
    ['parametrizacao-empresas', '/t/demo/parametrizacao/empresas', true],
    ['usuarios', '/t/demo/users', true],
    ['permissoes', '/t/demo/permissoes', true],
    ['atividades', '/t/demo/atividades', true],
    ['projetos', '/t/demo/projetos', true],
    ['rnc', '/t/demo/qualidade/rnc', true],
    ['perfil', '/profile', true],
];

const sleep = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

async function waitForJson(url, attempts = 50) {
    for (let attempt = 0; attempt < attempts; attempt += 1) {
        try {
            const response = await fetch(url);

            if (response.ok) {
                return await response.json();
            }
        } catch {
            // Chrome still starting.
        }

        await sleep(250);
    }

    throw new Error(`Chrome did not expose its debugging endpoint at ${url}.`);
}

function createCdpClient(webSocketUrl) {
    const socket = new WebSocket(webSocketUrl);
    const pending = new Map();
    let nextId = 1;

    socket.addEventListener('message', (event) => {
        const payload = JSON.parse(event.data);

        if (!payload.id || !pending.has(payload.id)) {
            return;
        }

        const { resolve, reject } = pending.get(payload.id);
        pending.delete(payload.id);

        if (payload.error) {
            reject(new Error(payload.error.message));
            return;
        }

        resolve(payload.result);
    });

    socket.addEventListener('close', () => {
        for (const { reject } of pending.values()) {
            reject(new Error('Chrome closed the debugging connection.'));
        }

        pending.clear();
    });

    const ready = new Promise((resolve, reject) => {
        socket.addEventListener('open', resolve, { once: true });
        socket.addEventListener('error', reject, { once: true });
    });

    return {
        ready,
        close: () => socket.close(),
        send(method, params = {}) {
            const id = nextId;
            nextId += 1;

            return new Promise((resolve, reject) => {
                pending.set(id, { resolve, reject });
                socket.send(JSON.stringify({ id, method, params }));
            });
        },
    };
}

async function navigate(client, url, waitMilliseconds = 1300) {
    await client.send('Page.navigate', { url });
    await sleep(waitMilliseconds);
}

async function evaluate(client, expression) {
    const result = await client.send('Runtime.evaluate', {
        expression,
        awaitPromise: true,
        returnByValue: true,
    });

    if (result.exceptionDetails) {
        throw new Error(result.exceptionDetails.text);
    }

    return result.result?.value;
}

async function screenshot(client, name) {
    const result = await client.send('Page.captureScreenshot', {
        format: 'png',
        captureBeyondViewport: false,
        fromSurface: true,
    });

    await writeFile(path.join(outputDir, `${name}.png`), Buffer.from(result.data, 'base64'));
    console.log(`Captured ${name}.png`);
}

await mkdir(outputDir, { recursive: true });
await rm(profileDir, { force: true, recursive: true });
await mkdir(profileDir, { recursive: true });

const chrome = spawn(chromePath, [
    '--headless=new',
    '--disable-gpu',
    '--disable-extensions',
    '--no-sandbox',
    '--hide-scrollbars',
    '--no-first-run',
    '--no-default-browser-check',
    '--remote-allow-origins=*',
    `--remote-debugging-port=${debugPort}`,
    `--user-data-dir=${profileDir}`,
    '--window-size=1440,1000',
    'about:blank',
], {
    stdio: 'ignore',
    windowsHide: true,
});

try {
    await waitForJson(`http://127.0.0.1:${debugPort}/json/version`);
    const targetResponse = await fetch(`http://127.0.0.1:${debugPort}/json/new?about:blank`, {
        method: 'PUT',
    });
    const target = await targetResponse.json();

    if (!target?.webSocketDebuggerUrl) {
        throw new Error('Chrome did not create a page target.');
    }

    const client = createCdpClient(target.webSocketDebuggerUrl);
    await client.ready;
    await client.send('Page.enable');
    await client.send('Runtime.enable');
    await client.send('Emulation.setDeviceMetricsOverride', {
        width: 1440,
        height: 1000,
        deviceScaleFactor: 1,
        mobile: false,
    });

    await navigate(client, `${baseUrl}/login`);
    console.log(`Viewport ${await evaluate(client, 'window.innerWidth')}x${await evaluate(client, 'window.innerHeight')}`);
    await screenshot(client, 'login');

    await evaluate(client, `
        (() => {
            const fields = document.querySelectorAll('input');
            const email = Array.from(fields).find((field) => field.type === 'text' || field.type === 'email');
            const password = Array.from(fields).find((field) => field.type === 'password');
            const setValue = (field, value) => {
                const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set;
                setter.call(field, value);
                field.dispatchEvent(new Event('input', { bubbles: true }));
                field.dispatchEvent(new Event('change', { bubbles: true }));
            };

            setValue(email, 'owner@demo.test');
            setValue(password, 'Senha1!');
            document.querySelector('form').requestSubmit();
        })();
    `);
    await sleep(1800);

    const loggedUrl = await evaluate(client, 'window.location.href');

    if (loggedUrl.includes('/login') || loggedUrl.includes('/alterar-senha')) {
        throw new Error(`Demo login did not reach the application. Current URL: ${loggedUrl}`);
    }

    for (const [name, url, requiresAuthentication] of screenshots) {
        if (!requiresAuthentication) {
            continue;
        }

        await navigate(client, `${baseUrl}${url}`);
        const currentUrl = await evaluate(client, 'window.location.href');

        if (!currentUrl.includes(url)) {
            console.warn(`Skipped ${name}.png because ${url} redirected to ${currentUrl}.`);
            continue;
        }

        await screenshot(client, name);
    }

    client.close();
} finally {
    chrome.kill();
}
