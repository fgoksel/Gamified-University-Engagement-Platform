<script setup>
import { computed, ref } from "vue";
import { router, useForm, usePage } from "@inertiajs/vue3";
import { ImageUp, LoaderCircle, Trash2 } from "@lucide/vue";
import SettingsSection from "./SettingsSection.vue";

const props = defineProps({
    profile: { type: Object, required: true },
});

const page = usePage();
const avatar = computed(() => page.props.auth?.user?.avatar ?? null);

const initials = computed(() =>
    props.profile.name
        .split(" ")
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0].toUpperCase())
        .join(""),
);

const roleLabels = { teacher: "Teacher", student: "Student" };

// Read-only details from the Neptun import; empty ones are hidden
const details = computed(() =>
    [
        { label: "Name", value: props.profile.name },
        { label: "Email", value: props.profile.email },
        {
            label: "Role",
            value: roleLabels[props.profile.role] ?? props.profile.role,
        },
        { label: "Faculty", value: props.profile.faculty },
        { label: "Neptun code", value: props.profile.neptunCode },
        { label: "Major", value: props.profile.major },
        { label: "Year of study", value: props.profile.yearOfStudy },
    ].filter((item) => item.value !== null && item.value !== ""),
);

// Photo upload
const fileInput = ref(null);
const form = useForm({ avatar: null });
const removing = ref(false);

function chooseFile() {
    fileInput.value?.click();
}

function upload(event) {
    const [file] = event.target.files;
    if (!file) return;

    form.avatar = file;
    form.post("/profile/avatar", {
        preserveScroll: true,
        onFinish: () => {
            form.reset();
            event.target.value = "";
        },
    });
}

function removePhoto() {
    router.delete("/profile/avatar", {
        preserveScroll: true,
        onStart: () => (removing.value = true),
        onFinish: () => (removing.value = false),
    });
}
</script>

<template>
    <SettingsSection
        title="Profile data"
        description="Your details come from the university records. To correct them, contact the administrator."
    >
        <!-- Photo -->
        <div class="flex flex-wrap items-center gap-4">
            <img
                v-if="avatar"
                :src="avatar"
                alt="Your profile photo"
                class="size-16 rounded-full object-cover"
            />
            <span
                v-else
                class="flex size-16 items-center justify-center rounded-full bg-surface-muted text-lg font-semibold text-fg"
                aria-hidden="true"
            >
                {{ initials }}
            </span>

            <div class="flex flex-wrap gap-2">
                <input
                    ref="fileInput"
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    class="sr-only"
                    tabindex="-1"
                    aria-hidden="true"
                    @change="upload"
                />
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-control border border-line px-3 py-2 text-sm font-medium text-fg hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-ring disabled:opacity-70"
                    :disabled="form.processing"
                    @click="chooseFile"
                >
                    <LoaderCircle
                        v-if="form.processing"
                        class="size-4 animate-spin"
                        :stroke-width="2"
                    />
                    <ImageUp v-else class="size-4" :stroke-width="1.75" />
                    {{ avatar ? "Change photo" : "Upload photo" }}
                </button>
                <button
                    v-if="avatar"
                    type="button"
                    class="inline-flex items-center gap-2 rounded-control px-3 py-2 text-sm font-medium text-fg-muted hover:bg-surface-muted hover:text-fg focus-visible:outline-2 focus-visible:outline-ring disabled:opacity-70"
                    :disabled="removing"
                    @click="removePhoto"
                >
                    <Trash2 class="size-4" :stroke-width="1.75" />
                    Remove
                </button>
            </div>
        </div>
        <p
            v-if="form.errors.avatar"
            class="mt-2 text-sm text-danger-fg"
            role="alert"
        >
            {{ form.errors.avatar }}
        </p>
        <p v-else class="mt-2 text-sm text-fg-muted">
            JPG, PNG or WebP, up to 2 MB.
        </p>

        <!-- Details -->
        <dl class="mt-6 divide-y divide-line border-t border-line">
            <div
                v-for="item in details"
                :key="item.label"
                class="grid gap-1 py-3 sm:grid-cols-3 sm:gap-4"
            >
                <dt class="text-sm text-fg-muted">{{ item.label }}</dt>
                <dd class="text-sm break-words text-fg sm:col-span-2">
                    {{ item.value }}
                </dd>
            </div>
        </dl>
    </SettingsSection>
</template>
