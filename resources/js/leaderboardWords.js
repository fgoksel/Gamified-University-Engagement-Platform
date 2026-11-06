// Plain words for the hall screen, the time on it and "Where am I?" (Task #56), in one place so
// the translations of #95 can move all of them into the dictionary in one go, like eventWords.js.

const pointsText = (n) => (n === 1 ? "1 point" : `${n} points`);

export const hallWords = {
    title: "Leaderboards",
    waiting: "Leaderboards will appear here shortly.",
    empty: "No leaderboards are published yet.",
    noPoints: "No points on this leaderboard yet.",
    rank: "Rank",
    points: "Points",
    pause: "Pause",
    resume: "Resume",
    students: (n) =>
        n === 1
            ? "1 student on this leaderboard"
            : `${n} students on this leaderboard`,
    position: (at, of) => `${at} of ${of}`,
};

export const screenTimeWords = {
    label: "Time on the hall screen",
    hint: (seconds) =>
        `Empty: use the default (${seconds} s). Otherwise 5 to 120 seconds.`,
};

export const publishWarning =
    "Published leaderboards are shown on the hall screen and to anyone without signing in.";

export const whereAmIWords = {
    titleStudent: "Where am I?",
    titleOthers: "Rankings",
    intro: "Pick an area, and a category or tag if you like.",
    area: "Area",
    chooseArea: "Choose an area…",
    category: "Category",
    allCategories: "All categories",
    tag: "Tag",
    allTags: "All tags",
    you: (rank, of, points) =>
        `You are number ${rank} of ${of}, with ${pointsText(points)}.`,
    toNext: (points, rank) => `${pointsText(points)} to reach number ${rank}.`,
    noPoints: "You have no points here yet.",
    noStudents: "No students have points here yet.",
    students: (n) =>
        n === 1
            ? "1 student has points here."
            : `${n} students have points here.`,
    namesNote: "Names show for the areas you manage.",
    listCaption: "Students in this ranking",
    rank: "Rank",
    name: "Name",
    neptunCode: "Neptun code",
    points: "Points",
    page: (page, of) => `Page ${page} of ${of}`,
    previous: "Previous",
    next: "Next",
};
