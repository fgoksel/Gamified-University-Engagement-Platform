import {
    Bell,
    CalendarDays,
    FolderTree,
    Home,
    IdCard,
    QrCode,
    ShieldCheck,
    UserRound,
} from "@lucide/vue";

// Main menu of AppLayout, per role. One place to add or rename menu items.
// An item with "children" is a group heading with links under it.
// "badge" names a count in the shared "notifications" prop, shown as a pill.
// "needs" hides an item unless the backend shares that flag in "topics"
// (Topics is shown only to people who have access to at least one topic).

// Shared items, so the sidebar menu and the mobile bottom bar use the same labels.
const home = { label: "Home", href: "/", icon: Home };
const events = { label: "Events", href: "/events", icon: CalendarDays };
const scan = { label: "Scan", href: "/scan", icon: QrCode };
const myEvents = { label: "My events", href: "/my-events", icon: IdCard };
const organizedByMe = {
    label: "Organized by me",
    href: "/my-events",
    icon: IdCard,
};
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
                { label: "All events", href: events.href },
                { label: organizedByMe.label, href: organizedByMe.href },
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
        events,
        scan,
        {
            label: "My events and passport",
            icon: IdCard,
            children: [
                { label: myEvents.label, href: myEvents.href },
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

// Mobile bottom bar of AppLayout (Task #93), per role. "center" draws the item
// as the large round button in the middle. An empty list shows no bar.
export const bottomBar = {
    student: [home, events, { ...scan, center: true }, myEvents, profile],
    teacher: [home, events, organizedByMe, profile],
    // System Admins work in the admin panel
    admin: [],
};
