# Data protection (Task #62)

This page says what personal data the platform holds, where it can leave the server, and what keeps it out of the logs (Technical Specification 8.6, decisions D2 and N13). It describes what the code does. The legal side is not in it: the legal basis, the privacy notice for students and how long the university keeps accounts are decisions of the university.

## What the platform holds

| Data | Where | How long |
| --- | --- | --- |
| Name, e-mail address, Neptun code, major, faculty, avatar and a password hash (never the password) | `users` | Until the account is removed. Nobody is removed automatically (D5). |
| An invited person: the same row with the status `invited` and an activation token | `users` | The token expires. |
| Applications, check-ins and points | `event_applications`, `point_transactions` | Kept. A correction adds a reversal row: a ledger row is never changed or deleted. |
| The badges a student holds (Task #103) | `badge_awards` (`student_id`, `badge_id`, `awarded_at`) | Until the badge is removed, or the credit it came from is taken back. A points reset does not touch them (`docs/badges.md`). |
| What happened to an account, a role, an area, a leaderboard or an event | `topic_audit_events` | Kept: it is the accountability record. It stores ids, titles and reasons. When a system admin is appointed, removed or replaced, and when an account is deactivated, it also stores the person's name. |
| The report of an import | `notifications` | It goes only to the admin who started the import. It names the Neptun codes of the rows that need attention. |
| The address and the browser of a signed-in session | `sessions` (`ip_address`, `user_agent`) | Until the session expires (`SESSION_LIFETIME`, 120 minutes). |
| A file of students that was uploaded for an import | `storage/app/private/imports` | Deleted when the import finishes, is refused for its columns, or fails for good. |

## What the logs hold

| Where | What could be in it | What keeps personal data out |
| --- | --- | --- |
| The application log, `storage/logs/laravel.log` | What our code and the framework write | Our code writes two lines, neither with a person in it. The `RedactPersonalData` tap on every text channel replaces an e-mail address with `[email]` and the value of a duplicate entry with `[value]`, whoever wrote the line. Only the Slack channel has no tap, and this project does not use it. |
| The message of a failed query | The SQL with the values that were saved: names, addresses, Neptun codes | `mask_bindings_in_exception_messages` on every connection (`config/database.php`). The message keeps the `?`. |
| A stack trace | The first 15 characters of each text argument | `docker/php/data-protection.ini` sets `zend.exception_ignore_args`. It is mounted into the `app`, `queue` and `scheduler` containers. |
| The `failed_jobs` table | The error text of a job, and a mail error can name the recipient | `queue:prune-failed --hours=72` runs every night, so a record lives three days. |
| The web server's access log | The visitor's address, the query string, the referrer | `docker/nginx/default.conf` and `production.conf` write the address with its last part cut off, the path without the query string, and no referrer. |
| Mail in development | Mailpit keeps the mails it receives, with names and addresses | Mailpit is a development tool. No example or compose file sets `MAIL_MAILER=log`, which would write whole mails into the log. |

A line of the access log looks like this:

```
203.0.113.0 - [22/Nov/2026:12:24:32 +0000] "GET /leaderboards HTTP/1.1" 200 4821 "Mozilla/5.0 (...)"
```

An IPv6 address keeps its first two groups (`2001:db8::`). Look at the log with `docker compose logs webserver`.

## Leaderboards

A leaderboard is anonymous until its creator says otherwise (D2).

| | Shows |
| --- | --- |
| A new leaderboard | A Neptun code, and no name. The form starts with only the Neptun code ticked, and at least one column must stay ticked. |
| People who sign in | The columns the creator ticked: Neptun code, first name, last name. |
| The guest hall screen | Always a Neptun code. A name only when the creator ticked it **and** the system admin switched names on for that leaderboard (Hall screen page). The switch starts off. |
| "Where am I?" | A student's own numbers only, never another student's name, Neptun code or row. |

An e-mail address and a student's account number never leave the server on any leaderboard screen.

## What the tests prove

`php artisan test tests/Feature/DataProtection`

- `NoPersonalDataInLogsTest`: a failed query carries no values; a report reaches the log without them; an address and the value of a duplicate entry are taken out of whatever is written; every text channel has the tap; no code of ours puts `->email`, `->name`, `->neptun_code`, `->first_name` or `->last_name` into a log call, a `report()` or the message of an exception; the trace setting is mounted into the three PHP containers; failed jobs are pruned after 72 hours; every `server` block of both nginx files writes the shortened log and nothing else; no example file sends mail to the log.
- `LeaderboardAnonymityTest`: with the names off, no screen that lists leaderboards shows a name, an address or an account number to a guest, a student, the admin of the area or the system admin; the creator's names show to people who sign in; the hall screen needs the creator and the system admin to agree.
- `UploadedImportFilesTest`: the file of "Import students" is deleted in all three endings, and its report goes only to the admin who started it.
- `DataProtectionDocTest`: this page exists, the readme points to it, and it names only tests and files that exist.

## What this does not do

- **A number can appear in the error log.** Laravel adds `userId` to every exception that is reported while someone is signed in. A number identifies nobody without the database, and it is what finds the account when something breaks.
- **Names are not recognised in a text.** A name cannot be found by a pattern, so the redaction does not look for one. Names are kept out of messages instead: the code is checked by the test above, and a failed query no longer carries them.
- **nginx's own error log** writes the address of the client for a request that went wrong, in a format that cannot be changed. It records faults, not visits.
- **The audit trail keeps names** for the admin changes listed above. That is the point of an audit trail.
- **A trace shows no argument values**, also on the error page in development. To see them while you debug, take the `data-protection.ini` line out of the three services in `docker-compose.yml` for a while and run `docker compose up -d`.
- **A person's rights** (to see their data, to have it corrected or erased), consent and the privacy notice are not part of this task.

## On a server

- Set `APP_DEBUG=false`. The debug error page shows the request and its data.
- Set `LOG_STACK=daily` to keep the application log for 14 days (`LOG_DAILY_DAYS`). The default `single` file grows without end.
- Docker keeps the output of a container without a limit. For the `webserver` service add `logging: { driver: json-file, options: { max-size: "10m", max-file: "5" } }`.
- After pulling this change: `docker compose up -d` (new mount for the PHP containers) and `docker compose up -d --force-recreate --no-deps webserver` (new log format; a plain restart can keep the old file, because `git pull` replaces it and the bind mount still points at the old one).

## Where it is

- `app/Logging/`: `RedactPersonalData` (the tap), `RedactingFormatter`, `PersonalDataRedactor` (the patterns).
- `config/logging.php`, `config/database.php`, `routes/console.php`, `docker/php/data-protection.ini`, `docker-compose.yml`, `docker/nginx/*.conf`.
- `tests/Feature/DataProtection/`.
