import '../css/app.css';
import './bootstrap';
import './echo';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import NavigationProgress from './Components/NavigationProgress.vue';
import { startPresence } from './lib/presence';

const appName = import.meta.env.VITE_APP_NAME || 'Lumko - Business Technology Partners';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => [h(App, props), h(NavigationProgress)] })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);

        startPresence();

        return app;
    },
    // Inertia's bar exposes an invalid role="bar"; NavigationProgress replaces it.
    progress: false,
});
