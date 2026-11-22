# Design system (Task #91)

The calm design system of the Vue app: the colour tokens, the shared components and the rules
every screen follows. Tokens live in `resources/css/app.css`; they switch with the light/dark
theme on their own, so components never need `dark:` classes.

## Colour tokens

Use them as Tailwind classes, e.g. `bg-surface`, `text-fg-muted`, `border-line`.

| Token | Use it for |
|---|---|
| `canvas` | Page background behind the cards |
| `surface` | Cards, panels, dialogs, inputs |
| `surface-muted` | Hover backgrounds, disabled fields, neutral chips |
| `line` | Borders and dividers |
| `fg` | Normal text |
| `fg-muted` | Secondary text: hints, dates, inactive tabs |
| `primary` / `primary-hover` | Main buttons and the active tab underline (with `on-primary` text) |
| `link` | Links and active/current text (active tab, current menu item) |
| `ring` | Focus outline |
| `success-fg` on `success-soft` | Done or positive: Accepted, Credited, Approved |
| `warning-fg` on `warning-soft` | Waiting or paused: Under Review, On hold |
| `danger-fg` on `danger-soft` | Errors and Cancelled |
| `info-fg` on `info-soft` | Neutral facts worth noticing: Published, Waitlisted, Surprise event |

Measured contrast (WCAG 2.2 AA needs 4.5:1 for normal text). All pairs pass in both themes:

| Pair | Light | Dark |
|---|---|---|
| `fg` on `surface` | 17.28 | 14.77 |
| `fg-muted` on `surface` | 6.27 | 7.23 |
| `fg-muted` on `surface-muted` | 5.62 | 6.51 |
| `link` on `surface` | 5.77 | 7.82 |
| `link` on `canvas` | 5.42 | 8.41 |
| `on-primary` on `primary` | 4.65 | 4.65 |
| `on-primary` on `primary-hover` | 5.77 | 5.77 |
| `success-fg` on `success-soft` | 5.05 | 8.35 |
| `warning-fg` on `warning-soft` | 6.68 | 7.90 |
| `danger-fg` on `danger-soft` | 8.45 | 7.34 |
| `info-fg` on `info-soft` | 4.93 | 6.61 |

