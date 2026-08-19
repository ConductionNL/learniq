# Tasks: fix-dead-role-gates

## Implementation Tasks

### Task 1: Rewrite DashboardRoleService's role vocabulary and group-id mapping
- **spec_ref**: `openspec/changes/fix-dead-role-gates/specs/dashboard/spec.md#requirement-every-manifest-role-visibility-literal-must-resolve-to-a-value-the-role-resolver-can-emit`
- **files**: `lib/Service/DashboardRoleService.php`
- **acceptance_criteria**:
  - GIVEN `GROUP_BACKED_ROLES` today is a positional list of role names combined with the `scholiq-` prefix WHEN this task is complete THEN it is an associative `role => unprefixed-group-id` map: `compliance-officer => compliance-officers`, `hr => hr`, `administration-manager => administration-managers`, `team-lead => team-leads`, `coordinator => coordinators`, `instructor => instructors`, `guardian => guardians` (design.md Decisions 1–4)
  - GIVEN a user in the `team-leads` group WHEN `resolvePrimaryRole()` runs THEN it returns `team-lead`
  - GIVEN a user in the `administration-managers` group WHEN `resolvePrimaryRole()` runs THEN it returns `administration-manager` (not `manager`)
  - GIVEN a user in the `instructors` group WHEN `resolvePrimaryRole()` runs THEN it returns `instructor` — the string itself is UNCHANGED from today; only the group id it checks moves from `scholiq-instructor`
  - GIVEN a user in the `guardians` group and no other privileged group WHEN `resolvePrimaryRole()` runs THEN it returns `guardian`
  - GIVEN a user in no privileged group and not an NC admin WHEN `resolvePrimaryRole()` runs THEN it still returns `learner` (unconditional fallback — NOT gated on the `learners` group; design.md Decision 3)
  - GIVEN `resolveViews()`'s `in_array($role, ['manager', 'instructor'], true)` check WHEN this task is complete THEN it reads `in_array($role, ['administration-manager', 'team-lead', 'coordinator', 'instructor'], true)`, and `guardian` is not added to either `in_array()` check (falls through to the base `student` tier only)
  - GIVEN the class docblock's `scholiq-{role}` convention description WHEN this task is complete THEN it is updated to describe the unprefixed group-id map and the canonical vocabulary table
- [x] Implement
- [x] Test

### Task 2: Update PHPUnit coverage for the canonical role vocabulary
- **spec_ref**: `openspec/changes/fix-dead-role-gates/specs/dashboard/spec.md#requirement-every-manifest-role-visibility-literal-must-resolve-to-a-value-the-role-resolver-can-emit`
- **files**: `tests/Unit/Service/DashboardRoleServiceTest.php`
- **acceptance_criteria**:
  - GIVEN the existing assertion `assertSame('instructor', ...)` for a `scholiq-instructor` member WHEN this task is complete THEN it asserts `'instructor'` for an `instructors` member (string unchanged, group id updated)
  - GIVEN a new test double member of the `administration-managers` group WHEN `resolvePrimaryRole()` runs THEN it asserts `'administration-manager'`
  - GIVEN a new test double member of the `team-leads` group WHEN `resolvePrimaryRole()` runs THEN it asserts `'team-lead'`
  - GIVEN a new test double member of the `coordinators` group WHEN `resolvePrimaryRole()` runs THEN it asserts `'coordinator'`
  - GIVEN a new test double member of the `guardians` group WHEN `resolvePrimaryRole()` runs THEN it asserts `'guardian'`
  - GIVEN a test double user in no privileged group and not an NC admin WHEN `resolvePrimaryRole()` runs THEN it asserts `'learner'` (the refusal/negative case — proves the resolver does not grant a role nobody is entitled to, and that `learner` needs no group membership)
- [x] Implement
- [x] Test

### Task 3: Grant administrators a door to Compliance and Book Conference Slots
- **spec_ref**: `openspec/changes/fix-dead-role-gates/specs/dashboard/spec.md#requirement-administrators-must-retain-access-to-every-role-gated-menu-item`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the `Compliance` menu item's `visibleIf.user.primaryRole.in` is `["compliance-officer", "hr"]` WHEN this task is complete THEN it is `["compliance-officer", "hr", "admin"]`
  - GIVEN `BookConferenceSlotsMenu`'s pre-fix gate is `["parent", "learner"]` WHEN this task is complete THEN it is `["guardian", "learner", "admin"]` (`parent`→`guardian` per Task 4's vocabulary correction, `admin` added here)
