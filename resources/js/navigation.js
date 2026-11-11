import {
    Bell,
    CalendarDays,
    FolderTree,
    Home,
    IdCard,
    QrCode,
    ShieldCheck,
    Trophy,
    UserRound,
} from "@lucide/vue";
import { t } from "./i18n.js";

// Main menu of AppLayout, per role. One place to add or rename menu items.
// An item with "children" is a group heading with links under it.
// "badge" names a count in the shared "notifications" prop, shown as a pill.
// "match" (optional) decides when a link is the current page, from the path and the query.
// "needs" hides an item unless the backend shares that flag in "topics"
// (Topics is shown only to people who have access to at least one topic).

// Shared items, so the sidebar menu and the mobile bottom bar use the same labels.
const home = { label: t("navigation.home"), href: "/", icon: Home };
const events = {
    label: t("navigation.events"),
    href: "/events",
    icon: CalendarDays,
};
const scan = { label: t("navigation.scan"), href: "/scan", icon: QrCode };
const myEvents = {
    label: t("navigation.my_events"),
    href: "/my-events",
    icon: IdCard,
};
const organizedByMe = {
    label: t("navigation.organized_by_me"),
    href: "/my-events",
    icon: IdCard,
};
// Leaderboards is a group (redesign, 9 November): the highlighted boards, or all of them.
// "match" decides when a link is the current page, because both open /leaderboards.
const showsAll = (query) => query.get("show") === "all";
const leaderboards = {
    label: t("navigation.leaderboards"),
    icon: Trophy,
    children: [
        {
            label: t("navigation.highlighted"),
            href: "/leaderboards",
            match: (path, query) =>
                path === "/leaderboards" && !showsAll(query),
        },
        {
            label: t("navigation.all_leaderboards"),
            href: "/leaderboards?show=all",
            match: (path, query) => path === "/leaderboards" && showsAll(query),
        },
    ],
};
const notifications = {
    label: t("navigation.notifications"),
    href: "/notifications",
    icon: Bell,
    badge: "unread",
};
const profile = {
    label: t("navigation.profile"),
    href: "/profile",
    icon: UserRound,
};
const topics = {
    label: t("navigation.areas"),
    href: "/topics",
    icon: FolderTree,
    needs: "available",
};
const topicRoles = {
    label: t("navigation.roles"),
    href: "/topics/roles",
    icon: ShieldCheck,
    needs: "roles",
};

export const navigation = {
    teacher: [
        home,
        {
            label: t("navigation.events"),
            icon: CalendarDays,
            children: [
                { label: t("navigation.all_events"), href: events.href },
                { label: organizedByMe.label, href: organizedByMe.href },
                {
                    label: t("navigation.tag_requests"),
                    href: "/topic-tag-requests",
                },
            ],
        },
        leaderboards,
        topics,
        topicRoles,
        notifications,
        profile,
    ],
    student: [
        home,
        events,
        scan,
        {
            label: t("navigation.my_events_and_passport"),
            icon: IdCard,
            children: [
                { label: myEvents.label, href: myEvents.href },
                { label: t("navigation.my_qr_passport"), href: "/passport" },
            ],
        },
        leaderboards,
        profile,
        notifications,
        topics,
        topicRoles,
    ],
    // System Admins work in the admin panel; Leaderboards, Topics and Roles open here.
    admin: [
        leaderboards,
        topics,
        topicRoles,
        {
            label: t("navigation.admin_panel"),
            href: "/admin",
            icon: UserRound,
            external: true,
        },
    ],
};

// Mobile bottom bar of AppLayout (Task #93), per role. "center" draws the item
// as the large round button in the middle. An empty list shows no bar.
export const bottomBar = {
    student: [home, events, { ...scan, center: true }, myEvents, profile],
    teacher: [home, events, organizedByMe, profile],
    // System Admins work in the admin panel
    admin: [],
};
