# Topics: the abstract topic tree (Sprint 1)

In the interface this is called an area; in the code it is still Topic.

This document records what was built, why, how the old faculty tree was carried over, and what is
deliberately left for later. It is the decision record for review; nothing here claims team or
supervisor approval.

- Base: `dev` at `8adbc59187a92922ef50b488103e850c293ab8ca` ("Merge pull request #38 from fgoksel/subject-tree-admin"),
  verified unchanged when work started.
- Branch: `feature/abstract-topic-tree`.

## 1. What a user sees

- **Areas** in the menu opens an explorer: the tree on the left, the selected area on the right.
  Expanding a branch (chevron, or the Right arrow key) never changes the selection; selecting (click,
  Enter) does. Every selection is a URL (`/topics/{id}`, plus `?view=branch`, `?tab=access`), so back,
  forward, reload and links work.
- A **root** is just an area without a parent. There can be several. No level has a required meaning:
  names such as Hungary, Pécs, Programming or Database are examples the user types.
- **Direct contents** shows the immediate children. **Whole branch** lists every area below that the
  person may see, with the path from the selected area, a filter and paging. The stored tree is never
  flattened.
- **The path** above an area runs from the root down (N5, Task #120). Every parent is named, whoever
  looks, so a person can see where their area sits. A parent above the person's own access is plain
  text: it cannot be opened (its address says "not found"), and nothing of it comes with the name: not
  its contents, its other children, its people or its roles. The explorer and the event page show the
  path this way. The tree on the left, search, the trails in lists and the Access tab still start at
  the person's own access.
- **Access** (only for people who may see who has access) shows *Assigned here* and *Inherited from
  [ancestor]*. An assignment on an ancestor above the person's own access is not listed: the path
  names that ancestor, but its people and roles stay hidden. Counts separate **people** from
  **role assignments** (one person with two roles is one person and two assignments).
- **Roles** (menu, for people who define or give roles) is the role builder.
- System Admins use the same screens (menu entries "Areas" and "Area roles" in the admin panel) plus a
  Filament page **System admins** to appoint, remove and replace System Admins.
- Phone width: the tree becomes a drawer opened by "Browse areas"; the area panel width can be resized
  by mouse or by the Left/Right keys on the separator and is remembered, as are expanded branches
  (browser storage only; access is re-checked on every load).

## 2. Architecture

| Piece | Where | Notes |
| --- | --- | --- |
| Topics | `topics`, `topic_closure` | `parent_id` is canonical. A **closure table** (every ancestor/descendant pair with distance) replaces the old 255-character `path`, so depth has no hidden column limit. The explicit limit is `config/topics.php` → `max_depth` (default 32, env `TOPICS_MAX_DEPTH`). |
| Capabilities | `App\Enums\Capability` | A closed registry: `topic.view`, `topic.create`, `topic.edit`, `topic.organise`, `topic.archive`, `access.view`, `access.assign`, `access.end`, `role.define`, plus the event capabilities `events.view`, `events.apply`, `events.create`, `events.manage`, `applications.manage`, `points.credit`, `qr_codes.manage` (Task #65; `App\Policies\EventPolicy` decides view, apply, manage, credit and QR, checked at the event's owning or audience topic), and `leaderboards.manage` (Task #89; `App\Services\AreaLeaderboards` decides who may manage and see a leaderboard). Nothing outside the list can be put in a role. Dependencies (e.g. `access.assign` needs `access.view`, `events.manage` needs `events.create`) are validated. |
| Role definitions | `role_definitions` | Name, description, `capabilities`, `delegable` (the subset the holder may pass on), scope (`topic_id` null = global, System Admin only), archived state, version. Names never decide anything. |
| Role assignments | `role_assignments` | Person + definition + topic, start/end/reason/ended-by, grantor, `granted_by_assignment_id` and a frozen `authority_snapshot` (provenance), `replaces_assignment_id` / `replaced_by_assignment_id`, `version`. Never deleted. A generated unique key allows one *active* (person, role, topic) and unlimited ended history. |
| Audit | `topic_audit_events` | Actor, action, before/after for structure, roles, assignments, System Admin changes, deactivation. |
| Authority | `App\Services\TopicAccess` | One answer to "what may this person do at this topic": the **union** of capabilities of active assignments on the topic and its ancestors, for an **active** account. System Admin (the global spatie `admin` role) has everything. Local roles never subtract. Remembered per request and flushed on every write. |
| Policies | `TopicPolicy`, `RoleDefinitionPolicy`, `RoleAssignmentPolicy` | Used by services and controllers; the UI only hides controls. |
| Writes | `TopicService`, `RoleService`, `AssignmentService`, `SystemAdminService` | The only code that writes. Transactions, row locks, audit. |
| Reads | `TopicBrowser` | Explorer payloads, search, whole-branch, counts, access panel: all built from `TopicAccess`, so a list can never show more than opening the topic would. |
| UI | `resources/js/Pages/Topics/*`, `Components/Topics/*` | Inertia pages; JSON only for the lazy tree, search, previews and the confirmation `prepare` step. |

### Authority rules (enforced on the server)

- **Perform vs delegate.** Doing something needs the capability. Passing it on needs it to be in the
  holder's `delegable` set. A role may only list as `delegable` what it also has.
- **Ceiling.** Giving a role at topic T needs `access.assign` at T and *every* capability of the role
  inside the giver's delegable set at T. Defining a role (scoped) needs `role.define` at the scope topic
  and the same ceiling for both `capabilities` and `delegable`. A global role is only for System Admins.
- **Editing a role** needs the ceiling over both the old and the new powers (so nobody edits a role
  stronger than they may pass on), cannot touch a global role from a scope, and is refused for a role
  the editor holds themselves (power changes and archiving). Changing a role people hold needs an
  acknowledgement showing how many assignments are affected. Scope is immutable.
- **Self protection.** Nobody gives themselves a role, ends or replaces their own assignment, or ends an
  assignment that is in the chain of grants that gave them their own authority (the *superior grant*
  rule, via `granted_by_assignment_id`).
- **Continuity.** Ending or replacing a person never ends what they granted, never touches topics,
  descendants, definitions or other people's assignments, and never rewrites the original grantor.
- **No title is power.** A topic role called "Admin" is an ordinary topic role; the System Admin check
  is the account role only, and the admin panel/`SystemAdminService` never read topic roles.

### Area admin and system admin (N1, N5, Task #104)

An **area admin** is someone whose role on an area lets them manage it: give roles and import
people there (*Give a role* / *Import students*), create events and leaderboards. They manage that
area and everything below it, nothing above or beside it (N1, N5). In the topic demo data this is the
Branch lead role; the presentation data (`Sprint4DemoSeeder`, `docs/sprint4-demo.md`) has an *Area admin*
role on the University root and on each course (N2, N3).

The **system admin** (the global `admin` account role) is for maintenance. An area admin does all
of their area's work in the main app, never in the admin panel: the structure, roles, leaderboards,
events, and the people. To add people they use the area's Access tab:

- **Import students**: a CSV file with a Neptun Code column. With the columns Full Name and Email
  too, a code that has no account gets a new, invited student account first (`ImportTopicStudentsJob`).
- **Invite a person**: one student or organiser (`POST /topics/{topic}/invitations`,
  `TopicInvitationController`). Someone who already has an account with that email gets the role
  instead of a second account, when the Neptun code is empty or theirs. A Neptun code that belongs
  to another account is refused and names that account, so a typo never gives a role to the wrong
  person. A deactivated account is refused.
- **Resend invitation**: a new 24-hour activation link for someone who holds a role on the area or
  below and has not activated yet (`POST /topics/{topic}/invitations/{user}/resend`).

All of them need *Import students* (`access.import`) on the area and pass the same `importRole`
check. Inviting an organiser, or resending an organiser's invitation, also needs *Give a role*
(`access.assign`), because organisers create and run events (`App\Services\AreaInvitations`).
Rights come from the role on the area, not from the account type: a student account can hold a
helper role, such as student tutor.
New accounts are made in one place for the whole app: `App\Services\StudentAccounts` and
`App\Services\OrganizerAccounts`, also used by the admin panel's invite actions and CSV imports.
An invited account may hold a role; it takes effect when the person activates the account, because
`TopicAccess` counts active accounts only. A deactivated account cannot be given a role.

#### Admin panel screens

Moved to the main app (the area's Access tab):

- Creating student and organiser accounts, by invite or import.
- Resending an invitation.

Staying in the admin panel, for the system admin:

| Screen | Why it stays |
| --- | --- |
| Deactivating and activating accounts | An account is used everywhere, not in one area (N1). An area admin ends the person's role in their area instead. |
| Topic tag requests | The tag list is global. It stays until the accounts and roles refactor. |
| Faculties | University-wide records, not one area's. |
| Semesters | One calendar for the whole university. |
| Hall screen | One public screen for the whole university, including whether names show (N13). |
| Points reset | Starts every total and every leaderboard from zero, for the whole university (Task #102). |
| Event moderation | Oversight of every event, above any single area. The page is still a placeholder. |
| System admins | Who maintains the system. Area roles never grant it. |

### General areas (#133)

The import stays the way students join their areas, late students too. A **general area** is
meant for every active student, such as the University root (#128 Q3, 10 November): when a
student's account becomes active, they join each general area with its role.

- **The rule.** An area is general when it has a general role (`topics.general_role_id`). When a
  student account turns active (the activation link, or "Activate" in the admin panel), the
  student gets that role in every general area that is not archived, unless they hold it there
  already. Course areas and every other area are not touched; nothing is ever removed; organiser
  and admin accounts join nothing. `App\Observers\UserObserver` does it after the commit, through
  `AssignmentService::joinGeneralArea()`.
- **Who may switch it.** An area admin with *Give a role* and *Import students* on the area, or a
  system admin, on the area's Access tab ("General area"). The role must pass the same
  `importRole` check as an import: only a role they may hand out there. Who switched it on and
  when is kept on the area, and every change is in the audit trail (`topic.general_on`,
  `topic.general_changed`, `topic.general_off`).
- **Students who are already active** do not join when an area is switched on, unless the area
  admin ticks "Also add the N active students who are not in this area yet"; a queued job
  (`JoinGeneralAreaJob`) then adds them in chunks. Turning an area off stops new joins and
  removes nobody.
- **How it shows.** An assignment made this way says "joined automatically (general area)" on the
  Access tab. Its grantor is whoever switched the area on, and its `authority_snapshot` says
  `via: general_area`.

### Ending and replacing (two confirmations, enforced)

`AssignmentService::prepare()` runs every check and returns the summary plus a signed, encrypted,
10-minute token bound to the actor, the assignment, its `version`, the replacement and the reason.
The first dialog's "Continue" calls `prepare`; the second dialog's "Confirm" calls `execute(token)`,
which locks the row, rejects an already-ended assignment (replay) or a changed `version` (stale),
re-checks authority, eligibility and self-protection, and then in **one transaction** ends the outgoing
assignment (date, reason, who ended it), creates the replacement (same role and topic, new grantor =
the person performing the handover, `replaces_assignment_id` link) and writes the audit event. Any
failure rolls everything back. Cancelling never calls `execute`. The same token cannot be used twice,
by another person, or after it expires.

### System Admin continuity

`SystemAdminService` (appoint, remove, replace, deactivate) first **locks all active admin rows** as
the first statement of its transaction and decides only from that locking read. A locking read sees the
latest committed data; an ordinary read made after waiting for another transaction would still see the
earlier snapshot (this was found and fixed by the concurrency test described in the test report).
Rules: only an active admin acts; nobody removes/replaces/deactivates themselves; the last active admin
can never be removed; the only remaining admin may appoint a second admin, who may then remove or
replace the first. `AdminPolicy` is unchanged and still used for the buttons; the Filament
deactivate actions (students, organizers) now call the service. Deactivating an account keeps all its
assignments (an inactive account simply has no access) and a replacement can be named for an inactive
holder.

## 3. Migration and compatibility

Additive forward migrations only. No historical migration is edited; no table is dropped.

1. `2026_10_08_100000_create_topic_tables` creates the five new tables.
2. `2026_10_08_100100_upgrade_legacy_tree_to_topics` runs `App\Support\LegacyTreeUpgrade`.

`tree_units` and `unit_memberships` (including their unique faculty/course links and the generated
`active_dean_*` columns) are **left untouched**, so the upgrade can be checked against them and a
rollback loses nothing from before the upgrade. Anything created in the new tables *after* the upgrade
is not carried back by a rollback.

### Mapping

| Old | New |
| --- | --- |
| `tree_units` row | `topics` row with the **same id**, parent, title, course link, subject-area link, creator, timestamps; `legacy_tree_unit_id` keeps the trace. `kind` (root/course/subtopic) is *not* kept as behaviour. Faculty links are not copied (courses keep their own `faculty_id`). |
| `path` | `topic_closure` rows (built by following `parent_id`; a cycle aborts the upgrade before anything is copied) and `depth`. |
| `unit_memberships` row | `role_assignments` row with the **same id**, topic, person, grantor (`added_by_id`), start/end/reason, `semester_id`, `manual`, timestamps. Tutor `permissions` are kept in `legacy_permissions` as history and grant nothing. |
| Legacy role label | An ordinary global role definition, created **only for labels that existing rows use** (`legacy_key` records the origin). Nothing is seeded on a fresh install. |

Legacy label → capabilities (delegable in brackets). There are no ranks any more; tiers differ only in
what they can pass on:

| Label | Can do | May pass on |
| --- | --- | --- |
| Dean, Teacher | view, add topics, see/give/end roles | view, add topics, see/give/end roles |
| Co-teacher | same as Teacher | view only |
| Student tutor | view | nothing |
| Student | view | nothing |

Access reach is unchanged: a person sees their node and everything below it, nothing above or beside.
Ended memberships stay ended history and give nothing. Event-related tutor rights are out of scope.

### Where the new model differs from the old (documented, not silent)

- **Peers can appoint peers.** A migrated Dean or Teacher can now give the Dean/Teacher/Co-teacher role
  (their capabilities are within what they may pass on); previously only the dean added teachers. A
  migrated Co-teacher still can only give view-only roles (student, tutor). Tighten by editing the
  migrated definitions (they are ordinary roles).
- **No "one role per course".** A person can hold several roles in a branch; powers combine.
- **No academic restriction by account type** (a student-type account may hold any topic role).
- **Grant chain history for migrated rows is only the grantor's account** (`granted_by_id`); the
  *superior grant* protection needs `granted_by_assignment_id`, which exists only for assignments made
  after the upgrade.
- The old rule "a tutor is not also a student of the same course" no longer exists.

### Existing course-enrolment import (compatibility path)

`ImportCourseEnrollmentsJob` keeps its file format and summary style but is **bounded**: a student is
placed only on the topic that the upgrade linked to that course (`topics.course_id`), using the role
created from the old "student" label (created on demand only when a linked topic needs it). It never
creates a topic or course and never attaches a student to any other topic. Rows that cannot be placed
are *counted and listed by reason* in the administrator's message: unknown Neptun code, unknown course,
**course not linked to a topic**, student role archived. On a fresh installation no course is linked, so
the import reports every row as "not linked" rather than guessing. A new course-link workflow is
explicitly out of scope for this sprint.

Other legacy coupling removed: creating a course no longer creates a topic; renaming a course or faculty
no longer renames a topic; Faculties and Faculty courses remain plain admin records. `/my-courses` and
its sub-paths redirect to `/topics`. The old Filament Trees/TreeDetail pages, `TreeService`, the
tree policies/controllers/enums/models and the Vue "My courses" pages were removed (the tables stay).

## 4. Optional operations

| Operation | Status |
| --- | --- |
| **Move** a topic with its subtree | **Implemented.** Needs `topic.organise` at the topic *and* at the destination (moving to top level: System Admin). Refuses itself/descendants and the depth limit. Preview shows how many topics move and how many people would gain or lose inherited access (counts only). Refuses when a branch-scoped role held inside would end up outside the branch it belongs to ("end or replace first"). One transaction; closure and depth rewritten; audited. Direct assignments stay with their topics. |
| **Archive / restore topics** | **Implemented** (power `topic.archive`, checked at the topic; part of the delegation ceiling). Archiving marks only that topic; it and everything below it leave the tree, search, counts, entry points and every normal view, for System Admins too. **Nothing is deleted or ended**: topics, roles, assignments and history stay (roles inside cannot be changed while it is archived, because nobody can open it). Descendants are *included* because they sit below an archived topic; one archived on its own stays archived when an ancestor is restored. **Restore** (same power) is refused while the topic still sits inside another archived topic ("Restore that one first"), so a branch is never put back under something hidden. The **Archived topics** page lists the top of each archived branch to people who hold the power; to anyone else a hidden topic looks like one that does not exist. |
| **Permanent delete of a topic** | **Implemented, System Admin only, unused topics only.** Refused when the topic has any topic inside (archived ones count), any role assignment ever (current or ended), any role defined for it, or a linked course; the refusal lists the reasons and offers "Archive instead". Two confirmations: a first "are you sure", then a red *final warning* where the topic name must be typed. Server side it is `prepareDelete` (checks, signed token bound to the actor, the topic and its `updated_at`) and `executeDelete` (token + typed name, rechecked on the locked row). Audited with the topic's last data. |
| **Archive / restore role definitions** | Implemented: stops new assignments, nobody loses access, restore any time. |
| Roles on very large trees | Search/whole-branch/children are paginated or lazy; the role scope picker is capped (200 topics). |

## 5. Deferred / not decided

Events, tags, points, leaderboards, file uploads and course-link workflows (out of scope by request);
per-user contact-detail policy beyond "name and email are shown
only to people who may see who has access"; cache beyond one request (revisions are not persisted);
an optional "reason" on archiving; a stricter policy on shared-role edits than the conservative one above; the exact capability matrix is
a proposal for review.

## 6. Open questions for teammates to compare with the supervisor's intent

1. Should peers be able to appoint peers (Dean → Dean)? The default is yes, bounded by the ceiling.
2. Is "move" wanted in this sprint, and who should hold `topic.organise`?
3. Should archive/restore of topics ship before events attach to topics?
4. Are name and email of candidate accounts acceptable to show to people who may give roles?
5. Should the old rule "one role per person per course" return as an optional per-role setting?
6. Should the enrolment import eventually create/link topics, and under whose control?
