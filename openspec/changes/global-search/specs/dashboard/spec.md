## ADDED Requirements

### Requirement: A fast-finder query builder splits one search term into per-kind OpenRegister requests

`src/utils/globalSearch.js` MUST export `buildGlobalSearchRequests(term, options)`, a pure function that,
given a non-blank search term, returns exactly two OpenRegister request descriptors — one for the
`learner-profile` schema and one for the `cohort` schema, both `x-openregister.searchable: true` — each
carrying an OpenRegister `_search` param set to the trimmed term and a `_limit` (default 8). A blank or
whitespace-only term MUST return an empty array (no unfiltered request is ever issued).

`classifyPersonKind(roles)` MUST classify a `learner-profile` row as `'staff'` when its `roles` array
contains any of `instructor`, `hr`, `manager`, `compliance-officer`, `admin`, `mentor`, `principal`, or
`inspector`, and `'learner'` otherwise (including an empty, missing, or `learner`/`parent`-only `roles`
array) — findings G-new-1 (learniq has no dedicated `Staff` schema; a staff member is a `LearnerProfile`).

#### Scenario: A blank term builds no requests

- **GIVEN** an empty or whitespace-only search term
- **WHEN** `buildGlobalSearchRequests` is called
- **THEN** it returns an empty array

#### Scenario: A real term builds one learner-profile and one cohort request

- **GIVEN** a non-blank search term
- **WHEN** `buildGlobalSearchRequests` is called
- **THEN** it returns a `learner-profile` request and a `cohort` request, each carrying `_search` set to the
  trimmed term

#### Scenario: Every declared staff role classifies as staff, not learner

- **GIVEN** a `learner-profile` row whose `roles` array contains a staff role (`instructor`, `hr`, `manager`,
  `compliance-officer`, `admin`, `mentor`, `principal`, or `inspector`)
- **WHEN** `classifyPersonKind` is called with that row's `roles`
- **THEN** it returns `'staff'`

#### Scenario: A row with no roles set defaults to learner

- **GIVEN** a `learner-profile` row with an empty or missing `roles` array
- **WHEN** `classifyPersonKind` is called
- **THEN** it returns `'learner'`

### Requirement: A fast-finder widget on the People dashboard

`PeopleDashboard.vue` MUST include a full-width `GlobalSearchWidget` above its existing KPI row, searching
across `LearnerProfile` (grouped into Learners/Staff by `classifyPersonKind`) and `Cohort`, with results
grouped by kind and each result keyboard-reachable (a real router-link, not a mouse-only click target). No
new top-level menu entry is added.

#### Scenario: A search finds a learner, a staff member, and a cohort in one box

<!-- @e2e exclude no local Nextcloud instance was exercised for this change (see proposal Open Questions);
     the query-builder/classifier contract this widget depends on is covered by
     tests/unit-js/globalSearch.test.mjs. A live browser verification pass is a named follow-up, not silently
     skipped. -->

- **GIVEN** the People dashboard is open
- **WHEN** a user types a name matching a learner, a staff member, and a cohort
- **THEN** all three appear, grouped under "Learners", "Staff", and "Cohorts" headings
- **AND** each result is reachable by keyboard (Tab to focus, Enter to navigate) and navigates to the
  matching detail page

#### Scenario: No new top-level menu entry is added

- **GIVEN** the app's menu manifest
- **WHEN** it is read after this change
- **THEN** no new top-level or `GroupPeople`-child menu entry named for global search exists — the widget
  lives only on the existing People dashboard page
