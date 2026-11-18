# Categories (Task #116)

The categories used to be four values written into the code. They are now a list that changes
without a code change, as the professor asked: "If they are not hard-coded but they look hard-coded,
it's not a problem" (N6, 9 November 2026). An event no longer needs a category or a tag (D10,
7 November 2026): the area tree organises events, and categories connect things across its branches.

## Who changes the list

| Person | May change the list |
| --- | --- |
| System admin (active account) | Yes, though normally they leave it to the root's area admin (N1) |
| Someone with the *Manage categories* right on a top area, such as the University root | Yes |
| The same right on any area further down | No: every branch shares the categories |
| Everyone else | No. Organisers ask for a new category, as they ask for tags |

To make someone the categories' keeper, give them a role that includes *Manage categories* on the
University root (Areas → the root → Access). The right only counts while that area is not archived.

The page is **Categories** in the main menu (`/categories`), shown only to these people. It is in the
main app, not the admin panel, because the root's area admin runs it (#104).

## What each change does

| Change | Effect |
| --- | --- |
| Add | A new category with a name and one of eight colours. It is offered at once for tags, tag requests and events, and every per-category total gets a place for it. |
| Rename or recolour | The new name and colour show everywhere, also on old events and points. No stored value changes. |
| Retire | No longer offered for new tags, events or tag requests, and a pending tag request in it cannot be approved. Everything that has it keeps it, its points still count, and an event that has it may keep it when edited. The last category in use cannot be retired. |
| Restore | Offered again. |

Nothing is ever deleted: the model refuses it, and the database refuses to remove a category that
anything uses.

## How it is stored

- `categories`: `slug`, `name`, `color`, `retired_at`. The table started with the four old categories,
  with their old values as slugs (`academic`, `scientific`, `sports`, `community`), so no record
  changed when the list arrived.
- `topic_tags`, `topic_tag_requests`, `subject_areas`, `events` and `point_transactions` keep the slug
  in their `category` column, with a foreign key to `categories.slug`. Events and ledger rows may have
  none; tags, tag requests and subject areas always have one.
- The slug is made once from the first name (`Student life` → `student_life`, `Közösség` →
  `kozosseg`) and never changes. A slug that is taken, or that would overwrite a field of the
  standings such as `points` or `total`, gets `_2`, `_3` and so on.
- Names are unique, ignoring case and spaces, retired categories included.

## Points

A ledger row copies the event's category when the points are given, as before. The totals per
category follow the list: there is one for every category, retired ones too, so they still add up.
Points of an event without a category count in the total only.

Once points are given for an event, its category is locked together with its tag, so the event and
its ledger rows always agree.

## In the code

- Server: `App\Services\CategoryList` is the only place that reads the list (pickers, filters,
  names, per-category totals, who may change it). `App\Services\CategoryEditor` makes the changes.
- Pages get the list with every response as the shared `categories` prop. In the browser,
  `resources/js/categoryColors.js` gives `categoryLabel[slug]`, `categoryDot[slug]`,
  `categorySoft[slug]` and `categoryOptions()`. Nothing else names a category.
- Still naming the four: the Home page and the leaderboard components, which PR #107 rewrites.
  Making them read the list is #117. They keep working meanwhile, with the old names and colours.

## Going back

`php artisan migrate:rollback` restores the four fixed values, but only while nothing has a
category added later and every event and ledger row still has one. Otherwise it stops with a
message and changes nothing.
