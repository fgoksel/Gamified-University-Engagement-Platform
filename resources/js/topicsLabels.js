import { t, tc } from "./i18n.js";
// Plain-language names of the capabilities in the registry
// (App\Enums\Capability). The server sends the same list to the role builder.
export const capabilityLabels = {
    "topic.view": t("capabilities.view_areas"),
    "topic.create": t("capabilities.add_areas"),
    "topic.edit": t("capabilities.edit_any_area"),
    "topic.organise": t("capabilities.move_areas"),
    "topic.archive": t("capabilities.archive_areas"),
    "access.view": t("capabilities.see_who_has_access"),
    "access.assign": t("capabilities.give_people_roles"),
    "access.end": t("capabilities.end_and_replace_roles"),
    "access.import": t("capabilities.import_students"),
    "role.define": t("capabilities.define_roles"),
    "events.view": t("capabilities.view_events"),
    "events.apply": t("capabilities.apply_to_events"),
    "events.create": t("capabilities.create_draft_events"),
    "events.manage": t("capabilities.manage_events"),
    "applications.manage": t("capabilities.manage_applicants"),
    "points.credit": t("capabilities.credit_points"),
    "qr_codes.manage": t("capabilities.manage_event_qr_codes"),
    "leaderboards.manage": t("capabilities.manage_leaderboards"),
    "categories.manage": t("capabilities.manage_categories"),
};

// What a leaderboard can show to tell students apart (Task #89).
export const leaderboardColumnLabels = {
    neptun_code: t("leaderboards.columns.neptun_code"),
    first_name: t("leaderboards.columns.first_name"),
    last_name: t("leaderboards.columns.last_name"),
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

// "1 area", "2 areas"
export const topicCount = (count) => tc("areas.count.area", count);

// "1 person", "2 people"
export const personCount = (count) => tc("areas.count.person", count);

// "1 role assignment", "2 role assignments"
export const assignmentCount = (count) =>
    tc("areas.count.role_assignment", count);
