<script setup>
import { useId } from "vue";
import { storeToRefs } from "pinia";
import { Moon, Sun } from "@lucide/vue";
import { useThemeStore } from "../../stores/theme.js";
import SettingsSection from "./SettingsSection.vue";

// Saved to the account, so it follows the user to every device.
const themeStore = useThemeStore();
const { theme } = storeToRefs(themeStore);

const name = useId();

const options = [
    { value: "light", label: "Light", icon: Sun },
    { value: "dark", label: "Dark", icon: Moon },
];
</script>

<template>
    <SettingsSection
        title="Appearance"
        description="Choose how the app looks. Your choice is saved to your account."
    >
        <fieldset>
            <legend class="sr-only">Theme</legend>
            <div class="grid max-w-md grid-cols-2 gap-3">
                <label
                    v-for="option in options"
                    :key="option.value"
                    class="flex cursor-pointer items-center gap-3 rounded-control border px-4 py-3 text-sm font-medium has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-ring"
                    :class="
                        theme === option.value
                            ? 'border-primary bg-info-soft text-fg'
                            : 'border-line text-fg-muted hover:bg-surface-muted hover:text-fg'
                    "
                >
                    <input
                        type="radio"
                        :name="name"
                        :value="option.value"
                        :checked="theme === option.value"
                        class="sr-only"
                        @change="themeStore.chooseTheme(option.value)"
                    />
                    <component
                        :is="option.icon"
                        class="size-5"
                        :stroke-width="1.75"
                    />
                    {{ option.label }}
                </label>
            </div>
        </fieldset>
    </SettingsSection>
</template>
