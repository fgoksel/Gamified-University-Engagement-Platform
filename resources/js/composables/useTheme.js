import { ref } from "vue";

// Temporary: the theme is remembered in the browser (localStorage).
// Later it will come from users.appearance once login exists.
const STORAGE_KEY = "theme";

const theme = ref("light");

function applyTheme(value) {
    document.documentElement.classList.toggle("dark", value === "dark");
}

export function initTheme() {
    let saved = null;
    try {
        saved = localStorage.getItem(STORAGE_KEY);
    } catch {
        // Storage can be blocked (e.g. private mode); fall back to light.
    }
    theme.value = saved === "dark" ? "dark" : "light";
    applyTheme(theme.value);
}

export function useTheme() {
    function toggleTheme() {
        theme.value = theme.value === "dark" ? "light" : "dark";
        applyTheme(theme.value);
        try {
            localStorage.setItem(STORAGE_KEY, theme.value);
        } catch {
            // Ignore: the theme still changes for this visit.
        }
    }

    return { theme, toggleTheme };
}
