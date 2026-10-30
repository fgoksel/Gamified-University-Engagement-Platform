// Task #61: accessibility check of the main screens with axe and Lighthouse.
//
// Run it before the fixes and again after them, against the Sprint 2 demo data
// (php artisan db:seed --class=Sprint2DemoSeeder), so the two results compare
// page by page. Tool versions are pinned in package.json for the same reason.
//
//   node run.mjs <output folder>
//
// A11Y_BASE_URL is the app's address (default http://127.0.0.1:8099) and
// A11Y_LABEL says what was measured, for example "dev 9a2e918, before".
// Chrome must be installed; Playwright uses it instead of downloading one.

import fs from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { AxeBuilder } from "@axe-core/playwright";
import lighthouse from "lighthouse";
import { chromium } from "playwright-core";

const BASE = (process.env.A11Y_BASE_URL ?? "http://127.0.0.1:8099").replace(
    /\/$/,
    "",
);
const LABEL = process.env.A11Y_LABEL ?? "";
const OUT = path.resolve(
    process.argv[2] ?? `results-${new Date().toISOString().slice(0, 10)}`,
);
const DEBUG_PORT = 9333;

// Sprint2DemoSeeder::PASSWORD
const PASSWORD = "password";

// WCAG 2.2 AA (Technical Spec 8.3) plus axe's best practices, which are reported separately.
const AXE_TAGS = [
    "wcag2a",
    "wcag2aa",
    "wcag21a",
    "wcag21aa",
    "wcag22aa",
    "best-practice",
];

// axe looks at each page at both sizes, because the phone layout differs (menu, bottom bar).
// Lighthouse uses its own phone emulation, its default.
const VIEWPORTS = {
    desktop: { width: 1280, height: 800 },
    phone: { width: 390, height: 844 },
};

// The main flows (#96: find and apply to an event, check in, show the passport) and the
// organiser's screens. {event:Title} becomes that demo event's id.
const RUNS = [
    {
        who: "Guest",
        email: null,
        pages: [
            ["Log in", "/login"],
            ["Forgot password", "/forgot-password"],
        ],
    },
    {
        who: "Student (Anna)",
        email: "sprint2.anna@example.test",
        pages: [
            ["Home", "/"],
            ["All events", "/events"],
            [
                "Event page, open for applications",
                "/events/{event:SQL Joins Workshop}",
            ],
            [
                "Event page, accepted and running",
                "/events/{event:Indexing Lab}",
            ],
            ["My events", "/my-events"],
            ["QR passport", "/passport"],
            ["Scan", "/scan"],
            ["Notifications", "/notifications"],
            ["Profile", "/profile"],
            ["Change password", "/password/change"],
        ],
    },
    {
        who: "Organiser (Ivan)",
        email: "sprint2.ivan@example.test",
        pages: [
            ["Organized by me", "/my-events"],
            ["Create event", "/events/create"],
            ["Edit event", "/events/{event:SQL Joins Workshop}/edit"],
            ["Applicants", "/events/{event:SQL Joins Workshop}/applicants"],
            ["Scan attendance (passport)", "/events/{event:Indexing Lab}/scan"],
            ["QR codes", "/events/{event:Indexing Lab}/qr-codes"],
            ["Points import", "/events/{event:Indexing Lab}/points-import"],
            // Opens the first topic the organiser may see. The Roles page is for system
            // admins only, so it is not measured here.
            ["Topics", "/topics"],
        ],
    },
];

const IMPACTS = ["critical", "serious", "moderate", "minor"];

