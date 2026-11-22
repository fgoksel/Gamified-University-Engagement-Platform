<script setup>
import CapabilityList from "./CapabilityList.vue";

// The role to give, as radio cards with each role's description and powers (Task #72), shared by
// "Import students" and "General area" (Task #133). Only the roles the server offers are listed.
defineProps({
    // [{ id, name, description, capabilities }]
    roles: { type: Array, required: true },
    // The radio group's name, unique on the page
    name: { type: String, required: true },
    legend: { type: String, required: true },
    // Shown when there is no role to choose
    empty: { type: String, required: true },
    error: { type: String, default: "" },
    // Native "required" and the red asterisk
    required: { type: Boolean, default: false },
});

const model = defineModel({ type: Number, default: null });
</script>

<template>
    <fieldset>
        <legend class="block text-sm font-medium text-fg">
            {{ legend }}
            <span v-if="required" class="text-danger-fg" aria-hidden="true"
                >*</span
            >
        </legend>
        <p v-if="roles.length === 0" class="mt-1.5 text-sm text-fg-muted">
            {{ empty }}
        </p>
        <div v-else class="mt-1.5 space-y-2">
            <label
                v-for="option in roles"
                :key="option.id"
                class="flex cursor-pointer gap-3 rounded-control border border-line p-3 text-sm has-[:checked]:border-primary has-[:checked]:bg-info-soft"
            >
                <input
                    v-model="model"
                    type="radio"
                    :name="name"
                    :value="option.id"
                    :required="required"
                    class="mt-1 shrink-0"
                />
                <span class="min-w-0">
                    <span class="block font-medium text-fg">{{
                        option.name
                    }}</span>
                    <span
                        v-if="option.description"
                        class="block text-fg-muted"
                        >{{ option.description }}</span
                    >
                    <CapabilityList
                        :values="option.capabilities"
                        class="mt-2"
                    />
                </span>
            </label>
        </div>
        <p v-if="error" class="mt-1.5 text-sm text-danger-fg">
            {{ error }}
        </p>
    </fieldset>
</template>
