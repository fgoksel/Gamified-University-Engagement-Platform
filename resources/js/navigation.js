import {
    CalendarDays,
    FolderTree,
    QrCode,
    ShieldCheck,
    Trophy,
    UserRound,
} from "@lucide/vue";

// Main menu of AppLayout, per role. One place to add or rename menu items.
// An item with "children" is a group heading with links under it.
// "framed" draws a border around the item (used for the QR shortcut).
// "needs" hides an item unless the backend shares that flag in "topics"
// (Topics is shown only to people who have access to at least one topic).
const leaderboard = { label: "Leaderboard", href: "/", icon: Trophy };
const profile = { label: "Profile", href: "/profile", icon: UserRound };
const topics = {
    label: "Topics",
    href: "/topics",
    icon: FolderTree,
    needs: "available",
};
const topicRoles = {
    label: "Roles",
    href: "/topics/roles",
    icon: ShieldCheck,
    needs: "roles",
};

export const navigation = {
    teacher: [
        leaderboard,
        {
            label: "Events",
            icon: CalendarDays,
            children: [
                { label: "All events", href: "/events" },
                { label: "Organized by me", href: "/my-events" },
            ],
        },
        topics,
        topicRoles,
        profile,
    ],
    student: [
        leaderboard,
        {
            label: "Events",
            icon: CalendarDays,
            children: [
                { label: "All events", href: "/events" },
                { label: "My events", href: "/my-events" },
            ],
        },
        topics,
        topicRoles,
        profile,
        { label: "Scan QR code", href: "/scan", icon: QrCode, framed: true },
    ],
    // System Admins work in the admin panel; Topics and Roles open here.
    admin: [
        topics,
        topicRoles,
        {
            label: "Admin panel",
            href: "/admin",
            icon: UserRound,
            external: true,
        },
    ],
};
