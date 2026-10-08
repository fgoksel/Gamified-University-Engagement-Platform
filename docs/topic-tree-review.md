# Topics: how to try it, and a review checklist

Nothing here claims that the team or the supervisor approved anything. It is a list to compare with
their intent.

## Try it locally (Docker)

```bash
docker compose up -d
docker compose exec app php artisan migrate                          # creates the topic tables, upgrades the old tree if there is one
docker compose exec app php artisan db:seed --class=TopicDemoSeeder  # OPTIONAL demo data; the normal seeders stay blank
```

Open http://localhost:8081/login.

| Sign in as | Password | What to look at |
| --- | --- | --- |
| The System Admin from `.env` (`ADMIN_EMAIL`) | `ADMIN_PASSWORD` (change it at first login) | Sees every root. Admin panel → **System admins**, **Topics**, **Topic roles**. |
| `demo.lead@example.test` | `password` | Enters at "Pécs" (nothing above or beside it is visible). Can give roles inside the branch. |
| `demo.delegate@example.test` | `password` | A delegate of the lead: can give only what the lead may pass on. |
| `demo.contributor@example.test` | `password` | Adds topics and edits their own; no access administration. |
| `demo.reader@example.test` | `password` | Looks only, at "SQL" and below. |
| `demo.programmer@example.test` | `password` | A contributor in "Programming" and a reader in "Budapest": different responsibilities in different branches. |

Use two browsers (or a private window) to be signed in as two people at once; the admin panel and the app share a session.

## Acceptance checklist

Structure
- [ ] Fresh install (no demo data): Topics is empty and there are no roles; the admin sees "No topics yet".
- [ ] Admin creates two unrelated top-level topics and nests several levels; no faculty/course/dean is asked for.
- [ ] Moving a topic into itself or into something below it is refused (Move dialog shows the reason).
- [ ] Expanding a chevron does not change the selection; clicking or Enter selects. The URL changes on select; reload, back, forward and a copied link land on the same topic with the tree open.

Visibility
- [ ] A user with access only to a nested topic enters there; the breadcrumb starts there; search, "Whole branch", counts and the Access tab never mention parents or siblings.
- [ ] Typing a hidden topic's address shows "not found", the same as a topic that does not exist.

Roles and authority
- [ ] Roles page: a role is a name plus ticked abilities. A role called "Admin" that only ticks "View topics" can only view, and is not a System Admin.
- [ ] A delegate cannot tick abilities they may not pass on; direct requests for more are refused.
- [ ] A contributor adds a topic and edits their own, but sees no Access tab and cannot give roles.
- [ ] Parent roles apply below; a local role cannot take them away. Roles from above show under "Inherited from [ancestor]" without End/Replace buttons.
- [ ] Editing a role that people hold asks you to acknowledge how many assignments it affects. Archiving a role keeps everyone's access.

Continuity
- [ ] You cannot end, replace or deactivate your own role/account (button hidden or the server says why).
- [ ] End role and Replace holder both show **two** confirmation steps; cancelling at either step changes nothing; the history shows the ended role with date, reason and who ended it; the original "given by" is unchanged.
- [ ] After a handover the topic, its descendants and other people's roles are untouched; roles the leaver had given to others still work.
- [ ] System admins: with one admin you cannot remove/replace/deactivate yourself; appoint a second admin, who can then remove or replace the first.

Move (optional operation, implemented)
- [ ] Move shows how many topics move and how many people gain or lose inherited access, and is refused when a branch-only role would end up outside its branch.

Archive and delete
- [ ] Archive a topic: it and everything inside leave the tree, search and counts; nothing is deleted; **Archived topics** lists it and **Restore** brings it back.
- [ ] A topic inside another archived topic cannot be restored until the outer one is.
- [ ] Only people with the "Archive topics" ability see Archive; only System Admins see Delete.
- [ ] Delete on a topic that has anything inside, any role history, a role for its branch or a linked course is refused with the reasons and "Archive instead".
- [ ] Delete on an empty, never-used topic shows a first "are you sure", then a red final warning where you must type the name; cancelling anywhere deletes nothing.

Other
- [ ] The existing enrolment import still runs and reports, row by row category, anything it cannot place.
- [ ] Phone width: "Browse topics" opens the tree as a drawer; Escape closes it; no sideways page scroll.

## Questions to compare with the supervisor's intent

1. Peers appointing peers: a Dean/Teacher-style role can give an equal role downward. Wanted?
2. Is **move** in scope now, and who holds `topic.organise`?
3. Is permanent delete (admin only, never-used topics only) enough, or should it be removed entirely until events exist?
4. Name and email of candidate accounts are shown to people who may give roles. Acceptable?
5. Should "one role per person per course" return as an optional setting on a role?
6. Should the enrolment import later create or link topics itself, and under whose control?
7. Is the capability list (view, add, edit any, move, see access, give roles, end/replace roles, define roles) the right granularity?
8. Should deactivating an account *require* handing over its active branch responsibilities first? (Today the confirmation dialog warns how many topic roles the person holds; they stay in place and can be handed over afterwards in Topics.)
