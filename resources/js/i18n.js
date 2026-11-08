// The one dictionary of the app (Task #95): lang/<locale>.json. Laravel reads the same file
// (__('key')), so a new language is added by translating that file and nothing else.
//
// Keys are always written out in full, never built from variables, so a search (and
// tests/Feature/TranslationsTest.php) finds every place a text is used.
import { PSEUDO, pseudo } from "./pseudoLocale.js";

const dictionaries = import.meta.glob("../../lang/*.json", {
    eager: true,
    import: "default",
});

const FALLBACK = "en";

// The server writes the locale into <html lang="...">. It only changes with a page load.
export const locale = (document.documentElement.lang || FALLBACK).replace(
    "-",
    "_",
);

let madeUp = null;

function dictionary(code) {
    // The test language is made from the English texts, there is no file for it.
    if (code === PSEUDO) {
        madeUp ??= Object.fromEntries(
            Object.entries(dictionary(FALLBACK)).map(([key, text]) => [
                key,
                pseudo(text),
            ]),
        );

        return madeUp;
    }

    return dictionaries[`../../lang/${code}.json`] ?? {};
}

function lineFor(key) {
    return dictionary(locale)[key] ?? dictionary(FALLBACK)[key];
}

/**
 * Fills in :name, :Name and :NAME like Laravel does, so one text works in both.
 */
function fill(text, params) {
    const replacements = {};

    for (const [name, raw] of Object.entries(params)) {
        const value = String(raw ?? "");
        replacements[`:${name}`] = value;
        replacements[`:${name.charAt(0).toUpperCase()}${name.slice(1)}`] =
            value.charAt(0).toUpperCase() + value.slice(1);
        replacements[`:${name.toUpperCase()}`] = value.toUpperCase();
    }

    const placeholders = Object.keys(replacements).sort(
        (a, b) => b.length - a.length,
    );

    if (placeholders.length === 0) return text;

    const pattern = new RegExp(
        placeholders
            .map((placeholder) =>
                placeholder.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"),
            )
            .join("|"),
        "g",
    );

    return text.replace(pattern, (found) => replacements[found]);
}

/**
 * The text for a key, with its :placeholders filled in. A key that is missing in the
 * current language falls back to English, and in development it is reported.
 */
export function t(key, params = {}) {
    const text = lineFor(key);

    if (text === undefined) {
        if (import.meta.env?.DEV) {
            // eslint-disable-next-line no-restricted-syntax -- a message for developers, not interface text
            console.warn(`[i18n] Missing translation: ${key}`);
        }

        return key;
    }

    return fill(text, params);
}

// "{1} one student" or "[2,*] :count students": the numbers a form is for, and its text.
function parseForm(form) {
    const found = form.match(/^\s*[{[]([^[\]{}]*)[}\]]([\s\S]*)/);

    return found
        ? { condition: found[1], text: found[2].trim() }
        : { condition: null, text: form.trim() };
}

function isFor(condition, count) {
    if (!condition.includes(",")) return count === Number(condition);

    const [from, to] = condition.split(",", 2);

    return (
        (to === "*" && count >= Number(from)) ||
        (from === "*" && count <= Number(to)) ||
        (count >= Number(from) && count <= Number(to))
    );
}

/**
 * Picks the form of a text that fits a number, like Laravel's trans_choice(). Each form
 * starts with the numbers it is for, "{1} one student|[0,*] :count students", so no
 * language needs rules of its own. :count is filled in. When no form fits (a negative
 * number), the last one is used.
 */
export function tc(key, count, params = {}) {
    const forms = t(key).split("|").map(parseForm);
    const form =
        forms.find(
            (candidate) =>
                candidate.condition !== null &&
                isFor(candidate.condition, count),
        ) ?? forms[forms.length - 1];

    return fill(form.text, { count, ...params });
}

export default {
    install(app) {
        app.config.globalProperties.$t = t;
        app.config.globalProperties.$tc = tc;
    },
};
