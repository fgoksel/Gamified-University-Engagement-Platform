import { createApp, h } from "vue";
import { createInertiaApp, router } from "@inertiajs/vue3";
import { createPinia } from "pinia";
import { useThemeStore } from "./stores/theme.js";
import AppLayout from "./Layouts/AppLayout.vue";
import AuthLayout from "./Layouts/AuthLayout.vue";
import GuestLayout from "./Layouts/GuestLayout.vue";
import PrintLayout from "./Layouts/PrintLayout.vue";
import ScreenLayout from "./Layouts/ScreenLayout.vue";

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob("./Pages/**/*.vue", { eager: true });
        return pages[`./Pages/${name}.vue`];
    },
    // Every page gets a layout automatically:
    // Pages/Public/ use GuestLayout, Pages/Auth/ (login, passwords) use
    // AuthLayout, Pages/Print/ (sheets to print) use PrintLayout, Pages/Screen/
    // (a projector or a phone held up) use ScreenLayout, all others use
    // AppLayout.
    layout: (name) => {
        if (name.startsWith("Print/")) return PrintLayout;
        if (name.startsWith("Screen/")) return ScreenLayout;
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
