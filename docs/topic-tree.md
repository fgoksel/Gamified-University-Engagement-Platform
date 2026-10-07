# Topics: the abstract topic tree (Sprint 1)

This document records what was built, why, how the old faculty tree was carried over, and what is
deliberately left for later. It is the decision record for review; nothing here claims team or
supervisor approval.

- Base: `dev` at `8adbc59187a92922ef50b488103e850c293ab8ca` ("Merge pull request #38 from fgoksel/subject-tree-admin"),
  verified unchanged when work started.
- Branch: `feature/abstract-topic-tree`.

## 1. What a user sees

- **Topics** in the menu opens an explorer: the tree on the left, the selected topic on the right.
  Expanding a branch (chevron, or the Right arrow key) never changes the selection; selecting (click,
  Enter) does. Every selection is a URL (`/topics/{id}`, plus `?view=branch`, `?tab=access`), so back,
  forward, reload and links work.
- A **root** is just a topic without a parent. There can be several. No level has a required meaning:
  names such as Hungary, Pécs, Programming or Database are examples the user types.
- **Direct contents** shows the immediate children. **Whole branch** lists every topic below that the
  person may see, with the path from the selected topic, a filter and paging. The stored tree is never
  flattened.
- **Access** (only for people who may see who has access) shows *Assigned here* and *Inherited from
  [ancestor]*. Ancestors above the person's own access are never named. Counts separate **people** from
  **role assignments** (one person with two roles is one person and two assignments).
- **Roles** (menu, for people who define or give roles) is the role builder.
- System Admins use the same screens (menu entries "Topics" and "Topic roles" in the admin panel) plus a
  Filament page **System admins** to appoint, remove and replace System Admins.
- Phone width: the tree becomes a drawer opened by "Browse topics"; the topic panel width can be resized
  by mouse or by the Left/Right keys on the separator and is remembered, as are expanded branches
  (browser storage only; access is re-checked on every load).

## 2. Architecture

| Piece | Where | Notes |
| --- | --- | --- |
| Topics | `topics`, `topic_closure` | `parent_id` is canonical. A **closure table** (every ancestor/descendant pair with distance) replaces the old 255-character `path`, so depth has no hidden column limit. The explicit limit is `config/topics.php` → `max_depth` (default 32, env `TOPICS_MAX_DEPTH`). |
| Capabilities | `App\Enums\Capability` | A closed registry: `topic.view`, `topic.create`, `topic.edit`, `topic.organise`, `topic.archive`, `access.view`, `access.assign`, `access.end`, `role.define`. Nothing outside the list can be put in a role. Dependencies (e.g. `access.assign` needs `access.view`) are validated. |
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