async function main() {
    await fs.mkdir(OUT, { recursive: true });
    const profile = await fs.mkdtemp(path.join(os.tmpdir(), "gup-a11y-"));

    const context = await chromium.launchPersistentContext(profile, {
        channel: "chrome",
        headless: true,
        // Lighthouse joins the same browser through this port, so it sees the signed-in session.
        // The camera pages get a fake camera instead of an error.
        args: [
            `--remote-debugging-port=${DEBUG_PORT}`,
            "--use-fake-ui-for-media-stream",
            "--use-fake-device-for-media-stream",
        ],
    });
    await context.grantPermissions(["camera"], { origin: BASE });

    const page = context.pages()[0] ?? (await context.newPage());
    const results = [];
    let chrome = "";

    try {
        for (const run of RUNS) {
            await context.clearCookies();

            if (run.email) {
                await logIn(page, run.email);
            }

            const events = run.email ? await eventIds(page) : {};

            for (const [name, pattern] of run.pages) {
                const route = pattern.replace(
                    /\{event:([^}]+)\}/g,
                    (_, title) => {
                        if (!(title in events)) {
                            throw new Error(
                                `${run.who} cannot find the demo event "${title}". Found: ${Object.keys(events).join(", ")}`,
                            );
                        }

                        return events[title];
                    },
                );

                process.stdout.write(`${run.who}: ${name} (${route}) ... `);
                const result = await checkPage(page, run.who, name, route);
                chrome ||= result.chrome ?? "";
                delete result.chrome;
                results.push(result);
                console.log(
                    result.error
                        ? `ERROR ${result.error}`
                        : `Lighthouse ${result.lighthouse.score}, axe ${countLine(result)}`,
                );
            }
        }
    } finally {
        await context.close();
        await fs.rm(profile, { recursive: true, force: true });
    }

    const meta = {
        label: LABEL,
        date: new Date().toISOString(),
        baseUrl: BASE,
        tools: {
            lighthouse: await version("lighthouse"),
            "axe-core": await version("axe-core"),
            "playwright-core": await version("playwright-core"),
            chrome,
        },
        axeTags: AXE_TAGS,
        viewports: VIEWPORTS,
        lighthouse: "accessibility category only, default phone emulation",
    };

    await fs.writeFile(
        path.join(OUT, "results.json"),
        JSON.stringify({ meta, results }, null, 2),
    );
    await fs.writeFile(path.join(OUT, "summary.md"), summary(meta, results));
    console.log(`\nWritten to ${OUT}`);
}

async function logIn(page, email) {
    await page.goto(`${BASE}/login`);
    await page.getByLabel("Email address").fill(email);
    await page.getByLabel("Password", { exact: true }).fill(PASSWORD);
    await Promise.all([
        page.waitForURL((url) => !url.pathname.startsWith("/login")),
        page.getByRole("button", { name: "Log in" }).click(),
    ]);
}

/**
 * Event title => id, read from the page data Inertia sends with the event lists,
 * so a reseeded database with other ids still measures the same events.
 */
async function eventIds(page) {
    const found = {};

    for (const route of ["/events", "/my-events"]) {
        await page.goto(`${BASE}${route}`);
        const data = await page.evaluate(() => {
            const script = document.querySelector('script[data-page="app"]');

            return script
                ? JSON.parse(script.textContent)
                : (window.history.state?.page ?? null);
        });

        collect(data?.props, found);
    }

    return found;
}

function collect(value, found) {
    if (Array.isArray(value)) {
        value.forEach((item) => collect(item, found));
    } else if (value && typeof value === "object") {
        const looksLikeEvent =
            typeof value.id === "number" &&
            typeof value.title === "string" &&
            ("points" in value ||
                "event_starts_at" in value ||
                "starts_at" in value);

        if (looksLikeEvent && !(value.title in found)) {
            found[value.title] = value.id;
        }

        Object.values(value).forEach((item) => collect(item, found));
    }
}

