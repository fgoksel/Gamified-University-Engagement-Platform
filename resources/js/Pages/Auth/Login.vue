<script setup>
import { Link, useForm, usePage } from "@inertiajs/vue3";
import AlertMessage from "../../Components/AlertMessage.vue";
import PrimaryButton from "../../Components/PrimaryButton.vue";
import TextField from "../../Components/TextField.vue";
import { t } from "../../i18n.js";

defineOptions({
    layout: {
        title: t("auth.login.title"),
        description: t("auth.login.description"),
    },
});

const page = usePage();

const form = useForm({
    email: "",
    password: "",
});

function submit() {
    form.post("/login", {
        onFinish: () => form.reset("password"),
    });
}
</script>

<template>
    <div class="space-y-5">
        <AlertMessage v-if="page.props.flash?.success">
            {{ page.props.flash.success }}
        </AlertMessage>
        <AlertMessage v-if="page.props.flash?.error" type="error">
            {{ page.props.flash.error }}
        </AlertMessage>

        <form class="space-y-5" novalidate @submit.prevent="submit">
            <TextField
                v-model="form.email"
                :label="$t('auth.email_address')"
                type="email"
                autocomplete="username"
                :error="form.errors.email"
            />

            <div>
                <TextField
                    v-model="form.password"
                    :label="$t('auth.password')"
                    type="password"
                    autocomplete="current-password"
                    :error="form.errors.password"
                />
                <div class="mt-2 text-right">
                    <Link
                        href="/forgot-password"
                        class="text-sm font-medium text-link hover:underline"
                    >
                        {{ $t("auth.login.forgot_password") }}
                    </Link>
                </div>
            </div>

            <PrimaryButton :loading="form.processing">{{
                $t("auth.login.submit")
            }}</PrimaryButton>
        </form>
    </div>
</template>
