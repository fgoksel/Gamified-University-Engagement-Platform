<script setup>
import { Link, useForm } from "@inertiajs/vue3";
import PrimaryButton from "../../Components/PrimaryButton.vue";
import TextField from "../../Components/TextField.vue";

// Shown on first login, while the user still has a temporary password (UC-2.1).
defineOptions({
    layout: {
        title: "Change your password",
        description:
            "You are using a temporary password. Choose your own password to continue.",
    },
});

const form = useForm({
    current_password: "",
    password: "",
    password_confirmation: "",
});

function submit() {
    form.put("/password/change", {
        onFinish: () =>
            form.reset("current_password", "password", "password_confirmation"),
    });
}
</script>

<template>
    <div class="space-y-5">
        <form class="space-y-5" novalidate @submit.prevent="submit">
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

            <PrimaryButton :loading="form.processing">Save</PrimaryButton>
        </form>

        <Link
            href="/logout"
            method="post"
            as="button"
            class="text-sm font-medium text-link hover:underline"
        >
            Log out
        </Link>
    </div>
</template>
