<script setup>
import { computed, ref } from "vue";
import { Link, router } from "@inertiajs/vue3";
import {
    Award,
    Calendar,
    CalendarCheck,
    CheckCircle2,
    Clock,
    IdCard,
    RotateCcw,
    Sparkles,
    Tag,
    Users,
    XCircle,
} from "@lucide/vue";
import EmptyState from "../../Components/EmptyState.vue";
import StatusChip from "../../Components/StatusChip.vue";
import Modal from "../../Components/Topics/Modal.vue";
import Btn from "../../Components/Topics/Btn.vue";
import { categoryDot } from "../../categoryColors.js";
import {
    actionWords,
    applyLabels,
    leaveText,
    myStatus,
} from "../../eventWords.js";

// Student: events I applied to (UC-2.3, Task #50)
defineOptions({ layout: { title: "My events" } });

const props = defineProps({
    applications: {
        type: Array,
        default: () => [],
    },
});

const currentTab = ref("all"); // 'all' | 'active' | 'history'
const withdrawingApp = ref(null);
const isWithdrawing = ref(false);
const reapplyingId = ref(null);

const activeStatuses = ["accepted", "waitlisted", "pending"];
const historyStatuses = ["withdrawn", "rejected"];

const counts = computed(() => {
    let active = 0;
    let history = 0;
    for (const app of props.applications) {
        if (app.is_credited || historyStatuses.includes(app.status)) {
            history++;
        } else if (activeStatuses.includes(app.status)) {
            active++;
        }
    }
    return {
        all: props.applications.length,
        active,
        history,
    };
});

const filteredApplications = computed(() => {
    if (currentTab.value === "active") {
        return props.applications.filter(
            (app) => !app.is_credited && activeStatuses.includes(app.status),
        );
    }
    if (currentTab.value === "history") {
        return props.applications.filter(
            (app) => app.is_credited || historyStatuses.includes(app.status),
        );
    }
    return props.applications;
});

// Icon next to the student's status
const statusIcon = {
    accepted: CheckCircle2,
    waitlisted: Users,
    pending: Clock,
    withdrawn: RotateCcw,
    rejected: XCircle,
};

// "3 Nov, 14:00" for the short sentences under each event
function shortDateTime(isoString) {
    if (!isoString) return "";
    const date = new Date(isoString);
    const day = date.toLocaleDateString(undefined, {
        day: "numeric",
        month: "short",
    });
    const time = date.toLocaleTimeString(undefined, {
        hour: "2-digit",
        minute: "2-digit",
    });
    return `${day}, ${time}`;
}

function formatDateTime(isoString) {
    if (!isoString) return "";
    return new Date(isoString).toLocaleString(undefined, {
        year: "numeric",
        month: "short",
        day: "numeric",
        hour: "2-digit",
        minute: "2-digit",
    });
}

function promptWithdrawal(app) {
    withdrawingApp.value = app;
}

function cancelWithdrawal() {
    if (!isWithdrawing.value) {
        withdrawingApp.value = null;
    }
}

function confirmWithdrawal() {
    if (!withdrawingApp.value || isWithdrawing.value) return;

    isWithdrawing.value = true;
    router.post(
        `/events/${withdrawingApp.value.event.id}/withdraw`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                isWithdrawing.value = false;
                withdrawingApp.value = null;
            },
        },
    );
}

function reapply(app) {
    if (reapplyingId.value) return;

    reapplyingId.value = app.id;
    router.post(
        `/events/${app.event.id}/apply`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                reapplyingId.value = null;
            },
        },
    );
}
</script>

