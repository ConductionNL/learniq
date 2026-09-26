---
kind: config
---

# Proposal: role-dashboards

## Summary
Adds three discoverable, role-gated navigation entries — Mentor, IB-er (intern begeleider), and
Director — that route to the existing operational (teacher-shaped) dashboard, gated on the
existing, already-resolved `user.primaryRole` values `instructor`, `coordinator`, and
`administration-manager` respectively. No new page, no new Vue component, no PHP change.

## Motivation
Round-1 competitor research (`compare/change-plan.md`, "Report cards, care and support" table)
carries `role-dashboards` against findings `12.1` and `8.12`:

- **12.1** Role dashboards (`compare/findings.md`): studytube, docebo, moodle-workplace, leeruniek
  and osiris (plus 5 more) all ship distinct dashboard entry points per role beyond
  admin/teacher/student. learniq's own `LearniqDashboards.vue` currently resolves exactly three
  views (`admin`/`teacher`/`student`, `DashboardRoleService::resolveViews()`); a mentor,
  intern begeleider (IB-er), or director signing in today has no named entry point of their own —
  they either see the generic Teaching item (if they also carry `instructor`/`coordinator`/
  `administration-manager` group membership) with no indication it applies to their role, or they
  see nothing at all.
- **8.12** Mentor dashboard (`compare/findings.md`): itslearning, eduarte, magister, somtoday.
  learniq's `DashboardTeacher` already shows "sessions to mark" and cohort lists; a distinct
  mentor-scoped view of *my own class's* flags/absences/grades is a further gap.

## Affected Projects
- [x] Project: `learniq` — three new role-gated `src/manifest.d/dashboard.json` nav entries.

## Scope

### In Scope
- Three new nav children under the existing `GroupInsight` menu group in
  `src/manifest.d/dashboard.json`: `DashboardMentorMenu` (label "Mentor", gated on
  `user.primaryRole: {in: ["instructor", "admin"]}`), `DashboardIbMenu` (label "IB-er", gated on
  `user.primaryRole: {in: ["coordinator", "admin"]}`), `DashboardDirectorMenu` (label "Director",
  gated on `user.primaryRole: {in: ["administration-manager", "admin"]}`) — each routing to the
  existing `DashboardTeacher` page.
- This closes finding 12.1's **discoverability** gap honestly: a mentor, IB-er, or director now has
  a named entry point that says "this is for you", using this app's own real role vocabulary
  (`instructor`/`coordinator`/`administration-manager` — there is no separate `mentor`/`ib-er`/
  `director` Nextcloud group in `rbac-declare-groups`; IB-er maps to this school's existing
  `coordinator` care-coordination role, director to `administration-manager`, mentor to
  `instructor`, since a mentor is a teacher role in this fleet's vocabulary, not a separate group).

### Out of Scope
- **Distinct dashboard CONTENT per new role** (a genuinely separate widget set for mentor vs IB-er
  vs director, beyond the shared `DashboardTeacher` operational view): `DashboardTeacher` is a
  `type: "custom"` page mounting a bespoke Vue component (`LearniqDashboards.vue`, `VALID_ROLES =
  ['admin', 'teacher', 'student']`); giving each new nav entry its own widget mix requires either a
  new Vue wrapper component or new role branches inside that component — a `code`-kind change
  (ADR-032 forbids mixing config and code in one spec), not this one. This change ships the
  discoverable entry point only; a follow-up `code` change can differentiate the content once
  scoped.
- **8.12's mentor scoping of the "sessions to mark" tile plus a "today" filter**: investigated and
  explicitly deferred. `Session` has no `teacherId`/mentor-ownership field of its own (only
  `cohortId`; the mentor relationship lives on `Cohort.teacherIds[]`), so scoping "my sessions"
  requires either a new backend join capability or a client-side fetch-then-filter — genuine `code`
  work in `LearniqDashboards.vue`/`ManageListWidget.vue`, not a manifest change. A "today" filter on
  a date field additionally has no verified backend range-filter grammar in this app (`ManageListWidget`
  passes its `filter` prop straight through as raw query-string params to OpenRegister's object
  API; every precedent anywhere in this codebase for that call is exact-match, never a range), and
  this repo has no Vue-component unit-test harness (`tests/unit-js/*.test.mjs` uses plain
  `node --test`, not `@vue/test-utils`/vitest) to verify a client-side date-filter branch would
  actually work before shipping it. Both risks are real enough that shipping either now would
  repeat the exact "unverified operator, silent no-op" mistake `care-and-support-index` (a sibling
  change in this same round) caught and reverted before commit. Filed as follow-up work, not
  attempted here.

## Approach
Three declarative nav-menu additions, reusing the existing `DashboardTeacher` route/component and
this app's existing `user.primaryRole` runtime value (already resolved server-side by
`DashboardRoleService` and exposed via initial state — no PHP change needed, since `primaryRole`
already carries the finer-grained values `instructor`/`coordinator`/`administration-manager`, not
just the three `canXDashboard` booleans the existing Administration/Teaching/My-learning items
gate on). This mirrors the existing `Compliance`/`AiProcessingDisclosure` nav entries in the same
file, which already gate on `user.primaryRole` directly rather than a dedicated boolean.

## New Dependencies
None.

## Impact
- `src/manifest.d/dashboard.json`: three new nav children.

## Cross-Project Dependencies
None.

## Risks

### Risk 1: A relabelled entry point to unchanged content may read as misleading
**Severity:** Low — **Mitigation:** each new entry's `_note`/PR description states plainly that it
routes to the existing operational dashboard, not a bespoke one; the value delivered this round is
discoverability (a mentor/IB-er/director now has a named way in), not new content — documented as
an explicit Out of Scope boundary, not silently implied as "done".

## Rollback Strategy
Remove the three menu entries; no schema, page, or component is touched, so rollback is a pure
manifest revert with no data or route impact.

## Open Questions
None — corpus evidence and this app's own role vocabulary are specific enough to proceed; the scope
boundaries above record judgment calls made under headless operation.
