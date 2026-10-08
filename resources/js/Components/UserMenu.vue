<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { Link, usePage } from "@inertiajs/vue3";
import { ChevronUp, LogOut, Moon, Settings, Sun } from "@lucide/vue";
import { storeToRefs } from "pinia";
import { useThemeStore } from "../stores/theme.js";

const page = usePage();
const themeStore = useThemeStore();
const { theme } = storeToRefs(themeStore);
const { toggleTheme } = themeStore;

// No login yet: show a placeholder until the backend shares auth.user.
const user = computed(
    () => page.props.auth?.user ?? { name: "Guest user", email: "" },
);

const initials = computed(() =>
    user.value.name
        .split(" ")
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0].toUpperCase())
        .join(""),
);

const open = ref(false);
const root = ref(null);

function close() {
    open.value = false;
}

function onDocumentClick(event) {
    if (root.value && !root.value.contains(event.target)) {
        close();
    }
}

function onKeydown(event) {
    if (event.key === "Escape") {
        close();
    }
}

onMounted(() => {
    document.addEventListener("click", onDocumentClick);
    document.addEventListener("keydown", onKeydown);
});
onBeforeUnmount(() => {
    document.removeEventListener("click", onDocumentClick);
    document.removeEventListener("keydown", onKeydown);
});
</script>

<template>
    <div ref="root" class="relative">
        <!-- The menu opens upwards, because it sits at the bottom of the sidebar -->
        <div
            v-if="open"
            class="absolute inset-x-0 bottom-full mb-2 rounded-card border border-line bg-surface p-1 shadow-popover"
            role="menu"
        >
            <Link
                :href="user.role === 'admin' ? '/admin/profile' : '/profile'"
                class="flex items-center gap-3 rounded-control px-3 py-2 text-sm text-fg hover:bg-surface-muted"
                role="menuitem"
                @click="close"
            >
                <Settings class="size-4" :stroke-width="1.75" />
                Profile settings
            </Link>

            <button
                type="button"
                class="flex w-full items-center gap-3 rounded-control px-3 py-2 text-sm text-fg hover:bg-surface-muted"
                role="menuitem"
                @click="toggleTheme"
            >
                <Sun
                    v-if="theme === 'dark'"
                    class="size-4"
                    :stroke-width="1.75"
                />
                <Moon v-else class="size-4" :stroke-width="1.75" />
                {{ theme === "dark" ? "Light mode" : "Dark mode" }}
            </button>

            <div class="my-1 border-t border-line"></div>

            <Link
                href="/logout"
                method="post"
                as="button"
                class="flex w-full items-center gap-3 rounded-control px-3 py-2 text-sm text-fg hover:bg-surface-muted"
                role="menuitem"
            >
                <LogOut class="size-4" :stroke-width="1.75" />
                Log out
            </Link>
        </div>

        <button
            type="button"
            class="flex w-full items-center gap-3 rounded-control px-2 py-2 text-left hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-ring"
            :aria-expanded="open"
            aria-haspopup="menu"
            @click="open = !open"
        >
            <img
                v-if="user.avatar"
                :src="user.avatar"
                alt=""
                class="size-9 rounded-full object-cover"
            />
            <span
                v-else
                class="flex size-9 shrink-0 items-center justify-center rounded-full bg-surface-muted text-sm font-medium text-fg"
            >
                {{ initials }}
            </span>

            <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-medium text-fg">
                    {{ user.name }}
                </span>
                <span
                    v-if="user.email"
                    class="block truncate text-xs text-fg-muted"
                >
                    {{ user.email }}
                </span>
            </span>

            <ChevronUp
                class="size-4 shrink-0 text-fg-muted transition-transform"
                :class="open ? '' : 'rotate-180'"
                :stroke-width="1.75"
            />
        </button>
    </div>
</template>
