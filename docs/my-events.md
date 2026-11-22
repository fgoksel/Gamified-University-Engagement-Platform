# My events: search and sort (Task #121)

Both "My events" pages, the student's list and the organizer's "Organized by me", have a search box and a sort list. What they hold is in the address, so a reload, the back button or a shared link shows the same list.

| In the address | What it does |
| --- | --- |
| `q` | Search: the events whose title contains the text, in any case. At most 100 characters are used. `%` and `_` are read as themselves. |
| `sort` | The order. A value that is not offered is ignored, never an error. |
| `filter` | The organizer's tab: `upcoming`, `drafts`, `past`, `on_hold` or `cancelled`. |

## The orders

| `sort` | Organizer | Student |
| --- | --- | --- |
| `date_asc` | event date, earliest first | event date, earliest first |
| `date_desc` | event date, latest first | event date, latest first |
| `title_asc` | title, A to Z | title, A to Z |
| `title_desc` | title, Z to A | title, Z to A |
| `applied` | not offered | the latest application first |

Titles are compared in lower case, so "alpha" comes before "Beta". Events that tie keep a steady order.

- **The student's list** is in the order of `applied` until another one is picked. That was its only order before.
- **The organizer's tabs** have an order of their own: earliest first for Upcoming, Drafts and On hold, latest first for Past and Cancelled. A tab keeps it until the organizer picks an order. A picked order stays when the tab changes; the order a tab has by itself does not carry over, so Past still starts from the latest.

## What the search does and does not do

- It only narrows what the person already sees. The organizer's list is still the events of the areas where they hold a right to run events, and the student's list is still their own applications. A removed event, and an application that was undone straight after applying, are never listed.
- The organizer's tabs count the events that match, so they show which tab the matches are in. A search that matches nothing keeps the box on the page and offers "Clear search", instead of saying that there are no events.
- The student's tabs (All, Active, History) work in the page as before and count the matches. The tab is not in the address.
- Leaving, applying again, cancelling, restoring or deleting an event goes back to the same address, so the search and the order are still there afterwards.

## Where it is

- `App\Support\EventListView` cleans what comes from the address.
- `App\Services\OrganizedEvents` searches and sorts the organizer's list; `EventController::mine` does the student's.
- `resources/js/Components/Events/ListControls.vue` is the box and the list, used by both pages (`Pages/Events/Organized.vue`, `Pages/Events/Applied.vue`).
- Tests: `tests/Feature/Events/MyEventsSearchSortTest.php`.
