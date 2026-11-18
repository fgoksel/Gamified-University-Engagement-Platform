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
(`categoryDot`, `categorySoft`), never by naming a category. The old names (`cat-academic` and the
other three) still point at the first four colours for the screens that name the four, until #117.

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