The status colours are deliberately calm: "danger" is a deep navy, not red. Category colours
are only for category dots and chips, never for status. Categories are a list the admin edits
(Task #116, [categories.md](categories.md)), and each one picks one of eight colours: `cat-violet`,
`cat-green`, `cat-orange`, `cat-pink`, `cat-blue`, `cat-teal`, `cat-amber` and `cat-slate`, each
with `-fg` and `-soft`. Screens get the classes for a category from `resources/js/categoryColors.js`
(`categoryDot`, `categorySoft`), never by naming a category.

| Category colour | `-fg` on `-soft`, light | dark | Dot on the page, light | dark |
|---|---|---|---|---|
| `violet` | 6.06 | 8.98 | 3.66 | 5.43 |
| `green` | 5.39 | 9.42 | 3.21 | 6.38 |
| `orange` | 5.47 | 9.00 | 3.07 | 6.47 |
| `pink` | 5.63 | 9.01 | 3.42 | 5.78 |
| `blue` | 5.64 | 9.73 | 3.49 | 6.31 |
| `teal` | 5.44 | 9.34 | 3.23 | 6.59 |
| `amber` | 5.52 | 9.95 | 3.43 | 7.40 |
| `slate` | 6.19 | 9.45 | 3.38 | 6.17 |

"Dot on the page" is the lowest of `surface`, `canvas` and `surface-muted`; `-fg` also keeps at least
5.4:1 on each of them in the light theme and 8.9:1 in the dark one.

## Colour on student pages (N11)

Decision N11 (9 November) lets the student pages use more colour and badge icons than the calm
look of 26 October, because the app is there to motivate. WCAG 2.2 AA still applies in both
themes, and the rules below stay: tokens only, no emojis, icons from `@lucide/vue`, and every
animation off with `prefers-reduced-motion` (Task #126).

| Token | Light | Dark | Where |
|---|---|---|---|
| `band-from` / `band-to` | brand-50 to brand-100 | `#12284a` to `#162f57` | Home's welcome band (gradient). |
| `medal-gold`, `-fg`, `-soft` | `#b08307`, `#7a5a00`, `#fbf1d4` | `#e0b13e`, `#f3d27a`, `#30260c` | Place 1: podium step, place badge in the list, board box stripe and medal icon. |
| `medal-silver`, `-fg`, `-soft` | `#76829a`, `#4a5568`, `#eef1f5` | `#aab5c7`, `#d3dae5`, `#222936` | Place 2, the same places. |
| `medal-bronze`, `-fg`, `-soft` | `#b46b3c`, `#8a4a22`, `#f9ebe0` | `#d69563`, `#efbf9c`, `#35231a` | Place 3, the same places. |

Places come from the rank, so equal points share a medal (1, 2, 2 are gold, silver, silver).
Screens get the classes from `resources/js/medals.js` (`medal(rank)`), never by naming a colour.

Measured contrast:

| Pair | Light | Dark |
|---|---|---|
| `medal-gold-fg` on `medal-gold-soft` | 5.66 | 10.16 |
| `medal-silver-fg` on `medal-silver-soft` | 6.64 | 10.38 |
| `medal-bronze-fg` on `medal-bronze-soft` | 5.83 | 8.96 |
| `medal-*-fg` on `surface` (lowest) | 6.38 | 10.45 |
| `medal-*` (stripe, icon) on `surface` (lowest) | 3.45 | 6.91 |
| `medal-*` on its own `-soft` (lowest) | 3.06 | 5.93 |
| `fg` on `band-to` | 14.78 | 11.29 |
| `fg-muted` on `band-to` | 5.36 | 5.53 |
| `link` (the total) on `band-to` | 4.93 | 5.98 |

Where the existing tokens add colour:

- **Your points** (Home): each tile in its category's `-soft` tone, a dot in the category colour and a
  bar with its share of the total ("40% of your total" beside it, so the bar is never the only
  carrier). The "no category" tile stays `surface-muted`.
- **Event cards** (All events, Home): a 4px top edge in the category colour (`line` without one), the
  category chip in its `-soft` tone, and "places left" in `warning-fg` when 3 or fewer are left.
- **Leaderboards**: the student's own row in `info-soft` (brand-100) with a `primary` edge and a
  "You" pill (`on-primary` on `primary`, 4.65). Board boxes have a top edge: the medal colour on
  places 1-3, `primary` otherwise.
- **Success moments**: the check-in confirmation and the scanner's "credited" result use
  `success-fg` on `success-soft` and the `pop-in` animation (a 0.35 s scale and fade, defined in
  `app.css` inside `prefers-reduced-motion: no-preference`, like `confirm-in`). The scanner's
  banner uses the status tokens instead of raw palette colours.
- **QR passport**: a `primary` frame (4.65 light, 3.75 dark against `surface`) around a white box,
  so cameras still read the code in dark mode, and the countdown ring in `link`.

## Components

| Component | When to use it |
|---|---|
| `PrimaryButton` | The one main action of a full-width form, such as Log in or Save. Full width, 44px high. |
| `Topics/Btn` | Every other button. `variant="primary"` for the main action of a dialog, `danger` to end or move something, `destructive` for permanent deletes, `quiet` (default) for the rest. `small` keeps the 44px height and only narrows the padding. |
| `TextField` | Text, number, date and password inputs with a label, hint and error. Add `required` when the backend needs a value (native `required` plus an asterisk) or `optional` when it may stay empty (adds "(optional)"). Never type "(optional)" into the label. |
| `StatusChip` | Any status label. Choose a `tone`, optionally pass a lucide `icon`. |
| `AlertMessage` | A message box inside a page, `type="success"` or `type="error"`. For short messages after an action, prefer the flash toast. |

For a plain `<label>` that cannot use TextField (textarea, select), copy the same markup:
`<span class="text-danger-fg" aria-hidden="true">*</span>` or
`<span class="font-normal text-fg-muted">(optional)</span>`.

### StatusChip tones

| Tone | Meaning | Examples |
|---|---|---|
| `success` | Done, positive | Accepted, Credited, Approved |
| `warning` | Waiting for someone, paused | Under Review, Pending Review, On hold |
| `danger` | Stopped for good | Cancelled |
| `info` | A fact worth noticing | Published, Waitlisted, Already applied, Surprise event |
| `neutral` | Inactive or closed | Draft, Completed, Withdrawn, Rejected, Applications closed |

Event statuses use the shared map in `resources/js/eventStatus.js` (`eventStatusTone`), so every
screen shows an event's status with the same tone.

```vue
<StatusChip tone="success" :icon="CheckCircle2">Accepted</StatusChip>
<StatusChip :tone="eventStatusTone[event.status]">{{ statusLabel[event.status] }}</StatusChip>
```

## Rules

- **44px targets.** Every button, link styled as a button, select and input is at least
  `min-h-11` (44px), or `size-11` for icon-only buttons.
- **Visible focus.** Interactive elements show `focus-visible:outline-2 focus-visible:outline-ring`.
- **Active text uses `link`.** The current tab or menu item uses `text-link font-semibold`, not
  `text-primary`, which fails AA as text on the canvas (4.36:1 light, 4.03:1 dark).
- **No raw Tailwind colours for status.** Never `emerald-*`, `amber-*`, `red-*` and so on with
  `dark:` overrides. Use `StatusChip` or the status tokens.
