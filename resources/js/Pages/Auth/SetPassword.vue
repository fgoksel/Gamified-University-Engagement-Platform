<script setup>
import { useForm } from "@inertiajs/vue3";
import PrimaryButton from "../../Components/PrimaryButton.vue";
import TextField from "../../Components/TextField.vue";

// The Password Setup page: opened from the invitation email or the
// "Forgot password" email (UC-1.1, UC-2.1).
const props = defineProps({
    token: { type: String, required: true },
    email: { type: String, required: true },
    isActivation: { type: Boolean, default: false },
});

const form = useForm({
    token: props.token,
    password: "",
    password_confirmation: "",
});

function submit() {
    form.post("/auth/set-password", {
        onFinish: () => form.reset("password", "password_confirmation"),
    });
}
</script>

<template>
    <div>
        <!-- The title depends on the link, so it is set here, not in the layout -->
        <h1 class="text-xl font-semibold tracking-tight">
            {{ isActivation ? "Activate your account" : "Set a new password" }}
        </h1>
        <p class="mt-1 text-sm break-all text-fg-muted">For {{ email }}</p>

        <form class="mt-6 space-y-5" novalidate @submit.prevent="submit">
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
    </div>
</template>
