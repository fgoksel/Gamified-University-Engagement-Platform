// Plain-language names of the capabilities in the registry
// (App\Enums\Capability). The server sends the same list to the role builder.
export const capabilityLabels = {
    "topic.view": "View topics",
    "topic.create": "Add topics",
    "topic.edit": "Edit any topic",
    "topic.organise": "Move topics",
    "topic.archive": "Archive topics",
    "access.view": "See who has access",
    "access.assign": "Give people roles",
    "access.end": "End and replace roles",
    "role.define": "Define roles",
};

export function capabilityLabel(value) {
    return capabilityLabels[value] ?? value;
}

export function formatDate(iso) {
    if (!iso) return "";
    return new Date(iso).toLocaleDateString(undefined, {
        year: "numeric",
        month: "short",
        day: "numeric",
    });
}

// "1 role assignment", "2 role assignments"
export function plural(count, one, many = `${one}s`) {
    return `${count} ${count === 1 ? one : many}`;
}
