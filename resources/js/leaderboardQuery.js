// The Leaderboards page keeps every choice in the address (redesign of 9 November): the list
// (?show=all), the selected board (?board=), the whole ranking (?places=all) and the choice of
// "Where am I?" (?area=, ?category=, ?tag=). A change keeps the others, so a reload or a shared
// link shows the same page. An empty value leaves the address.
export function leaderboardQuery(changes) {
    const params = Object.fromEntries(
        new URLSearchParams(window.location.search),
    );

    for (const [key, value] of Object.entries(changes)) {
        if (value === undefined || value === null || value === "") {
            delete params[key];
        } else {
            params[key] = String(value);
        }
    }

    return params;
}

// How a student is named on a leaderboard: only the columns its creator chose (L6).
export function studentLabel(row, columns) {
    return columns
        .map((key) => row?.[key])
        .filter(Boolean)
        .join(" ");
}
