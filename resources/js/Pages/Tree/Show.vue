<script setup>
import { Link, useForm } from "@inertiajs/vue3";
import { ChevronRight, FolderPlus, Users } from "@lucide/vue";
import AddPersonForm from "../../Components/Tree/AddPersonForm.vue";
import MemberRow from "../../Components/Tree/MemberRow.vue";
import PrimaryButton from "../../Components/PrimaryButton.vue";
import TextField from "../../Components/TextField.vue";

// One unit of "My courses": its people, its subtopics, and what the
// logged-in user may do here (decided by the backend).
defineOptions({ layout: { title: "My courses" } });

const props = defineProps({
    unit: { type: Object, required: true },
    breadcrumbs: { type: Array, required: true },
    children: { type: Array, required: true },
    members: { type: Array, required: true },
    history: { type: Array, required: true },
    addableRoles: { type: Array, required: true },
    canAddSubtopic: { type: Boolean, required: true },
    tutorPermissions: { type: Array, required: true },
});

const subtopic = useForm({ title: "" });

function addSubtopic() {
    subtopic.post(`/my-courses/${props.unit.id}/subtopics`, {
        preserveScroll: true,
        onSuccess: () => subtopic.reset(),
    });
}
</script>

<template>
    <div class="space-y-6">
        <!-- Where this unit sits -->
        <nav aria-label="Breadcrumb">
            <ol class="flex flex-wrap items-center gap-1 text-sm text-fg-muted">
                <li>
                    <Link
                        href="/my-courses"
                        class="hover:text-fg hover:underline"
                    >
                        My courses
                    </Link>
                </li>
                <li
                    v-for="crumb in breadcrumbs"
                    :key="crumb.id"
                    class="flex items-center gap-1"
                >
                    <ChevronRight class="size-4" :stroke-width="1.75" />
                    <Link
                        :href="`/my-courses/${crumb.id}`"
                        class="hover:text-fg hover:underline"
                    >
                        {{ crumb.title }}
                    </Link>
                </li>
                <li class="flex items-center gap-1 text-fg" aria-current="page">
                    <ChevronRight class="size-4" :stroke-width="1.75" />
                    {{ unit.title }}
                </li>
            </ol>
        </nav>

        <header>
            <p class="text-xs font-medium text-fg-muted uppercase">
                {{ unit.kindLabel }}
                <template v-if="unit.courseCode"
                    >· {{ unit.courseCode }}</template
                >
            </p>
            <h1 class="mt-1 text-xl font-semibold text-fg">{{ unit.title }}</h1>
            <p v-if="unit.myRole" class="mt-1 text-sm text-fg-muted">
                Your role here: {{ unit.myRole }}
            </p>
        </header>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <!-- People -->
                <section
                    class="rounded-card border border-line bg-surface p-5 shadow-card"
                >
                    <h2
                        class="flex items-center gap-2 text-base font-semibold text-fg"
                    >
                        <Users class="size-5" :stroke-width="1.75" />
                        People
                    </h2>
                    <ul v-if="members.length" class="mt-2 divide-y divide-line">
                        <MemberRow
                            v-for="member in members"
                            :key="member.id"
                            :member="member"
                            :tutor-permissions="tutorPermissions"
                        />
                    </ul>
                    <p v-else class="mt-3 text-sm text-fg-muted">
                        Nobody here that you can see yet.
                    </p>

                    <details
                        v-if="history.length"
                        class="mt-4 border-t border-line pt-4"
                    >
                        <summary
                            class="cursor-pointer text-sm font-medium text-fg-muted hover:text-fg"
                        >
                            Ended roles ({{ history.length }})
                        </summary>
                        <ul class="mt-2 divide-y divide-line text-sm">
                            <li
                                v-for="member in history"
                                :key="member.id"
                                class="py-2"
                            >
                                <span class="font-medium text-fg">{{
                                    member.name
                                }}</span>
                                <span class="text-fg-muted">
                                    · {{ member.roleLabel }} ·
                                    {{ member.startedAt }} to
                                    {{ member.endedAt }} ·
                                    {{ member.endedReason }}
                                </span>
                            </li>
                        </ul>
                    </details>
                </section>

                <!-- Units below -->
                <section
                    class="rounded-card border border-line bg-surface p-5 shadow-card"
                >
                    <h2 class="text-base font-semibold text-fg">
                        {{ unit.kind === "root" ? "Courses" : "Subtopics" }}
                    </h2>
                    <ul
                        v-if="children.length"
                        class="mt-2 divide-y divide-line"
                    >
                        <li v-for="child in children" :key="child.id">
                            <Link
                                :href="`/my-courses/${child.id}`"
                                class="flex items-center gap-2 py-3 text-sm text-fg hover:underline"
                            >
                                <span class="flex-1">
                                    {{ child.title }}
                                    <span
                                        v-if="child.courseCode"
                                        class="text-fg-muted"
                                    >
                                        · {{ child.courseCode }}
                                    </span>
                                </span>
                                <ChevronRight
                                    class="size-4 text-fg-muted"
                                    :stroke-width="1.75"
                                />
                            </Link>
                        </li>
                    </ul>
                    <p v-else class="mt-3 text-sm text-fg-muted">None yet.</p>

                    <form
                        v-if="canAddSubtopic"
                        class="mt-4 flex flex-col gap-3 border-t border-line pt-4 sm:flex-row sm:items-end"
                        novalidate
                        @submit.prevent="addSubtopic"
                    >
                        <div class="flex-1">
                            <TextField
                                v-model="subtopic.title"
                                label="New subtopic"
                                :error="subtopic.errors.title"
                            />
                        </div>
                        <PrimaryButton
                            :loading="subtopic.processing"
                            class="sm:w-auto"
                        >
                            <FolderPlus class="size-4" :stroke-width="2" />
                            Add subtopic
                        </PrimaryButton>
                    </form>
                </section>
            </div>

            <AddPersonForm
                v-if="addableRoles.length"
                :key="unit.id"
                class="self-start"
                :unit-id="unit.id"
                :roles="addableRoles"
                :tutor-permissions="tutorPermissions"
            />
        </div>
    </div>
</template>