async function checkPage(page, who, name, route) {
    const url = `${BASE}${route}`;
    const result = { who, name, route };

    try {
        result.axe = {};

        for (const [size, viewport] of Object.entries(VIEWPORTS)) {
            await page.setViewportSize(viewport);
            const response = await page.goto(url, { waitUntil: "networkidle" });
            // Let transitions and late renders finish before axe reads the page.
            await page.waitForTimeout(750);

            result.status = response?.status();
            result.finalUrl = page.url().replace(BASE, "");

            if (size === "phone") {
                result.smallTargets = await smallTargets(page);
            }

            const axe = await new AxeBuilder({ page })
                .withTags(AXE_TAGS)
                .analyze();
            result.axe[size] = axe.violations.map((violation) => ({
                id: violation.id,
                impact: violation.impact,
                help: violation.help,
                helpUrl: violation.helpUrl,
                wcag: violation.tags.filter((tag) => /^wcag\d{3,}$/.test(tag)),
                bestPractice: violation.tags.includes("best-practice"),
                count: violation.nodes.length,
                elements: violation.nodes.slice(0, 8).map(describe),
            }));
        }

        const { lhr } = await lighthouse(url, {
            port: DEBUG_PORT,
            output: "json",
            logLevel: "error",
            onlyCategories: ["accessibility"],
            // Keep the session: without this Lighthouse signs the user out first.
            disableStorageReset: true,
        });

        const category = lhr.categories.accessibility;
        result.lighthouse = {
            score: Math.round(category.score * 100),
            finalUrl: (lhr.finalDisplayedUrl ?? "").replace(BASE, ""),
            failing: category.auditRefs
                .map((ref) => ({ ref, audit: lhr.audits[ref.id] }))
                .filter(
                    ({ audit }) =>
                        audit.scoreDisplayMode === "binary" &&
                        audit.score === 0,
                )
                .map(({ ref, audit }) => ({
                    id: audit.id,
                    title: audit.title,
                    weight: ref.weight,
                    items: audit.details?.items?.length ?? 0,
                })),
            warnings: lhr.runWarnings,
        };
        result.chrome = (lhr.environment?.hostUserAgent ?? "").match(
            /Chrome\/[\d.]+/,
        )?.[0];
    } catch (error) {
        result.error = String(error?.message ?? error).split("\n")[0];
    }

    return result;
}

/**
 * Controls smaller than 44 x 44 px on the phone layout (#61 asks for 44 px; axe's
 * target-size rule only checks WCAG 2.5.8's 24 px). A checkbox or radio counts with
 * its label, and a link inside a sentence is exempt, as in WCAG 2.5.5.
 */
async function smallTargets(page, min = 44) {
    const found = await page.evaluate((min) => {
        const controls =
            'a[href], button, input:not([type="hidden"]), select, textarea, summary, [role="button"], [role="link"], [role="tab"]';
        const small = [];

        for (const element of document.querySelectorAll(controls)) {
            const style = getComputedStyle(element);
            let box = element.getBoundingClientRect();

            // Hidden, or visually hidden and worked through a visible button (a file input).
            if (
                style.visibility === "hidden" ||
                box.width <= 2 ||
                box.height <= 2
            ) {
                continue;
            }

            if (
                element.matches('input[type="checkbox"], input[type="radio"]')
            ) {
                const label =
                    element.closest("label") ??
                    (element.id
                        ? document.querySelector(
                              `label[for="${CSS.escape(element.id)}"]`,
                          )
                        : null);
                box = label ? label.getBoundingClientRect() : box;
            }

            if (
                element.matches("a") &&
                style.display === "inline" &&
                element.closest("p, li")
            ) {
                continue;
            }

            if (box.width >= min && box.height >= min) {
                continue;
            }

            const name = (
                element.getAttribute("aria-label") ||
                element.innerText ||
                element.value ||
                element.title ||
                ""
            )
                .trim()
                .replace(/\s+/g, " ")
                .slice(0, 40);
            const kind =
                element.tagName.toLowerCase() +
                (element.tagName === "INPUT" ? `[${element.type}]` : "");
            small.push(
                `${kind} "${name}" ${Math.round(box.width)}x${Math.round(box.height)}`,
            );
        }

        return small;
    }, min);

    // The same control repeated in a list (every card's button) counts once, with how often.
    const repeats = new Map();
    found.forEach((item) => repeats.set(item, (repeats.get(item) ?? 0) + 1));

    return {
        count: found.length,
        controls: [...repeats].map(([item, times]) =>
            times > 1 ? `${item} (x${times})` : item,
        ),
    };
}