- [x] Implement
- [x] Test

### Task 4: Correct every manifest role literal onto the canonical vocabulary
- **spec_ref**: `openspec/changes/fix-dead-role-gates/specs/dashboard/spec.md#requirement-every-manifest-role-visibility-literal-must-resolve-to-a-value-the-role-resolver-can-emit`
- **files**: `src/manifest.json`
- **acceptance_criteria** (full before/after table — 13 of 24 gates change content; the other 11 are unchanged):
  - GIVEN `DataMappingProfilesMenu`, `DataExchangeJobsMenu`, `ExchangeRejectionsMenu`, `ExchangeErrorCodesMenu` each name `["admin", "principal"]` WHEN this task is complete THEN each names `["admin", "administration-manager"]`
  - GIVEN `GroupTrendHeatmapMenu` names `["admin", "teacher"]` WHEN this task is complete THEN it names `["admin", "instructor"]`
  - GIVEN `CourseEvaluationResponsesMenu` names `["admin", "coordinator", "teacher"]` WHEN this task is complete THEN it names `["admin", "coordinator", "instructor"]`
  - GIVEN `ConferenceScheduleBoardMenu` names `["admin", "mentor", "principal"]` WHEN this task is complete THEN it names `["admin", "team-lead", "administration-manager"]`
  - GIVEN the four Payments gates (`FeeItemsMenu`, `OrderLinesMenu`, `PaymentTransactionsMenu`, `EntitlementsMenu`) each name `["admin", "finance"]` WHEN this task is complete THEN each names `["admin"]` only (ADR-081 — ​note the rationale in the PR description since the single-value list otherwise reads as an oversight)
  - GIVEN `EngagementRiskThresholdsMenu`, `PointRulesMenu`, `EngagementLevelsMenu`, `LeaderboardsMenu`, `PointAwardsMenu`, `TimetableConflictQueueMenu` each already name `["admin", "coordinator"]` WHEN this task is complete THEN they are unchanged (the literal was always correct; only the resolver could not produce it before Task 1)
  - GIVEN `AccessibilityLimitationsMenu`, `AiProcessingDisclosureMenu`, `AccessibilityFeedbacksMenu`, `Rollover`, `ExternalTraining` already name only canonical, already-producible values WHEN this task is complete THEN they are unchanged
- [x] Implement
- [x] Test

### Task 5: Live-verify every gate on the shared dev instance, with a named verifier and an exact observable
- **spec_ref**: `openspec/changes/fix-dead-role-gates/specs/dashboard/spec.md#requirement-administrators-must-retain-access-to-every-role-gated-menu-item`
- **files**: none (verification task — browser-driven against `localhost:8080`; see `test-plan.md` TC-1–TC-7 for the full named-verifier, exact-observable protocol)
- **acceptance_criteria**:
  - GIVEN an admin session WHEN the Scholiq navigation is opened THEN Compliance renders under Insight and Book Conference Slots renders under Conferences, both reachable and rendering real data — the implementing engineer performs this check and attaches a screenshot to the PR
  - GIVEN one test user is added to each of `instructors`, `administration-managers`, `team-leads`, `coordinators`, `guardians`, `compliance-officers`, `hr` (created manually if `rbac-declare-groups` has not yet provisioned them — design.md Decision 4) WHEN each logs in and opens the navigation THEN the implementing engineer records, per user, the exact set of role-gated menu items visible, cross-checked item-by-item against Task 4's table — not a general "looked correct" pass
  - GIVEN a same-session no-group learner WHEN the navigation is opened THEN the implementing engineer confirms the EXACT count (1 of 24 role-gated items visible — Book Conference Slots) and names the other 23 explicitly as confirmed absent, per the spec's refusal scenario
  - GIVEN this verification pass WHEN it completes THEN its results (screenshots plus the per-role checklist) are attached to the PR, since every scenario above carries `@e2e exclude` in the spec and has no automated substitute
- [x] Implement
- [x] Test

