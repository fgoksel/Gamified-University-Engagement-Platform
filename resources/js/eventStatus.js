import { t } from "./i18n.js";

// Plain words per event status (Task #73)
export const eventStatusLabel = {
    draft: t("event_status.draft"),
    published: t("event_status.published"),
    on_hold: t("event_status.on_hold"),
    completed: t("event_status.completed"),
    cancelled: t("event_status.cancelled"),
};

// StatusChip tone per event status (Task #91), so every screen shows an
// event's status the same way.
export const eventStatusTone = {
    draft: "neutral",
    published: "info",
    on_hold: "warning",
    completed: "neutral",
    cancelled: "danger",
};
