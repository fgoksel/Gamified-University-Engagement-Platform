<script setup>
import { Link, useForm, usePage } from "@inertiajs/vue3";
import AlertMessage from "../../Components/AlertMessage.vue";
import PrimaryButton from "../../Components/PrimaryButton.vue";
import TextField from "../../Components/TextField.vue";

defineOptions({
    layout: {
        title: "Log in",
        description: "Use your university email address.",
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
                label="Email address"
                type="email"
                autocomplete="username"
                :error="form.errors.email"
            />

            <div>
                <TextField
                    v-model="form.password"
                    label="Password"
                    type="password"
                    autocomplete="current-password"
                    :error="form.errors.password"
                />
                <div class="mt-2 text-right">
                    <Link
                        href="/forgot-password"
                        class="text-sm font-medium text-link hover:underline"
                    >
                        Forgot password?
                    </Link>
                </div>
            </div>

            <PrimaryButton :loading="form.processing">Log in</PrimaryButton>
        </form>
    </div>
</template>
