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

// Main menu of AppLayout, per role. One place to add or rename menu items.
// An item with "children" is a group heading with links under it.
// "framed" draws a border around the item (used for the QR shortcut).
// "badge" names a count in the shared "notifications" prop, shown as a pill.
// "needs" hides an item unless the backend shares that flag in "topics"
// (Topics is shown only to people who have access to at least one topic).
const home = { label: "Home", href: "/", icon: Home };
const notifications = {
    label: "Notifications",
    href: "/notifications",
    icon: Bell,
    badge: "unread",
};
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
        home,
        {
            label: "Events",
            icon: CalendarDays,
            children: [
                { label: "All events", href: "/events" },
                { label: "Organized by me", href: "/my-events" },
                { label: "Tag requests", href: "/topic-tag-requests" },
            ],
        },
        topics,
        topicRoles,
        notifications,
        profile,
    ],
    student: [
        home,
        { label: "Events", href: "/events", icon: CalendarDays },
        { label: "Scan", href: "/scan", icon: QrCode },
        {
            label: "My events and passport",
            icon: IdCard,
            children: [
                { label: "My events", href: "/my-events" },
                { label: "My QR passport", href: "/passport" },
            ],
        },
        profile,
        notifications,
        topics,
        topicRoles,
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
