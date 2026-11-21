# Event moderation (Task #58, UC-3.5)

The system admin can find any event in the system and put it on hold, cancel it or remove it. The page is **Event moderation** in the admin panel (`/admin/event-moderation`). Only a system admin opens it, and the same rule is checked again where the change is made (`App\Services\EventModerator`).

## Find an event

The table lists every event, whoever runs it and in whatever status, the latest start first. Search by **title**, **area** or **organizer**, or filter by status. A removed event stays in the list, with its reason and who removed it and when.

## What the admin can do

The three actions are in the menu at the end of each row (the three dots). Every action asks for a **reason** (at most 500 characters). The reason is kept in the audit log and told to the organizer; the students are not told why, only what happened.

| Action | Offered for | What happens |
| --- | --- | --- |
| Put on hold | published events | The event is frozen and students no longer see it. Applications are kept. |
| Cancel event | drafts, published events, events on hold | The event will not take place. The points it already gave stay. |
| Remove | every event that is not removed yet | The event is gone for everybody and every point it gave is taken back. |

## Removing an event

- The event gets the status `removed`. Who removed it, when and why is kept on the event itself (`removed_by_id`, `removed_at`, `removal_reason`), as for a cancelled one.
- Every credit the event gave is reversed by a **new** ledger row that points back at the credit (`reversal_of_id`): the same student, event, semester, category and source, negative points, and the reason. No ledger row is changed or deleted. A credit that was already reversed by hand is left alone.
- It is all or nothing, in one transaction. A credit that belongs to a closed semester cannot be reversed (`PointLedger::reverse`), so the whole removal is refused and nothing changes. The page then says "Nothing was changed".
- Totals and leaderboards drop at once: the new ledger rows clear the cached totals of the student and of the areas above the event.
- The event row, its applications, its QR codes and its ledger rows stay. The database refuses to delete an event that has them, which is why removing marks the event instead of deleting it.
- Once the removal has locked the event, no credit can be written for it: `PointLedger::write` looks at the status again after taking the same lock, so a scan that started a moment earlier is refused.
- Removing is final. No screen brings the event back, and the migration that adds the status cannot be rolled back while an event is removed.

## Gone for everybody

A removed event is hidden everywhere: the event catalog, the topic explorer, "Organized by me" with its tabs and counts, the student's applications, the student home, and the event page itself, which is "not found" for the organizer too. `EventPolicy::before()` refuses every ability on it, so it cannot be edited, held, restored, cancelled, applied to, scanned or credited. The moderation page is the only place that lists it.

A student's points history still shows the credit and its reversal, as a correction, because the ledger is never edited.

An area that ever had an event cannot be deleted, a removed event included, because the event keeps its row. A removed event no longer blocks moving an area or counts as a live event when an area is archived.

## Who is told

| Action | Students | Organizer who made the event |
| --- | --- | --- |
| Hold, cancel | those with an open application (pending, accepted or on the waiting list) | with the reason |
| Remove | the same, and every student whose points were taken back, with the number of points | with the reason |

Withdrawn and rejected applications are not told. A person gets one message, even if they applied and also got points. The message is an email and an entry on the Notifications page, and both say the same (`App\Notifications\EventModeratedNotification`).

## Audit log

Each action writes one row to `topic_audit_events`:

| Action | Subject | Before | After |
| --- | --- | --- | --- |
| `event.held` | the event, in the area that runs it | status | event title, new status, reason |
| `event.cancelled` | the same | status | the same |
| `event.removed` | the same | status | the same, and `credits_reversed` and `points_reversed` |

## Known limits

- Putting an event on hold is the same hold an organizer can put on: the organizer can restore it. To keep an event off, cancel or remove it.
- Points from before the last "Reset all points" are reversed too, so the ledger stays whole, but the student is not told about them: they no longer counted.
- The count of active events on the dashboard is Task #123.

Tests: `tests/Feature/Admin/EventModerationTest.php` and `tests/Feature/Events/EventRemovedMigrationTest.php`.
