import { createApp, h } from "vue";
import { createInertiaApp } from "@inertiajs/vue3";
import { initTheme } from "./composables/useTheme.js";
import AppLayout from "./Layouts/AppLayout.vue";
import AuthLayout from "./Layouts/AuthLayout.vue";
import GuestLayout from "./Layouts/GuestLayout.vue";

initTheme();

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob("./Pages/**/*.vue", { eager: true });
        return pages[`./Pages/${name}.vue`];
    },
    // Every page gets a layout automatically:
    // Pages/Public/ use GuestLayout, Pages/Auth/ (login, passwords) use
    // AuthLayout, all others use AppLayout.
    layout: (name) => {
        if (name.startsWith("Public/")) return GuestLayout;
        if (name.startsWith("Auth/")) return AuthLayout;
        return AppLayout;
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
});