### Task 6: Extend hydra gate 30 with `role-resolvable` and `group-declared` checks
- **spec_ref**: `openspec/changes/fix-dead-role-gates/specs/dashboard/spec.md#requirement-a-ci-gate-must-reject-a-manifest-role-literal-the-resolver-cannot-emit-and-a-group-name-no-declaration-provisions`
- **files**: `hydra` repo — `conduction/hydra-gates` package `scripts/lib/check_manifest_crossref.js`
- **acceptance_criteria**:
  - GIVEN an assembled manifest with a `visibleIf.user.primaryRole.in[]` literal absent from the app's role resolver's discoverable producible-value set WHEN gate 30 runs THEN it reports a `role-resolvable` FAIL finding naming the menu item id and the literal
  - GIVEN an `IGroupManager::isInGroup($uid, '<literal>')` call site whose literal group id resolves against neither the app's OAS scope map nor its `authorization` blocks WHEN gate 30 runs THEN it reports a `group-declared` FAIL finding naming the call site and the group id
  - GIVEN an app with no discoverable `primaryRole`-shaped resolver (most apps) WHEN gate 30 runs THEN the `role-resolvable` check WARNs and does not fail, matching the gate's existing WARN-on-unknowable posture
  - GIVEN a dynamically-constructed `isInGroup()` argument (e.g. string concatenation) WHEN gate 30 runs THEN the `group-declared` check WARNs rather than FAILs, since it cannot statically resolve the literal
- [ ] Implement
- [ ] Test

### Task 7: Add gate-30 fixtures and self-tests for both new checks
- **spec_ref**: `openspec/changes/fix-dead-role-gates/specs/dashboard/spec.md#requirement-a-ci-gate-must-reject-a-manifest-role-literal-the-resolver-cannot-emit-and-a-group-name-no-declaration-provisions`
- **files**: `hydra` repo — `scripts/test-fixtures/effective-manifest/good/`, `.../broken/`, `scripts/lib/test_check_manifest_crossref.js`
- **acceptance_criteria**:
  - GIVEN the `good/` fixture (a resolver + manifest pair whose every literal and group resolve cleanly) WHEN `test_check_manifest_crossref.js` runs THEN both new checks report zero findings and the gate exits 0
  - GIVEN the `broken/` fixture gains one seeded defect per new check class (one unresolvable `visibleIf` literal, one undeclared `isInGroup()` group) WHEN the test runs THEN both defects are reported and the gate exits non-zero
- [ ] Implement
- [ ] Test

### Task 8: Document the two new checks and sync role-vocabulary references
- **spec_ref**: `openspec/changes/fix-dead-role-gates/design.md#ci-gate-extension-hydra-gate-30`
- **files**: `hydra` repo — `.claude/skills/hydra-gate-effective-manifest-crossref/SKILL.md`; `scholiq` repo — any `docs/` page describing Scholiq's role/group model
- **acceptance_criteria**:
  - GIVEN the gate-30 skill's Check section currently lists 4 check classes WHEN this task is complete THEN it lists 6, with `role-resolvable` and `group-declared` documented alongside their fail/warn conditions and fix recipes, matching the pattern of the existing 4
  - GIVEN any Scholiq docs page names the `scholiq-{role}` group convention or the retired school-only role words (`teacher`, `principal`, `mentor`, `parent`) as identifiers WHEN this task is complete THEN it is updated to the canonical vocabulary, or a note is added that the docs page is out of date if updating it is out of scope for this change
- [ ] Implement
- [ ] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/Service/DashboardRoleServiceTest.php`) — Task 2
- No new API endpoints — Newman/Postman coverage N/A
- UI changes are visibility-only (no new components); covered by the named-verifier live verification in Task 5 rather than Playwright, since every affected scenario is `@e2e exclude`d in the spec for requiring per-test group-membership switching the harness cannot provision
- All tests pass (`composer test` for scholiq; the hydra-gates package's own test runner for Tasks 6–7)
- No new user-facing strings are introduced (menu labels are unchanged; only their `visibleIf` gates and the values they compare change) — i18n N/A
- Feature documentation: Task 8 covers the role-vocabulary and gate-30 doc sync; no new user-facing feature to screenshot
- `openspec validate` passes
