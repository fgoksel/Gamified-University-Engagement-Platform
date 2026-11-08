// The test language (Task #95). Every text comes out accented and wrapped in ⟦ ⟧, so a screen
// shows at a glance which text is still typed into the code: it has neither brackets nor accents.
// It is made from the English text while the page runs, so there is no file to keep up to date.
// Laravel does the same in app/Support/PseudoLocale.php. Keep the two alike.
export const PSEUDO = "qps";

const LETTERS = {
    a: "á",
    b: "ƀ",
    c: "ç",
    d: "đ",
    e: "é",
    f: "ƒ",
    g: "ĝ",
    h: "ĥ",
    i: "í",
    j: "ĵ",
    k: "ķ",
    l: "ł",
    m: "ɱ",
    n: "ñ",
    o: "ó",
    p: "ƥ",
    q: "ɋ",
    r: "ŕ",
    s: "š",
    t: "ţ",
    u: "ú",
    v: "ṽ",
    w: "ŵ",
    x: "ẋ",
    y: "ý",
    z: "ž",
    A: "Á",
    B: "Ɓ",
    C: "Ç",
    D: "Đ",
    E: "É",
    F: "Ƒ",
    G: "Ĝ",
    H: "Ĥ",
    I: "Í",
    J: "Ĵ",
    K: "Ķ",
    L: "Ł",
    M: "Ṁ",
    N: "Ñ",
    O: "Ó",
    P: "Ƥ",
    Q: "Ɋ",
    R: "Ŕ",
    S: "Š",
    T: "Ţ",
    U: "Ú",
    V: "Ṽ",
    W: "Ŵ",
    X: "Ẋ",
    Y: "Ý",
    Z: "Ž",
};

// What stays as it is, so the made-up texts still work: :placeholders, HTML tags and entities.
const KEEP = /(:[A-Za-z_][A-Za-z0-9_]*|<[^>]*>|&[#A-Za-z0-9]+;)/;

// One plural form: the numbers it starts with ({1}, [2,*]) stay too.
function form(text) {
    const found = text.match(/^([{[][^[\]{}]*[}\]])(\s*)([\s\S]*)$/);
    const [numbers, gap, words] = found
        ? [found[1], found[2], found[3]]
        : ["", "", text];

    const converted = words
        .split(KEEP)
        .map((part, index) =>
            index % 2 === 1
                ? part
                : [...part].map((letter) => LETTERS[letter] ?? letter).join(""),
        )
        .join("");

    return `${numbers}${gap}⟦${converted}⟧`;
}

export function pseudo(text) {
    return text.split("|").map(form).join("|");
}
