import { t, tc } from "./i18n.js";

// Plain words for the student screens (Task #94), in one place, read from the dictionary (Task #95).
// Students never see status codes such as "waitlisted" or "teacher_select".

// The student's own application, for a StatusChip: { text, tone } or null.
// Takes an event from the catalog (my_status) or an application from My events (status).
export function myStatus(item) {
    const status = "my_status" in item ? item.my_status : item.status;

    switch (status) {
        case "accepted":
            return { text: t("event_words.status.accepted"), tone: "success" };
        case "waitlisted":
            return { text: t("event_words.status.waitlisted"), tone: "info" };
        case "pending":
            return {
                text: t("event_words.status.pending"),
                tone: "warning",
            };
        case "rejected":
            return { text: t("event_words.status.rejected"), tone: "neutral" };
        case "withdrawn":
            return { text: t("event_words.status.withdrawn"), tone: "neutral" };
        default:
            return null;
    }
}

// How students get a place
export function applicationType(event) {
    return event.application_type === "teacher_select"
        ? t("event_words.type.teacher_select")
        : t("event_words.type.fcfs");
}

export function places(event) {
    if (event.application_type === "teacher_select") {
        return tc("event_words.places.organiser_chooses", event.capacity);
    }
    if (event.places_left > 0) {
        return tc("event_words.places.left", event.places_left);
    }
    return t("event_words.places.full");
}

export const applyLabels = {
    apply: t("event_words.apply.apply"),
    waitlist: t("event_words.apply.waitlist"),
    again: t("event_words.apply.again"),
};

// The label of the Apply button for this event
export function applyLabel(event) {
    if (event.my_status === "withdrawn") return applyLabels.again;
    if (event.application_type === "fcfs" && event.places_left === 0) {
        return applyLabels.waitlist;
    }
    return applyLabels.apply;
}

// The student's own application is still running: they may undo or leave it
export function isActiveApplication(event) {
    return ["accepted", "waitlisted", "pending"].includes(event.my_status);
}

// Everything the action area under an event says (ApplyAction)
export const actionWords = {
    undo: t("event_words.action.undo"),
    undone: t("event_words.action.undone"),
    leave: t("event_words.action.leave"),
    stay: t("event_words.action.stay"),
    leaveTitle: t("event_words.action.leave_title"),
    left: t("event_words.action.left"),
    started: t("event_words.action.started"),
    closed: t("event_words.action.closed"),
    overlapTitle: t("event_words.action.overlap_title"),
    cancel: t("event_words.action.cancel"),
    applyAnyway: t("event_words.action.apply_anyway"),
};

// What happens when the student leaves, by their status and how places are given.
// Only first come, first served events pass a freed place to the waitlist.
export function leaveText(event) {
    switch (event.my_status) {
        case "accepted":
            return event.application_type === "fcfs"
                ? t("event_words.leave.accepted_fcfs")
                : t("event_words.leave.accepted_other");
        case "waitlisted":
            return t("event_words.leave.waitlisted");
        default:
            return t("event_words.leave.default");
    }
}

export function overlapText(title) {
    return t("event_words.overlap", { title });
}
