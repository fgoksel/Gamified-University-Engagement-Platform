// Plain words for the student screens (Task #94), in one place so the
// translations of #95 can move all of them into the dictionary in one go.
// Students never see status codes such as "waitlisted" or "teacher_select".

// The student's own application, for a StatusChip: { text, tone } or null.
// Takes an event from the catalog (my_status) or an application from My events (status).
export function myStatus(item) {
    const status = "my_status" in item ? item.my_status : item.status;
    const position = item.waitlist_position;

    switch (status) {
        case "accepted":
            return { text: "You're in", tone: "success" };
        case "waitlisted":
            return {
                text: position
                    ? `You're number ${position} on the waitlist`
                    : "You're on the waitlist",
                tone: "info",
            };
        case "pending":
            return {
                text: "Applied · the organiser will choose",
                tone: "warning",
            };
        case "rejected":
            return { text: "Not selected this time", tone: "neutral" };
        case "withdrawn":
            return { text: "You left this event", tone: "neutral" };
        default:
            return null;
    }
}

// How students get a place
export function applicationType(event) {
    return event.application_type === "teacher_select"
        ? "The organiser chooses"
        : "First come, first served";
}

export function places(event) {
    if (event.application_type === "teacher_select") {
        return `${event.capacity} ${event.capacity === 1 ? "place" : "places"} · the organiser chooses`;
    }
    if (event.places_left > 0) {
        return `${event.places_left} ${event.places_left === 1 ? "place" : "places"} left`;
    }
    return "Full · you can join the waitlist";
}

export const applyLabels = {
    apply: "Apply",
    waitlist: "Join the waitlist",
    again: "Apply again",
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
    undo: "Undo",
    undone: "Application undone",
    leave: "Leave event",
    stay: "Stay",
    leaveTitle: "Leave this event?",
    left: "You've left the event.",
    started: "The event has started, so you can't leave it any more.",
    closed: "Applications are closed",
    overlapTitle: "Time conflict",
    cancel: "Cancel",
    applyAnyway: "Apply anyway",
};

// What happens when the student leaves, by their status
export function leaveText(event) {
    switch (event.my_status) {
        case "accepted":
            return "Your place will go to the next student on the waitlist.";
        case "waitlisted":
            return "You will lose your place on the waitlist.";
        default:
            return "Your application will be withdrawn.";
    }
}

export function overlapText(title) {
    return `This event overlaps in time with ${title}. Are you sure you want to apply?`;
}
