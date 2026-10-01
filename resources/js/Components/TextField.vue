<script setup>
import { computed, ref, useId } from "vue";
import { Eye, EyeOff } from "@lucide/vue";

const props = defineProps({
    label: { type: String, required: true },
    type: { type: String, default: "text" },
    error: { type: String, default: "" },
    hint: { type: String, default: "" },
    autocomplete: { type: String, default: "" },
});

const model = defineModel({ type: String, default: "" });

const id = useId();
const showPassword = ref(false);

const isPassword = computed(() => props.type === "password");
const inputType = computed(() =>
    isPassword.value && showPassword.value ? "text" : props.type,
);
// Screen readers read the error (or the hint) together with the field
const describedBy = computed(() => {
    if (props.error) return `${id}-error`;
    if (props.hint) return `${id}-hint`;
    return undefined;
});
</script>

<template>
    <div>
        <label :for="id" class="block text-sm font-medium text-fg">
            {{ label }}
        </label>

        <div class="relative mt-1.5">
            <input
                :id="id"
                v-model="model"
                :type="inputType"
                :autocomplete="autocomplete || undefined"
                :aria-invalid="error ? 'true' : undefined"
                :aria-describedby="describedBy"
                class="block w-full rounded-control border bg-surface px-3 py-2 text-fg placeholder:text-fg-muted focus:outline-2 focus:outline-offset-0 focus:outline-ring"
                :class="[
                    error ? 'border-danger' : 'border-line',
                    isPassword ? 'pr-10' : '',
                ]"
            />

            <button
                v-if="isPassword"
                type="button"
                class="absolute inset-y-0 right-0 flex items-center rounded-control px-3 text-fg-muted hover:text-fg focus-visible:outline-2 focus-visible:outline-ring"
                :aria-label="showPassword ? 'Hide password' : 'Show password'"
                :aria-pressed="showPassword"
                @click="showPassword = !showPassword"
            >
                <EyeOff
                    v-if="showPassword"
                    class="size-4"
                    :stroke-width="1.75"
                />
                <Eye v-else class="size-4" :stroke-width="1.75" />
            </button>
        </div>

        <p
            v-if="error"
            :id="`${id}-error`"
            class="mt-1.5 text-sm text-danger-fg"
        >
            {{ error }}
        </p>
        <p
            v-else-if="hint"
            :id="`${id}-hint`"
            class="mt-1.5 text-sm text-fg-muted"
        >
            {{ hint }}
        </p>
    </div>
</template>
