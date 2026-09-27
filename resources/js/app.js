import { createApp, h } from "vue";
import { createInertiaApp } from "@inertiajs/vue3";
import { initTheme } from "./composables/useTheme.js";
import AppLayout from "./Layouts/AppLayout.vue";
import GuestLayout from "./Layouts/GuestLayout.vue";

initTheme();

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob("./Pages/**/*.vue", { eager: true });
        return pages[`./Pages/${name}.vue`];
    },
    // Every page gets a layout automatically:
    // pages in Pages/Public/ use GuestLayout, all others use AppLayout.
    layout: (name) => (name.startsWith("Public/") ? GuestLayout : AppLayout),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
});