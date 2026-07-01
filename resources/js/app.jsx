import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

const viteAppName = import.meta.env.VITE_APP_NAME;
const bladeAppName = document.querySelector('meta[name="app-name"]')?.getAttribute('content');
const appName = [bladeAppName, viteAppName, 'Deming']
    .find((name) => name && !String(name).includes('${'));

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.jsx`,
            import.meta.glob('./Pages/**/*.jsx'),
        ),
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: {
        color: '#0B5FFF',
    },
});
