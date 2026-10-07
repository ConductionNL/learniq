---
kind: code
depends_on: [example-set-removal-in-wizard]
---

# Proposal: wizard-drops-the-removal-step

## Why

Live audit, 7 October 2026 (A1): the other apps offer example data in the
setup wizard as cards with a Load button and nothing else. learniq alone
still ends its wizard with a separate step, "Remove the example data". The
shared wizard card cannot remove (nextcloud-vue 2.70.0 has no remove action
on a card), so folding removal into the card is not possible. Ruben, 7
October 2026: drop the separate step.

## What changes

- `src/manifest.json`: the `remove-example-set` setup step is gone. The
  wizard is welcome, example data, kind of organisation, done.
- `src/main.js` no longer expands that step into one step per loaded set,
  and `src/utils/exampleSetSteps.js` with its test is removed.
- `docs/installation.md` names `occ learniq:example-set:remove` as the way
  to remove a set.

The server side stays: `POST /api/setup/action/remove-example-set` and
`remove-example-set-<id>` still remove a set for an administrator who calls
them, and the setup status still reports them done. Nothing in the UI posts
them any more.

## Out of scope

A removal button on learniq's admin settings page. There is none today; the
settings page has no example data section.
