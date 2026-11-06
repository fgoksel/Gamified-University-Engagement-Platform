<script setup>
import { ref } from "vue";
import { Link, router } from "@inertiajs/vue3";
import { ArrowLeft, Plus } from "@lucide/vue";
import EmptyState from "../../Components/EmptyState.vue";
import Btn from "../../Components/Topics/Btn.vue";
import Modal from "../../Components/Topics/Modal.vue";
import CapabilityList from "../../Components/Topics/CapabilityList.vue";
import RoleFormDialog from "../../Components/Topics/RoleFormDialog.vue";
import Trans from "../../Components/Trans.vue";
import { t } from "../../i18n.js";

defineOptions({ layout: { title: t("areas.roles.roles") } });

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
            {{ $t("areas.roles.back_to_areas") }}
        </Link>

        <div class="flex flex-wrap items-start justify-between gap-3">
            <p class="max-w-2xl text-sm text-fg-muted">
                {{ $t("areas.roles.a_role_is_a_name") }}
            </p>
            <Btn variant="primary" @click="editing = 'new'">
                <Plus class="size-4" :stroke-width="2" aria-hidden="true" />
                {{ $t("areas.roles.new_role") }}
            </Btn>
        </div>

        <EmptyState
            v-if="roles.length === 0"
            :title="$t('areas.roles.no_roles_yet')"
        >
            {{ $t("areas.roles.create_the_first_role_then") }}
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
                                >{{ $t("areas.roles.archived") }}</span
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
                                    ? $t(
                                          "areas.roles.global_can_be_used_anywhere",
                                      )
                                    : $t("areas.roles.only_in_scope", {
                                          scope: role.scope.title,
                                      })
                            }}
                            ·
                            {{
                                $t("areas.roles.active_assignments", {
                                    count: role.active_assignments,
                                })
                            }}
                        </p>
                    </div>
                    <div v-if="role.editable" class="flex gap-2">
                        <Btn small @click="editing = role">{{
                            $t("common.edit")
                        }}</Btn>
                        <Btn
                            v-if="!role.archived"
                            small
                            @click="archiving = role"
                            >{{ $t("areas.roles.archive") }}</Btn
                        >
                        <Btn
                            v-else
                            small
                            :loading="busy"
                            @click="setArchived(role, false)"
                            >{{ $t("areas.roles.restore") }}</Btn
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
            :title="$t('areas.roles.archive_this_role')"
            @close="archiving = null"
        >
            <p class="text-sm text-fg">
                <Trans
                    k="areas.roles.archive_confirm"
                    :params="{ count: archiving.active_assignments }"
                >
                    <template #name>
                        <strong>{{ archiving.name }}</strong>
                    </template>
                </Trans>
            </p>
            <template #footer>
                <Btn @click="archiving = null">{{ $t("common.cancel") }}</Btn>
                <Btn
                    variant="danger"
                    :loading="busy"
                    @click="setArchived(archiving, true)"
                    >{{ $t("areas.roles.archive_role") }}</Btn
                >
            </template>
        </Modal>
    </div>
</template>
