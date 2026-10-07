<script setup>
import { ref } from "vue";
import { Link, router } from "@inertiajs/vue3";
import { ArrowLeft, Plus } from "@lucide/vue";
import EmptyState from "../../Components/EmptyState.vue";
import Btn from "../../Components/Topics/Btn.vue";
import Modal from "../../Components/Topics/Modal.vue";
import CapabilityList from "../../Components/Topics/CapabilityList.vue";
import RoleFormDialog from "../../Components/Topics/RoleFormDialog.vue";

defineOptions({ layout: { title: "Roles" } });

defineProps({
    roles: { type: Array, required: true },
    registry: { type: Array, required: true },
    scopes: { type: Array, required: true },
    isAdmin: { type: Boolean, required: true },
});

const editing = ref(null); // role | "new"
const archiving = ref(null);
const busy = ref(false);

function setArchived(role, archived) {
    busy.value = true;
    router.post(
        `/topics/roles/${role.id}/archive`,
        { archived },
        {
            preserveScroll: true,
            onSuccess: () => (archiving.value = null),
            onFinish: () => (busy.value = false),
        },
    );
}
</script>

<template>
    <div class="space-y-5">
        <Link
            href="/topics"
            class="inline-flex items-center gap-1 text-sm text-fg-muted hover:text-fg hover:underline"
        >
            <ArrowLeft class="size-4" :stroke-width="1.75" aria-hidden="true" />
            Back to topics
        </Link>

        <div class="flex flex-wrap items-start justify-between gap-3">
            <p class="max-w-2xl text-sm text-fg-muted">
                A role is a name plus a set of things people can do. Roles are
                reusable: give the same role to many people at different topics.
                The name is just a label; only the ticked abilities count.
            </p>
            <Btn variant="primary" @click="editing = 'new'">
                <Plus class="size-4" :stroke-width="2" aria-hidden="true" />
                New role
            </Btn>
        </div>

        <EmptyState v-if="roles.length === 0" title="No roles yet">
            Create the first role, then give it to people on a topic. A fresh
            installation starts without any predefined roles.
        </EmptyState>

        <ul v-else class="space-y-3">
            <li
                v-for="role in roles"
                :key="role.id"
                class="rounded-card border border-line bg-surface p-4 shadow-card"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-fg">
                            {{ role.name }}
                            <span
                                v-if="role.archived"
                                class="ml-2 rounded-full bg-surface-muted px-2 py-0.5 text-xs font-normal text-fg-muted"
                                >archived</span
                            >
                        </h2>
                        <p
                            v-if="role.description"
                            class="mt-0.5 text-sm text-fg-muted"
                        >
                            {{ role.description }}
                        </p>
                        <p class="mt-1 text-xs text-fg-muted">
                            {{
                                role.global
                                    ? "Global: can be used anywhere"
                                    : `Only in “${role.scope.title}” and below`
                            }}
                            · {{ role.active_assignments }} active assignment(s)
                        </p>
                    </div>
                    <div v-if="role.editable" class="flex gap-2">
                        <Btn small @click="editing = role">Edit</Btn>
                        <Btn
                            v-if="!role.archived"
                            small
                            @click="archiving = role"
                            >Archive</Btn
                        >
                        <Btn
                            v-else
                            small
                            :loading="busy"
                            @click="setArchived(role, false)"
                            >Restore</Btn
                        >
                    </div>
                </div>
                <CapabilityList
                    :values="role.capabilities"
                    :pass-on="role.delegable"
                    class="mt-3"
                />
            </li>
        </ul>

        <RoleFormDialog
            v-if="editing"
            :role="editing === 'new' ? null : editing"
            :registry="registry"
            :scopes="scopes"
            :is-admin="isAdmin"
            @close="editing = null"
        />

        <Modal
            v-if="archiving"
            title="Archive this role?"
            @close="archiving = null"
        >
            <p class="text-sm text-fg">
                <strong>{{ archiving.name }}</strong> can no longer be given to
                new people. The {{ archiving.active_assignments }} active
                assignment(s) stay as they are: nobody loses access, and topics
                are untouched. You can restore the role at any time.
            </p>
            <template #footer>
                <Btn @click="archiving = null">Cancel</Btn>
                <Btn
                    variant="danger"
                    :loading="busy"
                    @click="setArchived(archiving, true)"
                    >Archive role</Btn
                >
            </template>
        </Modal>
    </div>
</template>
