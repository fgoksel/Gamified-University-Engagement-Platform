<script setup>
import { Link, useForm, usePage } from "@inertiajs/vue3";
import { ArrowLeft } from "@lucide/vue";
import AlertMessage from "../../Components/AlertMessage.vue";
import PrimaryButton from "../../Components/PrimaryButton.vue";
import TextField from "../../Components/TextField.vue";

defineOptions({
    layout: {
        title: "Forgot password",
        description:
            "Enter your email address and we will send you a link to set a new password.",
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
                label="Email address"
                type="email"
                autocomplete="username"
                :error="form.errors.email"
            />

            <PrimaryButton :loading="form.processing">
                Send reset link
            </PrimaryButton>
        </form>

        <Link
            href="/login"
            class="inline-flex items-center gap-1.5 text-sm font-medium text-link hover:underline"
        >
            <ArrowLeft class="size-4" :stroke-width="1.75" />
            Back to log in
        </Link>
    </div>
</template>