<template>
    <div class="space-y-6">
        <!-- Subtitle & Browse shortcut -->
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <p class="text-sm text-fg-muted">
                Track your event applications, waitlist positions, and credited
                attendance points.
            </p>
            <div class="flex flex-wrap gap-2">
                <!-- One tap to the passport from My events (Task #93) -->
                <Link
                    href="/passport"
                    class="inline-flex min-h-11 items-center gap-2 rounded-control border border-line bg-surface px-3 py-2 text-sm font-medium text-fg hover:bg-surface-muted transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                >
                    <IdCard class="size-4" />
                    My QR passport
                </Link>
                <Link
                    href="/events"
                    class="inline-flex items-center gap-2 rounded-control border border-line bg-surface px-3 py-2 text-sm font-medium text-fg hover:bg-surface-muted transition"
                >
                    <Calendar class="size-4" />
                    Browse Events
                </Link>
            </div>
        </div>

        <!-- Filter tabs -->
        <div
            v-if="applications.length > 0"
            class="flex border-b border-line gap-4 text-sm font-medium"
        >
            <button
                type="button"
                class="min-h-11 pb-3 border-b-2 transition"
                :class="
                    currentTab === 'all'
                        ? 'border-primary text-link font-semibold'
                        : 'border-transparent text-fg-muted hover:text-fg'
                "
                @click="currentTab = 'all'"
            >
                All ({{ counts.all }})
            </button>
            <button
                type="button"
                class="min-h-11 pb-3 border-b-2 transition"
                :class="
                    currentTab === 'active'
                        ? 'border-primary text-link font-semibold'
                        : 'border-transparent text-fg-muted hover:text-fg'
                "
                @click="currentTab = 'active'"
            >
                Active ({{ counts.active }})
            </button>
            <button
                type="button"
                class="min-h-11 pb-3 border-b-2 transition"
                :class="
                    currentTab === 'history'
                        ? 'border-primary text-link font-semibold'
                        : 'border-transparent text-fg-muted hover:text-fg'
                "
                @click="currentTab = 'history'"
            >
                History ({{ counts.history }})
            </button>
        </div>

        <!-- Empty state: No applications at all -->
        <div v-if="applications.length === 0" class="space-y-4">
            <EmptyState
                :icon="CalendarCheck"
                title="You have not applied to any events"
            >
                Events you apply to will be listed here, with their status,
                waitlist position, and the points you earned.
            </EmptyState>
            <div class="flex justify-center">
                <Link
                    href="/events"
                    class="inline-flex items-center gap-2 rounded-control bg-primary px-4 py-2 text-sm font-semibold text-on-primary hover:bg-primary-hover transition"
                >
                    <Calendar class="size-4" />
                    Find events to join
                </Link>
            </div>
        </div>

        <!-- Empty state: Tab has 0 applications -->
        <div
            v-else-if="filteredApplications.length === 0"
            class="rounded-card border border-line bg-surface p-8 text-center text-sm text-fg-muted"
        >
            No events found in this category.
        </div>

        <!-- Application Cards -->
        <div v-else class="grid gap-4">
            <div
                v-for="app in filteredApplications"
                :key="app.id"
                class="rounded-card border border-line bg-surface p-5 shadow-card space-y-4 transition hover:border-fg-muted/30"
            >
                <!-- Card Header -->
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="space-y-1">
                        <!-- Category, Tag & Topic -->
                        <div
                            class="flex flex-wrap items-center gap-2 text-xs text-fg-muted"
                        >
                            <span
                                v-if="app.event.category"
                                class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 font-medium"
                                :class="[
                                    app.event.category === 'academic' &&
                                        'bg-cat-academic-soft text-cat-academic-fg',
                                    app.event.category === 'scientific' &&
                                        'bg-cat-scientific-soft text-cat-scientific-fg',
                                    app.event.category === 'sports' &&
                                        'bg-cat-sports-soft text-cat-sports-fg',
                                    app.event.category === 'community' &&
                                        'bg-cat-community-soft text-cat-community-fg',
                                ]"
                            >
                                <span
                                    class="size-1.5 rounded-full"
                                    :class="categoryDot[app.event.category]"
                                ></span>
                                <span class="capitalize">{{
                                    app.event.category
                                }}</span>
                            </span>

                            <span
                                v-if="app.event.topic_tag"
                                class="inline-flex items-center gap-1 font-medium text-fg"
                            >
                                <Tag class="size-3" />
                                {{ app.event.topic_tag.name }}
                            </span>

                            <span
                                v-if="app.event.owning_topic"
                                class="text-fg-muted"
                            >
                                &bull; {{ app.event.owning_topic.name }}
                            </span>
                        </div>

                        <!-- Event Title -->
                        <h2 class="text-lg font-semibold text-fg">
                            {{ app.event.title }}
                        </h2>
                    </div>

                    <!-- Status Badges -->
                    <div class="flex flex-wrap items-center gap-2">
                        <!-- Points Credited -->
                        <StatusChip
                            v-if="app.is_credited"
                            tone="success"
                            :icon="Sparkles"
                        >
                            +{{ app.event.points_value }} pts Credited
                        </StatusChip>

                        <!-- Application Status, in plain words (Task #94) -->
                        <StatusChip
                            v-if="myStatus(app)"
                            :tone="myStatus(app).tone"
                            :icon="statusIcon[app.status]"
                        >
                            {{ myStatus(app).text }}
                        </StatusChip>

                        <!-- Event Lifecycle status if non-standard -->
                        <StatusChip
                            v-if="app.event.status === 'on_hold'"
                            tone="warning"
                        >
                            On hold
                        </StatusChip>
                        <StatusChip
                            v-else-if="app.event.status === 'cancelled'"
                            tone="danger"
                        >
                            Cancelled
                        </StatusChip>
                    </div>
                </div>

                <!-- Description -->
                <p
                    v-if="app.event.description"
                    class="text-sm text-fg-muted line-clamp-2"
                >
                    {{ app.event.description }}
                </p>

                <!-- Schedule & Meta -->
                <div
                    class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 pt-2 text-xs text-fg-muted border-t border-line/60"
                >
                    <div>
                        <span class="font-medium text-fg">Event Date:</span>
                        <div class="mt-0.5">
                            {{ formatDateTime(app.event.event_starts_at) }}
                        </div>
                    </div>
                    <div>
                        <span class="font-medium text-fg"
                            >Application Deadline:</span
                        >
                        <div class="mt-0.5">
                            {{ formatDateTime(app.event.application_deadline) }}
                        </div>
                    </div>
                    <div v-if="app.event.location">
                        <span class="font-medium text-fg">Location:</span>
                        <div class="mt-0.5">
                            {{ app.event.location }}
                        </div>
                    </div>
                    <div>
                        <span class="font-medium text-fg">Reward:</span>
                        <div class="mt-0.5 font-semibold text-fg">
                            +{{ app.event.points_value }} points
                        </div>
                    </div>
                </div>

                <!-- Footer / Actions -->
                <div
                    class="flex flex-wrap items-center justify-between gap-3 pt-3 border-t border-line/60"
                >
                    <div class="text-xs text-fg-muted">
                        <span v-if="app.withdrawn_at">
                            You left on {{ shortDateTime(app.withdrawn_at) }}.
                        </span>
                        <span v-else-if="app.status === 'waitlisted'">
                            Applied on {{ shortDateTime(app.applied_at) }} ·
                            you'll get a message if a place opens.
                        </span>
                        <span v-else-if="app.status === 'pending'">
                            Applied on {{ shortDateTime(app.applied_at) }} · the
                            organiser will choose and let you know.
                        </span>
                        <span v-else-if="app.status === 'accepted'">
                            Applied on {{ shortDateTime(app.applied_at) }} ·
                            your place is confirmed.
                        </span>
                        <span v-else-if="app.status === 'rejected'">
                            The organiser chose other students this time.
                        </span>
                    </div>

                    <div class="flex items-center gap-2">
                        <!-- Leave event: the same words as on the event cards (Task #94) -->
                        <Btn
                            v-if="app.can_withdraw"
                            @click="promptWithdrawal(app)"
                        >
                            {{ actionWords.leave }}
                        </Btn>

                        <!-- Re-apply button -->
                        <button
                            v-if="app.can_reapply"
                            type="button"
                            :disabled="reapplyingId === app.id"
                            class="inline-flex min-h-11 items-center gap-1.5 rounded-control bg-primary px-3 py-1.5 text-xs font-semibold text-on-primary hover:bg-primary-hover disabled:opacity-50 transition cursor-pointer focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                            @click="reapply(app)"
                        >
                            <RotateCcw class="size-3.5" />
                            {{
                                reapplyingId === app.id
                                    ? "Sending…"
                                    : applyLabels.again
                            }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Leave event dialog: the same as on the event cards (ApplyAction) -->
        <Modal
            v-if="withdrawingApp"
            :title="actionWords.leaveTitle"
            :busy="isWithdrawing"
            @close="cancelWithdrawal"
        >
            <p class="text-sm text-fg">
                {{ leaveText({ my_status: withdrawingApp.status }) }}
            </p>
            <template #footer>
                <Btn :disabled="isWithdrawing" @click="cancelWithdrawal">
                    {{ actionWords.stay }}
                </Btn>
                <Btn
                    variant="danger"
                    :loading="isWithdrawing"
                    @click="confirmWithdrawal"
                >
                    {{ actionWords.leave }}
                </Btn>
            </template>
        </Modal>
    </div>
</template>
