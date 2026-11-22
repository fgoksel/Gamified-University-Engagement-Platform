// Gold, silver and bronze for places 1-3 (N11, Task #126). Tokens in app.css, AA in both themes:
// "soft" is a background with its text colour, "stripe" a coloured edge, "color" text or an icon. Written out in
// full so Tailwind finds the classes. Places from 4 on get nothing (null).
const MEDALS = {
    1: {
        soft: "bg-medal-gold-soft text-medal-gold-fg",
        stripe: "bg-medal-gold",
        color: "text-medal-gold-fg",
        border: "border-medal-gold",
    },
    2: {
        soft: "bg-medal-silver-soft text-medal-silver-fg",
        stripe: "bg-medal-silver",
        color: "text-medal-silver-fg",
        border: "border-medal-silver",
    },
    3: {
        soft: "bg-medal-bronze-soft text-medal-bronze-fg",
        stripe: "bg-medal-bronze",
        color: "text-medal-bronze-fg",
        border: "border-medal-bronze",
    },
};

export function medal(rank) {
    return MEDALS[rank] ?? null;
}
