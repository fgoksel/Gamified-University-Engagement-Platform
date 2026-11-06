import { t } from "./i18n.js";

// Tailwind classes per subject area / event category (tokens in app.css).
// Written out in full so Tailwind finds them when it scans the code.
export const categoryLabel = {
    academic: t("categories.academic"),
    scientific: t("categories.scientific"),
    sports: t("categories.sports"),
    community: t("categories.community_short"),
};

export const categoryDot = {
    academic: "bg-cat-academic",
    scientific: "bg-cat-scientific",
    sports: "bg-cat-sports",
    community: "bg-cat-community",
};
