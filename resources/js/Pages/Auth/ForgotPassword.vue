<script setup>
import { Link, useForm, usePage } from "@inertiajs/vue3";
import { ArrowLeft } from "@lucide/vue";
import AlertMessage from "../../Components/AlertMessage.vue";
import PrimaryButton from "../../Components/PrimaryButton.vue";
import TextField from "../../Components/TextField.vue";
import { t } from "../../i18n.js";

defineOptions({
    layout: {
        title: t("auth.forgot_password.title"),
        description: t("auth.forgot_password.description"),
    },
});

const page = usePage();

const form = useForm({
    email: "",
});

function submit() {
    form.post("/forgot-password");
}
</script>

<template>
    <div class="space-y-5">
        <AlertMessage v-if="page.props.flash?.success">
            {{ page.props.flash.success }}
        </AlertMessage>

        <form class="space-y-5" novalidate @submit.prevent="submit">
            <TextField
                v-model="form.email"
                :label="$t('auth.email_address')"
                type="email"
                autocomplete="username"
                :error="form.errors.email"
            />

            <PrimaryButton :loading="form.processing">
                {{ $t("auth.forgot_password.submit") }}
            </PrimaryButton>
        </form>

        <Link
            href="/login"
            class="inline-flex items-center gap-1.5 text-sm font-medium text-link hover:underline"
        >
            <ArrowLeft class="size-4" :stroke-width="1.75" />
            {{ $t("auth.forgot_password.back_to_login") }}
        </Link>
    </div>
</template>
