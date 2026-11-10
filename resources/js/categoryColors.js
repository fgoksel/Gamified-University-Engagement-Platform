import { usePage } from "@inertiajs/vue3";

// The category list (Task #116) comes with every page from the server (HandleInertiaRequests):
// [{ slug, name, color, retired }]. Nothing here names a category, so one the admin adds shows
// with its name and colour everywhere, without a code change.
//
// categoryLabel[slug], categoryDot[slug] and categorySoft[slug] answer for every stored slug,
// retired ones too, so old events and points keep their name. Looping over them (v-for,
// Object.keys) gives only the categories in use: what a picker offers.

const page = usePage();

function list() {
    return page.props?.categories ?? [];
}

function find(slug) {
    return typeof slug === "string"
        ? list().find((category) => category.slug === slug)
        : undefined;
}

// Tailwind classes per colour (tokens in app.css). Written out in full so Tailwind finds them
// when it scans the code.
const DOT = {
    violet: "bg-cat-violet",
    green: "bg-cat-green",
    orange: "bg-cat-orange",
    pink: "bg-cat-pink",
    blue: "bg-cat-blue",
    teal: "bg-cat-teal",
    amber: "bg-cat-amber",
    slate: "bg-cat-slate",
};

// A soft tone behind and the strong tone for text: AA in both themes.
const SOFT = {
    violet: "bg-cat-violet-soft text-cat-violet-fg",
    green: "bg-cat-green-soft text-cat-green-fg",
    orange: "bg-cat-orange-soft text-cat-orange-fg",
    pink: "bg-cat-pink-soft text-cat-pink-fg",
    blue: "bg-cat-blue-soft text-cat-blue-fg",
    teal: "bg-cat-teal-soft text-cat-teal-fg",
    amber: "bg-cat-amber-soft text-cat-amber-fg",
    slate: "bg-cat-slate-soft text-cat-slate-fg",
};

// An object keyed by slug whose values follow the list of the current page.
function bySlug(pick) {
    return new Proxy(
        {},
        {
            get: (target, slug) => {
                const category = find(slug);
                return category ? pick(category) : undefined;
            },
            has: (target, slug) => find(slug) !== undefined,
            ownKeys: () =>
                list()
                    .filter((category) => !category.retired)
                    .map((category) => category.slug),
            getOwnPropertyDescriptor: (target, slug) => {
                const category = find(slug);
                return category && !category.retired
                    ? {
                          value: pick(category),
                          enumerable: true,
                          configurable: true,
                          writable: false,
                      }
                    : undefined;
            },
        },
    );
}

export const categoryLabel = bySlug((category) => category.name);
export const categoryDot = bySlug(
    (category) => DOT[category.color] ?? DOT.slate,
);
export const categorySoft = bySlug(
    (category) => SOFT[category.color] ?? SOFT.slate,
);

/**
 * The categories a picker offers: the ones in use, and "keep" (the value a record already has)
 * even if it was retired since. [{ value, label, dot }]
 */
export function categoryOptions(keep = null) {
    return list()
        .filter((category) => !category.retired || category.slug === keep)
        .map((category) => ({
            value: category.slug,
            label: category.name,
            dot: DOT[category.color] ?? DOT.slate,
        }));
}
