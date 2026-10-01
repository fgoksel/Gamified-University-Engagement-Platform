<script setup>
import { useForm } from "@inertiajs/vue3";
import PrimaryButton from "../PrimaryButton.vue";
import TextField from "../TextField.vue";
import SettingsSection from "./SettingsSection.vue";

const form = useForm({
    current_password: "",
    password: "",
    password_confirmation: "",
});

function submit() {
    form.put("/profile/password", {
        preserveScroll: true,
        // Never keep passwords in the form after sending them
        onFinish: () =>
            form.reset("current_password", "password", "password_confirmation"),
    });
}
</script>

<template>
    <SettingsSection
        title="Change password"
        description="Use at least 8 characters. You will stay logged in on this device."
    >
        <form class="max-w-md space-y-5" novalidate @submit.prevent="submit">
            <TextField
                v-model="form.current_password"
                label="Old password"
                type="password"
                autocomplete="current-password"
                :error="form.errors.current_password"
            />

            <TextField
                v-model="form.password"
                label="New password"
                type="password"
                autocomplete="new-password"
                hint="At least 8 characters."
                :error="form.errors.password"
            />

            <TextField
                v-model="form.password_confirmation"
                label="Confirm new password"
                type="password"
                autocomplete="new-password"
            />

            <PrimaryButton :loading="form.processing" class="sm:w-auto">
                Change password
            </PrimaryButton>
        </form>
    </SettingsSection>
</template>
