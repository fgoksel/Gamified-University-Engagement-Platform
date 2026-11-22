# Accessibility check (Task #61)

Measures the main screens with axe-core and Lighthouse, signed in as a demo student and a demo organiser, and counts controls smaller than 44 x 44 px on the phone layout. Run it before and after the accessibility fixes; the results go in `docs/accessibility/`.

1. Serve a throwaway copy of the commit to measure. It has its own SQLite database with the Sprint 2 demo data, so the dev database is never touched:

    ```sh
    tests/accessibility/serve-copy.sh            # HEAD, or name a commit
    tests/accessibility/serve-copy.sh --worktree # the working folder, uncommitted changes included
    ```

    After the demo seed, `seed-copy.php` adds the little the check needs on top, in the copy only: a role that lets Ivan invite people on Faculty of Informatics (for the "Invite a person" dialog), and a leaderboard on Anna's own area, Databases (students see only their own areas' boards since #119).

2. Run the check (Node 20 or later, and Chrome), once per theme:

    ```sh
    cd tests/accessibility
    npm ci
    A11Y_LABEL="dev abc1234, final (light)" node run.mjs ../../docs/accessibility/2026-11-22-final-light
    A11Y_THEME=dark A11Y_LABEL="dev abc1234, final (dark)" node run.mjs ../../docs/accessibility/2026-11-22-final-dark
    ```

    `A11Y_THEME=dark` sets each demo user's appearance to dark after logging in, through the app's own setting (`PUT /appearance`, what the Profile page saves), and gives guests the theme the app remembers in the browser. Without it, the run is light. Each theme is its own result set.

3. Remove the copy: `tests/accessibility/serve-copy.sh --stop`

### Pages

The pages are listed in `RUNS` in `run.mjs`, per demo user. Keep the names of existing pages, so the tables of different dates line up; add new ones at the end of a list. A route can name demo data instead of an id: `{event:Title}`, `{board:Leaderboard name}` (a board the user can see) and `{topic:first}` (the area `/topics` opens for the user).

Task #131 added: Guest "Hall screen"; Anna "Leaderboards" and "Leaderboard, one board selected"; Ivan "Areas, Access tab", "Area leaderboards tab", and two dialogs.

A dialog is measured by giving the page an `open` step, a Playwright click that opens it before axe runs (the run checks that a dialog is really open). Name such pages "... (dialog open)". Lighthouse loads a URL fresh and cannot open the dialog, so dialogs are measured by axe only and left out of the Lighthouse average:

```js
["Organized by me, Restore (dialog open)", "/my-events?filter=on_hold", {
    open: (page) => page.locator('[data-test="restore"]').first().click(),
}],
```

The tool versions are pinned in `package.json`, so a later run compares like with like. Keyboard use and screen-reader labels still need a check by hand: no tool sees whether the order makes sense or a label says the right thing.
