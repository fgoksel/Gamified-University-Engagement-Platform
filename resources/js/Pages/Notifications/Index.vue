<script setup>
import { computed } from "vue";
import { Link, router } from "@inertiajs/vue3";
import { Bell, CheckCheck } from "@lucide/vue";
import EmptyState from "../../Components/EmptyState.vue";
import Btn from "../../Components/Topics/Btn.vue";

// In-app notifications of the signed-in person (Task #53, UC-1.6 / UC-2.3).
defineOptions({ layout: { title: "Notifications" } });

const props = defineProps({
    notifications: { type: Object, required: true },
});

const items = computed(() => props.notifications.data);
const unread = computed(() => items.value.filter((n) => !n.read).length);

function open(notification) {
    // Reading a notification marks it as read, then goes to the page it is about.
    if (notification.read) {
        if (notification.link) router.visit(notification.link);
        return;
    }

    router.post(
        `/notifications/${notification.id}/read`,
        {},
        {
            preserveScroll: true,
            onSuccess: () =>
                notification.link && router.visit(notification.link),
        },
    );
}

function markAllRead() {
    router.post("/notifications/read-all", {}, { preserveScroll: true });
}
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-4">
        <div v-if="items.length" class="flex justify-end">
            <Btn
                :disabled="unread === 0"
                data-test="read-all"
                @click="markAllRead"
            >
                <CheckCheck class="size-4" />
                Mark all as read
            </Btn>
        </div>

        <EmptyState
            v-if="items.length === 0"
            :icon="Bell"
            title="No notifications"
        >
            When your application changes, or an event you applied to is
            cancelled, put on hold or restored, you will see it here.
        </EmptyState>

        <ul
            v-else
            class="divide-y divide-line overflow-hidden rounded-card border border-line bg-surface shadow-card"
        >
            <li v-for="notification in items" :key="notification.id">
                <button
                    type="button"
                    class="flex w-full items-start gap-3 px-4 py-3 text-left hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-ring"
                    :class="notification.read ? '' : 'bg-primary/5'"
                    data-test="notification"
                    @click="open(notification)"
                >
                    <span
                        class="mt-1.5 size-2 shrink-0 rounded-full"
                        :class="
                            notification.read ? 'bg-transparent' : 'bg-primary'
                        "
                        :aria-label="notification.read ? 'Read' : 'Unread'"
                    ></span>
                    <span class="min-w-0 flex-1">
                        <span
                            class="block text-sm text-fg"
                            :class="notification.read ? '' : 'font-semibold'"
                        >
                            {{ notification.title }}
                        </span>
                        <!-- Import results list one skipped row per line (Task #72). -->
                        <span
                            class="mt-0.5 block text-sm whitespace-pre-line text-fg-muted"
                        >
                            {{ notification.body }}
                        </span>
                        <span
                            class="mt-1 block text-xs text-fg-muted"
                            :title="notification.created_at"
                        >
                            {{ notification.created_human }}
                        </span>
                    </span>
                </button>
            </li>
        </ul>

        <nav
            v-if="notifications.last_page > 1"
            class="flex items-center justify-between text-sm"
            aria-label="Pages"
        >
            <Link
                v-if="notifications.prev_page_url"
                :href="notifications.prev_page_url"
                class="rounded-control border border-line px-3 py-1.5 hover:bg-surface-muted"
                preserve-scroll
            >
                Newer
            </Link>
            <span v-else></span>
            <span class="text-fg-muted">
                Page {{ notifications.current_page }} of
                {{ notifications.last_page }}
            </span>
            <Link
                v-if="notifications.next_page_url"
                :href="notifications.next_page_url"
                class="rounded-control border border-line px-3 py-1.5 hover:bg-surface-muted"
                preserve-scroll
            >
                Older
            </Link>
            <span v-else></span>
        </nav>
    </div>
</template>