/** The element's selector, and for a contrast failure the colours and the ratio it needs. */
function describe(node) {
    const target = node.target.join(" ");
    const contrast = node.any?.find(
        (check) => check.id === "color-contrast",
    )?.data;

    if (!contrast?.contrastRatio) {
        return target;
    }

    return `${target} (${contrast.fgColor} on ${contrast.bgColor}: ${contrast.contrastRatio}:1, needs ${contrast.expectedContrastRatio})`;
}

/** One rule per page: the union of both sizes, the larger element count. */
function rulesOf(result) {
    const rules = new Map();

    for (const [size, violations] of Object.entries(result.axe ?? {})) {
        for (const violation of violations) {
            const seen = rules.get(violation.id);

            if (seen) {
                seen.count = Math.max(seen.count, violation.count);
                seen.sizes.push(size);
            } else {
                rules.set(violation.id, { ...violation, sizes: [size] });
            }
        }
    }

    return [...rules.values()];
}

function counts(result) {
    const byImpact = Object.fromEntries(IMPACTS.map((impact) => [impact, 0]));

    for (const rule of rulesOf(result)) {
        byImpact[rule.impact] += 1;
    }

    return byImpact;
}

function countLine(result) {
    const byImpact = counts(result);

    return IMPACTS.map((impact) => `${byImpact[impact]} ${impact}`).join(", ");
}

