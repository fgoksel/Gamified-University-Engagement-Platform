<script setup>
import { useForm } from "@inertiajs/vue3";
import AlertMessage from "../../Components/AlertMessage.vue";
import PrimaryButton from "../../Components/PrimaryButton.vue";
import TextField from "../../Components/TextField.vue";
import { t } from "../../i18n.js";

// The Password Setup page: opened from the invitation email or the
// "Forgot password" email (UC-1.1, UC-2.1).
const props = defineProps({
    token: { type: String, required: true },
    email: { type: String, required: true },
    isActivation: { type: Boolean, default: false },
    // Set when the link logged out another account in this browser
    loggedOutName: { type: String, default: null },
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
            {{
                isActivation
                    ? $t("auth.set_password.activate_title")
                    : $t("auth.set_password.reset_title")
            }}
        </h1>
        <p class="mt-1 text-sm break-all text-fg-muted">
            {{ $t("auth.set_password.for_email", { email }) }}
        </p>

        <AlertMessage v-if="loggedOutName" class="mt-4">
            {{ $t("auth.set_password.logged_out", { name: loggedOutName }) }}
        </AlertMessage>

        <form class="mt-6 space-y-5" novalidate @submit.prevent="submit">
            <TextField
                v-model="form.password"
                :label="$t('auth.new_password')"
                type="password"
                autocomplete="new-password"
                :hint="$t('auth.password_hint')"
                :error="form.errors.password"
            />

            <TextField
                v-model="form.password_confirmation"
                :label="$t('auth.confirm_new_password')"
                type="password"
                autocomplete="new-password"
            />

            <PrimaryButton :loading="form.processing">{{
                $t("common.save")
            }}</PrimaryButton>
        </form>
    </div>
</template>
