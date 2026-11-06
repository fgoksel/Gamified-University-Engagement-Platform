# Translations (Task #95)

Every text a person reads comes from one dictionary, `lang/en.json`. It is never typed into a
screen. Laravel and Vue read the same file. Sprint 3 stays in English; Hungarian is added in a
later sprint by translating that file, with no screen rewritten.

## How a text is written

`lang/en.json` is a flat list: a key and its English text. A key says where the text is used,
in lowercase with dots (`auth.login.title`, `events.apply.again`, `admin.faculties.navigation`).
Keep the file sorted: `php artisan lang:sort` does it.

| Where | How |
|---|---|
| Laravel | `__('auth.login.failed')`, `__('mail.greeting', ['name' => $user->name])`, `trans_choice('notifications.feed.points.title', $points)` |
| Blade | `{{ __('admin.coming_soon.heading') }}` |
| Admin panel (Filament) | `->label(__('admin.faculties.name'))`, `->title(__('admin.faculties.saved'))`. A property such as `$navigationLabel` is a method instead: `public static function getNavigationLabel(): string { return __('...'); }` |
| Vue template | `{{ $t('common.save') }}`, `:label="$t('auth.email_address')"`, `$tc('qr_print.count.slips', n)` |
| Vue script | `import { t, tc } from "../i18n.js"`, then `t('common.save')` (also for `defineOptions({ layout: { title: t('leaderboard.title') } })`) |
| A sentence with markup inside | `<Trans k="subject_areas.filtering_by"><template #title><strong>{{ title }}</strong></template></Trans>`, where the text is `Filtering by :title` |

- **Placeholders** are written `:name` (also `:Name` for a capital first letter and `:NAME`), and
  are filled the same way in PHP and in Vue.
- **Plural texts** give each form the numbers it is for:
  `{1} You received :count point|[0,*] You received :count points`. Every form starts with
  `{1}`, `[0,*]`, `[2,*]` and so on, so a language with other rules (Hungarian has none after a
  number) needs no code.
- **Write the key out in full.** `t('events.status.' + status)` is refused, because a search for
  `events.status.draft` would not find where it is used. Use a map or a condition with two full
  keys instead.
- **Do not glue a sentence together.** `$count.' students'` or `$name.' failed'` cannot be
  translated, because another language puts the words in another order. Put the whole sentence in
  the dictionary with placeholders: `:title failed`.
- **Do not type a text next to a key.** If the same English word appears twice, give each use its
  own key. Another language may need two words.

## Texts that Laravel asks for itself

Laravel's own pages ask for a few texts by their English words: the pages for an error (`Not Found`,
`Server Error`, ...) and the layout of an email (`Regards,`, `All rights reserved.`, the help under a
button). They sit in `lang/en.json` under that English text as the key, because Laravel looks them up
that way, and are listed in `TranslationsTest::FROM_LARAVEL`. The test fails when a new version of
Laravel asks for one that is missing.

## What is not in the dictionary

- User data: topic names, event titles, names of people, the names of roles an administrator
  typed.
- The messages of a `LogicException`, `RuntimeException` and the like. They are for developers, and
  a person never reads them. Every other exception (`InvalidArgumentException`,
  `AuthorizationException`, ...) can reach a screen and uses the dictionary.
- Command-line tools (`app/Console`, `routes/console.php`): they are for developers.
- The headings and sample rows of the CSV files (`Full Name`, `Neptun Code`, ...). That is the file
  format the importers read, not text on a screen.
- Seeders, factories, migrations and config: they hold data. The upgrade of the old faculty tree
  (`LegacyTreeUpgrade`) writes ordinary role rows that an administrator can rename.
- SQL, validation rules, route names, HTTP headers, date patterns.

`HardCodedTextTest::NOT_INTERFACE_TEXT` lists every string that looks like text and is not, with
the reason. The test fails when a line on that list matches nothing any more.

## What checks it

- `npm run lint`: no text between tags or in `title`, `aria-label`, `label`, `placeholder`,
  `hint`, `alt` and similar attributes, no typed sentence in a script or expression, no text
  built around a value (`` `${n} of ${total}` ``), no one-word label (`cond ? "Open" : "Closed"`)
  and no raw value printed as text (`{{ event.status }}` shows the English word the server stores:
  map it to a text of the dictionary), in every file under `resources/js` (`eslint.config.js`).
- `tests/Feature/HardCodedTextTest.php`, three searches over every PHP file of `app/`,
  `bootstrap/` and `routes/` and every Blade view:
  1. a typed text where PHP hands a message to a person (`->with('error', ...)`, `abort(...)`, an
     email line, a refusal, a label of the admin panel, `ucfirst($status)`),
  2. any string that reads like text, wherever it is, unless it is on the list above,
  3. text or a text attribute (`title`, `alt`, `placeholder`, ...) in a Blade view.
- `tests/Feature/TranslationsTest.php`: every key a screen asks for exists, no text is left over
  in the file, a script that calls `t()` imports it, keys are written out in full, plural forms say
  which numbers they are for, `lang/en.json` is sorted, the texts Laravel asks for itself are in it,
  and the other language files have the same keys, `:placeholders` and plural forms as the English
  one.
- `tests/Feature/LocaleSwitchTest.php` and `tests/Feature/PseudoLocaleTest.php`: the test language
  below changes the messages, the emails with their layout, the pages for an error and the admin
  panel, and PHP and Vue make the same text of it.

## Looking at the screens in the test language

`qps` is a made-up language that Laravel and Vue build from the English texts while the app runs.
Every text comes out accented and in brackets: `Save` becomes `⟦Šáṽé⟧`. It covers the texts of
this project and also the messages of Laravel and of the admin panel, because they go through the
same translator. Set `APP_LOCALE=qps` in `.env` (and run `npm run build`, or keep `npm run dev`
running) and open the screens. **A text without brackets and accents is typed into the code**,
which is a bug to fix. Set `APP_LOCALE=en` to come back. No file belongs to this language, so there
is nothing to keep up to date.

## Adding a language (Hungarian, later)

1. Copy `lang/en.json` to `lang/hu.json` and translate the values. Keep the keys, the
   `:placeholders` and the numbers of the plural forms as they are.
2. Set `APP_LOCALE=hu`. The page carries `<html lang="hu">`, which is what Vue reads.
3. `TranslationsTest` fails if `hu.json` lacks a text, has an extra one, or loses a placeholder.

Not part of the dictionary, and to look at when Hungarian is added:

- the messages of Laravel itself (validation, passwords) come from the framework's language files;
  add a `lang/hu/` set for them (the `laravel-lang` package has one),
- the admin panel ships a Hungarian language pack of its own,
- the names of days and months: PHP writes them with `translatedFormat()`, and Laravel sets the
  locale of Carbon with the language, so `hu` gives Hungarian names and "5 perce" for relative
  times with no change. The browser writes the dates it formats itself (`toLocaleString()`) in the
  language of the browser,
- the headings of the CSV files stay as they are: they are the file format the importers read,
- the Vue bundle holds every `lang/*.json` file. With one language that is nothing to think about;
  when `hu.json` is added, load only the language of the page (`import.meta.glob` without
  `eager`).
