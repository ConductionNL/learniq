# Nextcloud App Specification

## ADDED Requirements

### Requirement: The page tells a chosen segment apart from the default
The server MUST publish, next to the `segment` initial state, a `chosenSegment` initial state: the stored segment when a `LearniqSettings` row carries a known code AND its `setBy` names an existing Nextcloud user, otherwise `null`. The browser MUST place it at `runtime.workspace.chosenSegment` as one of the six codes or `null`; any other value MUST become `null`. Rows written by the setup wizard name the admin who chose; the generated demo rows name fictional people and therefore MUST NOT count as a choice. A failed read MUST yield `null`, so the page still renders with every menu.

#### Scenario: The wizard stored Company
- **GIVEN** the newest `LearniqSettings` row has `segment: corporate` and `setBy: admin`, an existing user
- **WHEN** a signed-in user opens the app
- **THEN** the page carries `segment: corporate` and `chosenSegment: corporate`

#### Scenario: Only the generated demo rows exist
- **GIVEN** the only `LearniqSettings` rows are the three generated demo rows with `setBy: Voorbeeld Setby 1`, 2 and 3
- **WHEN** a signed-in user opens the app
- **THEN** the page carries `segment: corporate` and `chosenSegment: null`

#### Scenario: OpenRegister cannot be read
- **GIVEN** SegmentService throws when resolved
- **WHEN** a signed-in user opens the app
- **THEN** the page renders with `segment: corporate` and `chosenSegment: null`

### Requirement: The company segment hides the school-only menus
Every surface of the school-only groups MUST carry `visibleIf: {"workspace.chosenSegment": {"notIn": ["corporate"]}}`: the Attendance flags reports card (attendance flags, leerplicht and verzuim reporting), the report periods, report cards and report card templates entries, the admissions entries (applications, admissions rounds, review board), school advies, the parent conferences entries, the BPV cards and reports card, and the exam board cards (exemption requests, fraud cases, item revision flags). A surface is a menu entry that survives `menu-layout.json`, a card on a nav-card-grid landing page, or a card on the Reports page. The Reports page MUST apply a card's `visibleIf` with the shared library's `passesContextPredicates`, since `CnReportsPage` does not.

#### Scenario: A company that chose Company
- **GIVEN** `runtime.workspace.segment` and `runtime.workspace.chosenSegment` are both `corporate` and the user is an admin
- **WHEN** the navigation, the Progress and Compliance landing pages and the Reports page render
- **THEN** attendance flags, report cards, admissions, school advies, parent conferences, BPV and the exam board do not show
- **AND** compliance, external training, courses, people, attendance records, engagement, course evaluation and the other entries still show

#### Scenario: A training institute keeps admissions and the exam board
- **GIVEN** `runtime.workspace.chosenSegment` is `training`
- **WHEN** the admissions entries and the exam board cards are evaluated for an admin
- **THEN** they show

### Requirement: An install that never chose keeps every menu
With `runtime.workspace.segment` `corporate` and `runtime.workspace.chosenSegment` `null`, every surface MUST show for an admin, as before this change. Every `workspace.chosenSegment` predicate MUST use only `notIn` with known segment codes, so a `null` value always passes, and every `workspace.segment` gate MUST keep `corporate`. `npm run check:menu-role-gates` MUST fail when either rule breaks, on menu entries and on landing and reports cards alike.

#### Scenario: An existing install after the upgrade
- **GIVEN** `runtime.workspace.segment` is `corporate` and `runtime.workspace.chosenSegment` is `null`
- **WHEN** every menu entry, landing card and reports card is evaluated for an admin
- **THEN** every one shows

#### Scenario: A chosen-segment gate that would hide from installs that never chose
- **GIVEN** a menu entry with `visibleIf: {"workspace.chosenSegment": {"in": ["po"]}}`
- **WHEN** `npm run check:menu-role-gates` runs
- **THEN** it exits 1 and names the entry

### Requirement: Segment gates sit on surfaces that render
A `workspace.segment` or `workspace.chosenSegment` gate MUST NOT sit on a menu group that `menu-layout.json` relocates, because the shared `applyMenuRelocations()` dissolves the group and drops its `visibleIf`. The gate MUST sit on the group's children and on the landing or reports cards that carry the group's pages. The segment gates `segment-menu-gating` placed on `GroupBpv`, `GroupStudyProgress`, `GroupEngagement`, `GroupCourseEvaluation` and `GroupExamBoard` MUST move to those surfaces, and the gate on `GroupCompliance` MUST move to the two company cards inside it (the compliance overview and external training), so a school keeps the exam board, accessibility and privacy cards. `npm run check:menu-role-gates` MUST fail on a segment gate on a relocated group.

#### Scenario: A primary school opens the Progress landing page
- **GIVEN** `runtime.workspace.segment` and `runtime.workspace.chosenSegment` are `po` and the user is an admin
- **WHEN** the Progress landing page renders
- **THEN** the BPV, BSA, engagement and course evaluation cards do not show
- **AND** the portfolio, competency and analytics cards show

#### Scenario: A secondary school opens Compliance
- **GIVEN** `runtime.workspace.chosenSegment` is `vo` and the user is an admin
- **WHEN** the navigation and the Compliance landing page render
- **THEN** the Compliance menu shows with the exam board, accessibility and privacy request cards
- **AND** the compliance overview and external training cards do not show

#### Scenario: A segment gate on a relocated group
- **GIVEN** `GroupBpv` carries `visibleIf: {"workspace.segment": {"in": ["mbo", "corporate"]}}` and `menu-layout.json` relocates it
- **WHEN** `npm run check:menu-role-gates` runs
- **THEN** it exits 1 and says the gate never runs
