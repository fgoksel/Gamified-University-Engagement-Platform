<script setup>
import { Link, useForm } from "@inertiajs/vue3";
import PrimaryButton from "../../Components/PrimaryButton.vue";
import TextField from "../../Components/TextField.vue";
import { t } from "../../i18n.js";

// Shown on first login, while the user still has a temporary password (UC-2.1).
defineOptions({
    layout: {
        title: t("auth.change_password.title"),
        description: t("auth.change_password.description"),
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
                :label="$t('auth.old_password')"
                type="password"
                autocomplete="current-password"
                :error="form.errors.current_password"
            />

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

        <Link
            href="/logout"
            method="post"
            as="button"
            class="text-sm font-medium text-link hover:underline"
        >
            {{ $t("auth.change_password.log_out") }}
        </Link>
    </div>
</template>
