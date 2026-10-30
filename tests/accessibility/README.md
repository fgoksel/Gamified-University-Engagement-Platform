# Accessibility check (Task #61)

Measures the main screens with axe-core and Lighthouse, signed in as a demo student and a demo organiser, and counts controls smaller than 44 x 44 px on the phone layout. Run it before and after the accessibility fixes; the results go in `docs/accessibility/`.

1. Serve a throwaway copy of the commit to measure. It has its own SQLite database with the Sprint 2 demo data, so the dev database is never touched:

    ```sh
    tests/accessibility/serve-copy.sh            # HEAD, or name a commit
    ```

2. Run the check (Node 20 or later, and Chrome):

    ```sh
    cd tests/accessibility
    npm ci
    A11Y_LABEL="dev abc1234, after" node run.mjs ../../docs/accessibility/2026-11-03-after
    ```

3. Remove the copy: `tests/accessibility/serve-copy.sh --stop`

The tool versions are pinned in `package.json`, so a later run compares like with like. Keyboard use and screen-reader labels still need a check by hand: no tool sees whether the order makes sense or a label says the right thing.
