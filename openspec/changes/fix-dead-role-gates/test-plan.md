# Test Plan: fix-dead-role-gates

Every functional TC below is `@e2e exclude`d at the spec level because it requires switching Nextcloud group
membership per test, which the single-admin scholiq e2e harness cannot provision. "Verified live instead" is
only a real control when a named person performs a named, falsifiable check and records the result — so each
such TC below states WHO performs it (the implementing engineer, on the shared dev instance, as part of this
change's own PR) and WHAT the observable is as an exact, checkable set — never "the items are absent" on its
own, since a negative assertion with no positive control passes identically whether the gate correctly denies
or the nav simply failed to render.

## Test Cases

### TC-1: Instructor-group member sees the Learning-analytics trend heatmap
- **spec_ref**: `openspec/changes/fix-dead-role-gates/specs/dashboard/spec.md#requirement-every-manifest-role-visibility-literal-must-resolve-to-a-value-the-role-resolver-can-emit`
- **type**: functional
- **persona**: n/a (staff role, not one of the 9 citizen personas)
- **who verifies**: the implementing engineer, live on `localhost:8080`, as part of this change's PR
- **preconditions**: A Nextcloud user `test-instructor` is created and added to the `instructors` group and no
  other privileged group (positive control: first confirm via `occ user:info` or the Users admin page that
  `test-instructor`'s only group beyond the default is `instructors`)
- **steps**: Log in as `test-instructor`; open the Scholiq navigation; open Group Trend Heatmap
- **expected result**: The Group Trend Heatmap menu item is visible under Student analytics; opening it renders
  without error. Recorded observable: a screenshot of the navigation tree with Group Trend Heatmap visible,
  attached to the PR.

### TC-2: Coordinator-group member sees exactly the six coordinator-gated items
- **spec_ref**: `openspec/changes/fix-dead-role-gates/specs/dashboard/spec.md#requirement-every-manifest-role-visibility-literal-must-resolve-to-a-value-the-role-resolver-can-emit`
- **type**: functional
- **persona**: n/a
- **who verifies**: the implementing engineer, live on `localhost:8080`
- **preconditions**: A Nextcloud user `test-coordinator` is created, member of `coordinators` only
- **steps**: Log in as `test-coordinator`; open the Scholiq navigation; enumerate every visible top-level and
  nested menu entry
- **expected result**: Exactly the following six of the 24 role-gated items are visible — Point Rules,
  Engagement Levels, Leaderboards, Point Awards, Engagement Risk Thresholds, Timetable Conflict Queue — named
  individually by the implementing engineer against the checklist in `tasks.md` Task 5, not a general "looked
  right" judgment. Recorded observable: the six-item checklist, each row marked seen/not-seen, attached to the
  PR.

### TC-3: Guardian-group member sees Book Conference Slots and nothing staff-only
- **spec_ref**: `openspec/changes/fix-dead-role-gates/specs/dashboard/spec.md#requirement-every-manifest-role-visibility-literal-must-resolve-to-a-value-the-role-resolver-can-emit`
- **type**: security
- **persona**: n/a
- **who verifies**: the implementing engineer, live on `localhost:8080`
- **preconditions**: A Nextcloud user `test-guardian` is created, member of `guardians` only
- **steps**: Log in as `test-guardian`; open the Scholiq navigation; enumerate every visible top-level and
  nested menu entry against the full 24-item role-gated checklist
- **expected result**: Book Conference Slots is the only role-gated item visible (1 of 24); all 23 others are
  individually confirmed absent by the implementing engineer walking the checklist row by row. This is the
  positive control that pairs with TC-4's negative assertion for the same gate: `test-guardian` sees Book
  Conference Slots (this TC), `test-learner`-with-no-other-group also sees it (TC-4) because `learner` remains
  in that gate's `in[]`, and every OTHER role-gated item is absent for both — proving the gate discriminates
  correctly rather than merely rendering nothing.

### TC-4: Learner with no privileged group sees exactly 1 of 24 role-gated items
- **spec_ref**: `openspec/changes/fix-dead-role-gates/specs/dashboard/spec.md#requirement-every-manifest-role-visibility-literal-must-resolve-to-a-value-the-role-resolver-can-emit`
- **type**: security
- **persona**: n/a
- **who verifies**: the implementing engineer, live on `localhost:8080`
- **preconditions**: A Nextcloud user `test-learner-only` is created with no group membership beyond Nextcloud
  defaults — explicitly NOT in `instructors`, `administration-managers`, `team-leads`, `coordinators`,
  `guardians`, `hr`, `compliance-officers`, or the NC admin group (positive control: confirm via `occ
  user:info` that group membership is empty before logging in)
- **steps**: Log in as `test-learner-only`; open the Scholiq navigation; walk the full 24-item role-gated
  checklist (the same one used for TC-2/TC-3) row by row
- **expected result**: The visible count is exactly **1 of 24** — Book Conference Slots, via the gate's
  `learner` literal. The other 23 are each individually marked absent by name on the checklist — not a
  paragraph asserting "the rest are hidden." My learning also remains visible (governed by the separate
  group-gated dashboard requirement, not `primaryRole` — confirms the nav rendered at all, which is the
  positive control this refusal case needs: a nav that failed to render entirely would also show 0 role-gated
  items, so My learning's presence is what distinguishes "correctly denied" from "broken."). Recorded
  observable: the completed 24-row checklist plus a screenshot, attached to the PR.

### TC-5: Admin sees the Compliance item and it renders real data
- **spec_ref**: `openspec/changes/fix-dead-role-gates/specs/dashboard/spec.md#requirement-administrators-must-retain-access-to-every-role-gated-menu-item`
- **type**: functional
- **persona**: n/a
- **who verifies**: the implementing engineer, live on `localhost:8080`
- **preconditions**: Logged in as a member of the Nextcloud admin group
- **steps**: Open the Scholiq navigation; locate Compliance under Insight; open it
- **expected result**: The Compliance menu item is visible; `/apps/scholiq/compliance` renders with the seeded
  regulation, attestation, and external-training coverage data (already confirmed live pre-fix — this TC
  re-confirms the DOOR is now present, not that the page works). Recorded observable: screenshot of the nav
  with Compliance visible, and of the rendered page.

### TC-6: Admin sees Book Conference Slots
- **spec_ref**: `openspec/changes/fix-dead-role-gates/specs/dashboard/spec.md#requirement-administrators-must-retain-access-to-every-role-gated-menu-item`
- **type**: functional
- **persona**: n/a
- **who verifies**: the implementing engineer, live on `localhost:8080`
- **preconditions**: Logged in as a member of the Nextcloud admin group
- **steps**: Open the Scholiq navigation; locate Book Conference Slots under Conferences
- **expected result**: The menu item is visible and routes correctly. Recorded observable: screenshot.

### TC-7: DashboardRoleService unit coverage for the canonical role vocabulary
- **spec_ref**: `openspec/changes/fix-dead-role-gates/specs/dashboard/spec.md#requirement-every-manifest-role-visibility-literal-must-resolve-to-a-value-the-role-resolver-can-emit`
- **type**: regression
- **persona**: n/a
- **who verifies**: CI (`composer test`), no manual step
- **preconditions**: PHPUnit test doubles for `IGroupManager` covering membership in each of `instructors`,
  `administration-managers`, `team-leads`, `coordinators`, `guardians`, `compliance-officers`, `hr`, plus a
  no-membership case and an NC-admin case
- **steps**: Run `tests/Unit/Service/DashboardRoleServiceTest.php`
- **expected result**: `resolvePrimaryRole()` returns `instructor`/`administration-manager`/`team-lead`/
  `coordinator`/`guardian`/`compliance-officer`/`hr` respectively for each membership case, `admin` for the
  NC-admin case (checked first, short-circuits), and `learner` for the no-membership case
- **test command**: `composer test` (PHPUnit)

### TC-8: Gate 30 fails on a manifest role literal the resolver cannot produce
- **spec_ref**: `openspec/changes/fix-dead-role-gates/specs/dashboard/spec.md#requirement-a-ci-gate-must-reject-a-manifest-role-literal-the-resolver-cannot-emit-and-a-group-name-no-declaration-provisions`
- **type**: regression
- **persona**: n/a
- **who verifies**: CI (hydra-gates test runner), no manual step
- **preconditions**: `broken/` fixture under `hydra/scripts/test-fixtures/effective-manifest/` seeded with a
  `visibleIf.user.primaryRole.in` literal the fixture resolver cannot emit
- **steps**: Run `node hydra/scripts/lib/check_manifest_crossref.js --app-dir <fixture-dir> --manifest <assembled>`
- **expected result**: A `role-resolvable` FAIL finding is reported naming the menu item id and literal; gate
  exit code is non-zero
- **test command**: `/test-api` (gate script is a CLI, driven the same way a Newman collection drives an
  endpoint — direct invocation, asserting exit code + JSON finding shape)

### TC-9: Gate 30 fails on an undeclared group name
- **spec_ref**: `openspec/changes/fix-dead-role-gates/specs/dashboard/spec.md#requirement-a-ci-gate-must-reject-a-manifest-role-literal-the-resolver-cannot-emit-and-a-group-name-no-declaration-provisions`
- **type**: regression
- **persona**: n/a
- **who verifies**: CI (hydra-gates test runner), no manual step
- **preconditions**: `broken/` fixture seeded with an `isInGroup()` call site naming a group id declared nowhere
  in the fixture's register JSON
- **steps**: Run `node hydra/scripts/lib/check_manifest_crossref.js --app-dir <fixture-dir> --manifest <assembled>`
- **expected result**: A `group-declared` FAIL finding is reported naming the call site and group id; gate exit
  code is non-zero
- **test command**: `/test-api`

### TC-10: Gate 30 passes clean when every literal and group resolve
- **spec_ref**: `openspec/changes/fix-dead-role-gates/specs/dashboard/spec.md#requirement-a-ci-gate-must-reject-a-manifest-role-literal-the-resolver-cannot-emit-and-a-group-name-no-declaration-provisions`
- **type**: regression
- **persona**: n/a
- **who verifies**: CI (hydra-gates test runner), no manual step
- **preconditions**: `good/` fixture — resolver + manifest + register JSON where every role literal and group
  id resolve
- **steps**: Run the gate against the `good/` fixture; separately, run it against Scholiq's own post-fix
  `src/manifest.json` + `lib/Service/DashboardRoleService.php` + `lib/Settings/scholiq_register.json`
- **expected result**: Zero `role-resolvable` / `group-declared` findings on both the fixture and the real
  Scholiq tree; gate exits 0
- **test command**: `/test-api`

## Coverage Summary

| Requirement | Covered by | Notes |
|---|---|---|
| Every manifest role-visibility literal MUST resolve to a value the role resolver can emit | TC-1, TC-2, TC-3, TC-4, TC-7 | TC-1–4 are live/named-verifier per the spec's `@e2e exclude` reasoning; TC-4 pairs with TC-3 as an explicit positive/negative control pair on the same gate (`BookConferenceSlotsMenu`); TC-7 is the automated PHPUnit substitute at the unit level |
| Administrators MUST retain access to every role-gated menu item | TC-5, TC-6 | Live/named-verifier, same harness constraint |
| A CI gate MUST reject a manifest role literal the resolver cannot emit, and a group name no declaration provisions | TC-8, TC-9, TC-10 | Fully automated — the gate script itself is the test subject, no browser needed |

## Out of Scope

- The 15 role-gated menu items not individually walked in TC-1/TC-2/TC-3 (beyond their inclusion in the
  24-item checklist TC-4 exhaustively confirms absent-for-a-learner) are not each given their own dedicated
  positive-control TC — TC-7 (resolver-level unit coverage) plus TC-8–10 (gate-level regression coverage) is
  the mechanism that guarantees ALL of them structurally, not a per-item browser pass for every one. The
  `administration-manager`/`team-lead` items specifically (Data exchange × 4, Conference Schedule Board) are
  covered by TC-7's unit assertions for the resolver side; their manifest-side correctness is covered by TC-10
  running the gate against Scholiq's real, post-fix tree.
- `finance` is not tested as a producible value (Decision 5, `design.md`) — there is no positive scenario for
  it by design; its absence from the Payments gates is confirmed by TC-4's checklist (Payments items are among
  the 23 confirmed-absent for a plain learner) and by the manifest content itself no longer naming it.
- `RoleSelector` / `LearnerProfile.primaryRole` calculation coverage is unaffected by this change and not
  retested here — it is a separate service (design.md Non-Goals).
- The guardian per-slot scoping residual (design.md Decision 6) is explicitly NOT tested here as a pass/fail —
  it is a known, accepted, documented gap for this change, not a requirement this change claims to satisfy.
  TC-3 confirms guardian VISIBILITY only; scoping verification belongs to the later administrations change's
  own test plan.
