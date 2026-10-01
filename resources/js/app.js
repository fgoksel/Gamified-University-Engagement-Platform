import { createApp, h } from "vue";
import { createInertiaApp, router } from "@inertiajs/vue3";
import { createPinia } from "pinia";
import { useThemeStore } from "./stores/theme.js";
import AppLayout from "./Layouts/AppLayout.vue";
import AuthLayout from "./Layouts/AuthLayout.vue";
import GuestLayout from "./Layouts/GuestLayout.vue";

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
        const pinia = createPinia();

        // Apply the saved theme before the first render, and again after each
        // visit (e.g. login, logout) in case the user changed.
        const themeStore = useThemeStore(pinia);
        themeStore.sync(props.initialPage.props.auth?.user);
        router.on("navigate", (event) =>
            themeStore.sync(event.detail.page.props.auth?.user),
        );

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(pinia)
            .mount(el);
    },
});
