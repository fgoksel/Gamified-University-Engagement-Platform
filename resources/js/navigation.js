import { CalendarDays, Network, QrCode, Trophy, UserRound } from "@lucide/vue";

// Main menu of AppLayout, per role. One place to add or rename menu items.
// An item with "children" is a group heading with links under it.
// "framed" draws a border around the item (used for the QR shortcut).
const leaderboard = { label: "Leaderboard", href: "/", icon: Trophy };
const profile = { label: "Profile", href: "/profile", icon: UserRound };

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
        // Deans, teachers and co-teachers manage their courses here
        { label: "My courses", href: "/my-courses", icon: Network },
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
        profile,
        { label: "Scan QR code", href: "/scan", icon: QrCode, framed: true },
    ],
};