function summary(meta, results) {
    const lines = [];
    const measured = results.filter((result) => !result.error);
    const scores = measured.map((result) => result.lighthouse.score);
    const average = scores.length
        ? Math.round(
              scores.reduce((sum, score) => sum + score, 0) / scores.length,
          )
        : 0;

    lines.push(
        `# Accessibility check${meta.label ? `: ${meta.label}` : ""}`,
        "",
    );
    lines.push(
        `Measured ${meta.date.slice(0, 16).replace("T", " ")} UTC at ${meta.baseUrl} with Lighthouse ${meta.tools.lighthouse} (accessibility only, phone emulation), axe-core ${meta.tools["axe-core"]} (WCAG 2.2 A/AA and best practices, at ${Object.entries(
            meta.viewports,
        )
            .map(([size, { width, height }]) => `${size} ${width}x${height}`)
            .join(" and ")}) and ${meta.tools.chrome || "Chrome"}.`,
        "",
    );
    lines.push(
        `Pages: ${results.length}. Average Lighthouse accessibility score: ${average}. Lowest: ${scores.length ? Math.min(...scores) : "-"}.`,
        "",
    );

    lines.push(
        "| Who | Page | Lighthouse | axe critical | axe serious | axe moderate | axe minor | Controls under 44 px (phone) |",
    );
    lines.push("| --- | --- | --- | --- | --- | --- | --- | --- |");

    for (const result of results) {
        if (result.error) {
            lines.push(
                `| ${result.who} | ${result.name} | error: ${result.error} | | | | | |`,
            );
            continue;
        }

        const byImpact = counts(result);
        const moved =
            result.finalUrl !== result.route
                ? ` (ended at ${result.finalUrl})`
                : "";
        lines.push(
            `| ${result.who} | ${result.name}${moved} | ${result.lighthouse.score} | ${IMPACTS.map((impact) => byImpact[impact]).join(" | ")} | ${result.smallTargets?.count ?? ""} |`,
        );
    }

    // The same rule across pages: what to fix once, in a shared component, rather than per page.
    const byRule = new Map();

    for (const result of measured) {
        for (const rule of rulesOf(result)) {
            const entry = byRule.get(rule.id) ?? {
                ...rule,
                example: rule.elements[0] ?? "",
                pages: [],
                elements: 0,
            };
            entry.pages.push(`${result.who}: ${result.name}`);
            entry.elements += rule.count;
            byRule.set(rule.id, entry);
        }
    }

    const rules = [...byRule.values()].sort(
        (a, b) =>
            IMPACTS.indexOf(a.impact) - IMPACTS.indexOf(b.impact) ||
            b.pages.length - a.pages.length,
    );

    lines.push("", "## axe issues by rule", "");

    if (rules.length === 0) {
        lines.push("None.");
    } else {
        lines.push(
            "| Impact | Rule | What it checks | WCAG | Pages | Elements | For example |",
        );
        lines.push("| --- | --- | --- | --- | --- | --- | --- |");

        for (const rule of rules) {
            const wcag = rule.wcag.length
                ? rule.wcag.map(criterion).join(", ")
                : rule.bestPractice
                  ? "best practice"
                  : "";
            const example = `\`${rule.example.split(" (#")[0].slice(0, 70).replace(/\|/g, "\\|")}\``;
            lines.push(
                `| ${rule.impact} | [${rule.id}](${rule.helpUrl}) | ${rule.help.replace(/\|/g, "\\|")} | ${wcag} | ${rule.pages.length} | ${rule.elements} | ${example} |`,
            );
        }
    }

    const failing = new Map();

    for (const result of measured) {
        for (const audit of result.lighthouse.failing) {
            const entry = failing.get(audit.id) ?? { ...audit, pages: 0 };
            entry.pages += 1;
            failing.set(audit.id, entry);
        }
    }

    lines.push("", "## Lighthouse audits that failed", "");

    if (failing.size === 0) {
        lines.push("None.");
    } else {
        lines.push("| Audit | Weight | Pages |", "| --- | --- | --- |");

        for (const audit of [...failing.values()].sort(
            (a, b) => b.pages - a.pages || b.weight - a.weight,
        )) {
            lines.push(
                `| ${audit.title.replace(/\|/g, "\\|")} | ${audit.weight} | ${audit.pages} |`,
            );
        }
    }

    lines.push("", "## Elements per page (serious and critical)", "");

    for (const result of measured) {
        const rules = rulesOf(result).filter(
            (rule) => rule.impact === "critical" || rule.impact === "serious",
        );

        if (rules.length === 0) {
            continue;
        }

        lines.push(`### ${result.who}: ${result.name}`, "");

        for (const rule of rules) {
            const elements = rule.elements.map((element) => {
                const [, target, detail] = element.match(
                    /^(.*?)(?: \((#.*)\))?$/,
                );

                return detail ? `\`${target}\` (${detail})` : `\`${target}\``;
            });
            lines.push(
                `- ${rule.impact}, ${rule.id} (${rule.sizes.join(" and ")}): ${elements.join(", ")}${rule.count > rule.elements.length ? `, and ${rule.count - rule.elements.length} more` : ""}`,
            );
        }

        lines.push("");
    }

    lines.push("## Controls under 44 x 44 px on the phone layout", "");

    for (const result of measured) {
        if (result.smallTargets?.count) {
            lines.push(
                `- ${result.who}: ${result.name}: ${result.smallTargets.controls.join("; ")}`,
            );
        }
    }

    return `${lines.join("\n")}\n`;
}

/** "wcag143" => "1.4.3" */
function criterion(tag) {
    const digits = tag.replace("wcag", "");

    return `${digits[0]}.${digits[1]}.${digits.slice(2)}`;
}

async function version(name) {
    const file = new URL(
        `./node_modules/${name}/package.json`,
        import.meta.url,
    );

    return JSON.parse(await fs.readFile(file, "utf8")).version;
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
