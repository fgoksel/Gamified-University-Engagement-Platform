import {
    Award,
    BookOpen,
    Code,
    Dumbbell,
    FlaskConical,
    Flame,
    Heart,
    Lightbulb,
    Medal,
    Music,
    Palette,
    Rocket,
    Star,
    Trophy,
    Users,
} from "@lucide/vue";
import { t } from "./i18n.js";

// The badge icons (Task #125): each key the server accepts (Badge::ICONS) with its lucide icon and
// its name for the badge card. Written out in full, so a key is never turned into a component name.
const ICONS = {
    award: { component: Award, name: t("badges.icons.award") },
    trophy: { component: Trophy, name: t("badges.icons.trophy") },
    medal: { component: Medal, name: t("badges.icons.medal") },
    star: { component: Star, name: t("badges.icons.star") },
    flame: { component: Flame, name: t("badges.icons.flame") },
    rocket: { component: Rocket, name: t("badges.icons.rocket") },
    heart: { component: Heart, name: t("badges.icons.heart") },
    "book-open": { component: BookOpen, name: t("badges.icons.book_open") },
    code: { component: Code, name: t("badges.icons.code") },
    "flask-conical": {
        component: FlaskConical,
        name: t("badges.icons.flask_conical"),
    },
    dumbbell: { component: Dumbbell, name: t("badges.icons.dumbbell") },
    users: { component: Users, name: t("badges.icons.users") },
    lightbulb: { component: Lightbulb, name: t("badges.icons.lightbulb") },
    music: { component: Music, name: t("badges.icons.music") },
    palette: { component: Palette, name: t("badges.icons.palette") },
};

// A badge made before Task #125 has no icon: it shows the award, as the server says too.
export const DEFAULT_BADGE_ICON = "award";

export function badgeIcon(key) {
    return (ICONS[key] ?? ICONS[DEFAULT_BADGE_ICON]).component;
}

export function badgeIconName(key) {
    return (ICONS[key] ?? ICONS[DEFAULT_BADGE_ICON]).name;
}
