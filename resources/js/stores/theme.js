import { ref } from "vue";
import { defineStore } from "pinia";
import { router } from "@inertiajs/vue3";

// Light / dark theme for the whole app.
// Logged-in users: saved in users.appearance, so it follows them to any device.
// Guests (login page, public pages): remembered in this browser (localStorage).
const STORAGE_KEY = "theme";

function applyTheme(value) {
    document.documentElement.classList.toggle("dark", value === "dark");
}

function readSaved() {
    try {
        return localStorage.getItem(STORAGE_KEY);
    } catch {
        // Storage can be blocked (e.g. private mode); fall back to light.
        return null;
    }
}

function remember(value) {
    try {
        localStorage.setItem(STORAGE_KEY, value);
    } catch {
        // Ignore: the theme still changes for this visit.
    }
}

export const useThemeStore = defineStore("theme", () => {
    const theme = ref("light");
    let loggedIn = false;

    function setTheme(value) {
        theme.value = value === "dark" ? "dark" : "light";
        applyTheme(theme.value);
        remember(theme.value);
    }

    // Called on page load and after every visit with the shared auth.user,
    // so the saved theme is loaded right after login.
    function sync(user) {
        loggedIn = Boolean(user);
        setTheme(user?.appearance ?? readSaved());
    }

    function toggleTheme() {
        setTheme(theme.value === "dark" ? "light" : "dark");

        if (loggedIn) {
            router.put(
                "/appearance",
                { appearance: theme.value },
                { preserveState: true, preserveScroll: true, async: true },
            );
        }
    }

    return { theme, sync, toggleTheme };
});
