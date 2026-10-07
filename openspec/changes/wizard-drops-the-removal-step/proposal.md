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

Removal moves to the admin page (Ruben, 7 October 2026):

- An Example data section on learniq's admin settings page lists the loaded
  example sets, each with a Remove button. The button asks first, then posts
  the existing admin-only setup action `remove-example-set-<id>`, which moves
  the set's objects to OpenRegister's trash (not the `occ` command's hard
  delete), and shows the result.
- New admin-only `GET /api/setup/example-sets` returns the loaded sets. The
  admin page cannot read the `loadedExampleSets` initial state, which only
  the app page provides.
- `docs/installation.md` names the admin page, and the `occ` command for a
  set that cannot be removed there.

The setup status keeps reporting the removal actions done, so a browser that
still holds the older manifest never starts one by itself.
