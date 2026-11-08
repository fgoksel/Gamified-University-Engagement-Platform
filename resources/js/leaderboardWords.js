// Plain words for the hall screen, the time on it and "Where am I?" (Task #56), in one place and
// read from the dictionary (Task #95), like eventWords.js.
import { t, tc } from "./i18n.js";

const pointsText = (n) => tc("leaderboards.points_count", n);

export const hallWords = {
    title: t("leaderboards.hall.title"),
    waiting: t("leaderboards.hall.waiting"),
    empty: t("leaderboards.hall.empty"),
    noPoints: t("leaderboards.hall.no_points"),
    rank: t("leaderboards.hall.rank"),
    points: t("leaderboards.hall.points"),
    pause: t("leaderboards.hall.pause"),
    resume: t("leaderboards.hall.resume"),
    students: (n) => tc("leaderboards.hall.students", n),
    position: (at, of) => t("leaderboards.hall.position", { at, of }),
};

export const screenTimeWords = {
    label: t("leaderboards.screen_time.label"),
    hint: (seconds) => t("leaderboards.screen_time.hint", { seconds }),
};

export const whereAmIWords = {
    titleStudent: t("leaderboards.where_am_i.title_student"),
    titleOthers: t("leaderboards.where_am_i.title_others"),
    intro: t("leaderboards.where_am_i.intro"),
    area: t("leaderboards.where_am_i.area"),
    chooseArea: t("leaderboards.where_am_i.choose_area"),
    categoryLabel: t("leaderboards.where_am_i.category"),
    allCategories: t("leaderboards.where_am_i.all_categories"),
    tag: t("leaderboards.where_am_i.tag"),
    allTags: t("leaderboards.where_am_i.all_tags"),
    you: (rank, of, points) =>
        t("leaderboards.where_am_i.you", {
            rank,
            of,
            points: pointsText(points),
        }),
    toNext: (points, rank) =>
        t("leaderboards.where_am_i.to_next", {
            points: pointsText(points),
            rank,
        }),
    noPoints: t("leaderboards.where_am_i.no_points"),
    noStudents: t("leaderboards.where_am_i.no_students"),
    students: (n) => tc("leaderboards.where_am_i.students", n),
    namesNote: t("leaderboards.where_am_i.names_note"),
    listCaption: t("leaderboards.where_am_i.list_caption"),
    rank: t("leaderboards.where_am_i.rank"),
    name: t("leaderboards.where_am_i.name"),
    neptunCode: t("leaderboards.where_am_i.neptun_code"),
    points: t("leaderboards.where_am_i.points"),
    page: (page, of) => t("leaderboards.where_am_i.page", { page, of }),
    previous: t("leaderboards.where_am_i.previous"),
    next: t("leaderboards.where_am_i.next"),
};
