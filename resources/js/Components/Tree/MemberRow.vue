<script setup>
import { ref, useId } from "vue";
import { useForm } from "@inertiajs/vue3";
import PrimaryButton from "../PrimaryButton.vue";
import TextField from "../TextField.vue";

// One person on a unit: role, tutor rights, and "End role" with a reason.
// Roles are never deleted, only ended.
const props = defineProps({
    member: { type: Object, required: true },
    tutorPermissions: { type: Array, required: true },
});

const id = useId();
const ending = ref(false);

const rights = useForm({ permissions: [...props.member.permissions] });
const end = useForm({ ended_reason: "" });

function saveRights() {
    rights.put(`/my-courses/members/${props.member.id}/permissions`, {
        preserveScroll: true,
        // The saved rights are the new starting point of the form
        onSuccess: () => rights.defaults(),
    });
}

function submitEnd() {
    end.post(`/my-courses/members/${props.member.id}/end`, {
        preserveScroll: true,
    });
}

function permissionLabel(value) {
    return (
        props.tutorPermissions.find((permission) => permission.value === value)
            ?.label ?? value
    );
}
</script>

<template>
    <li class="py-4">
        <div class="flex flex-wrap items-start gap-x-4 gap-y-2">
            <div class="min-w-0 flex-1">
                <p class="font-medium text-fg">
                    {{ member.name }}
                    <span
                        v-if="member.isMe"
                        class="ml-1 text-xs font-normal text-fg-muted"
                    >
                        (you)
                    </span>
                </p>
                <p class="truncate text-sm text-fg-muted">{{ member.email }}</p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <span
                    class="rounded-control bg-surface-muted px-2 py-0.5 text-xs font-medium text-fg"
                >
                    {{ member.roleLabel }}
                </span>
                <span
                    v-if="member.manual"
                    class="rounded-control bg-info-soft px-2 py-0.5 text-xs font-medium text-info-fg"
                    title="Added by hand, not by the Neptun import"
                >
                    Added by hand
                </span>
                <button
                    v-if="member.canEnd && !ending"
                    type="button"
                    class="rounded-control px-2 py-1 text-sm text-danger-fg hover:bg-danger-soft focus-visible:outline-2 focus-visible:outline-ring"
                    @click="ending = true"
                >
                    End role
                </button>
            </div>
        </div>

        <!-- Tutor rights -->
        <template v-if="member.role === 'tutor'">
            <form
                v-if="member.canSetPermissions"
                class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2"
                @submit.prevent="saveRights"
            >
                <span class="text-sm text-fg-muted">May:</span>
                <label
                    v-for="permission in tutorPermissions"
                    :key="permission.value"
                    class="flex items-center gap-1.5 text-sm text-fg"
                >
                    <input
                        v-model="rights.permissions"
                        type="checkbox"
                        :value="permission.value"
                        class="size-4 rounded border-line"
                    />
                    {{ permission.label }}
                </label>
                <button
                    type="submit"
                    :disabled="!rights.isDirty || rights.processing"
                    class="rounded-control border border-line px-3 py-1 text-sm text-fg hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-ring disabled:opacity-50"
                >
                    Save rights
                </button>
            </form>
            <p v-else class="mt-2 text-sm text-fg-muted">
                May:
                {{
                    member.permissions.length
                        ? member.permissions.map(permissionLabel).join(", ")
                        : "nothing yet"
                }}
            </p>
        </template>

        <!-- End role -->
        <form
            v-if="ending"
            class="mt-3 max-w-md space-y-3 rounded-control bg-surface-muted p-3"
            :aria-labelledby="`${id}-end`"
            novalidate
            @submit.prevent="submitEnd"
        >
            <p :id="`${id}-end`" class="text-sm text-fg">
                End the role of <strong>{{ member.name }}</strong
                >? The record is kept with today's date and your reason.
            </p>
            <TextField
                v-model="end.ended_reason"
                label="Reason"
                :error="end.errors.ended_reason"
            />
            <div class="flex gap-2">
                <PrimaryButton :loading="end.processing" class="sm:w-auto">
                    End role
                </PrimaryButton>
                <button
                    type="button"
                    class="rounded-control px-3 py-2 text-sm text-fg-muted hover:text-fg focus-visible:outline-2 focus-visible:outline-ring"
                    @click="ending = false"
                >
                    Cancel
                </button>
            </div>
        </form>
    </li>
</template>
