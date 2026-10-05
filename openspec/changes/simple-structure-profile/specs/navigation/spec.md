## ADDED Requirements

### Requirement: REQ-SSP-001 Two structures are built from one manifest

learniq MUST build a simple structure and a full structure from the same manifest and the same fragments. The full structure MUST be exactly what `buildManifest` makes from the manifest, the fragments and `src/menu-layout.json`. Neither structure may add or remove a page or a route.

#### Scenario: The full structure is unchanged
@e2e exclude An equality between two built manifests, asserted in tests/unit-js/structureProfile.test.mjs against the library's real buildManifest.
- **GIVEN** `menu_structure` is `full`
- **WHEN** the manifest is built
- **THEN** it MUST equal what `buildManifest` makes from the manifest, the fragments and `src/menu-layout.json`
- **AND** the menu MUST count 108 entries: 100 in the main list, 4 in the footer and 4 in settings

#### Scenario: Every page keeps its route
@e2e exclude A comparison of two page lists, asserted in tests/unit-js/structureProfile.test.mjs.
- **GIVEN** either structure
- **WHEN** the manifest is built
- **THEN** it MUST hold the same 350 pages, with the same ids, in the same order

### Requirement: REQ-SSP-002 The simple menu shows at most ten entries per role under three captions

In the simple structure the main menu MUST be flat and MUST sit under the captions Home, Teaching and Learners. Each primary role MUST see at most ten main entries. A Nextcloud administrator MUST see at most twelve. A user who is also the confidential counsellor or a line manager MAY see two more. A gate written in the simple profile MUST NOT show a role a page the full menu does not show that role.

#### Scenario: An administrator opens learniq on the simple structure
- **GIVEN** `menu_structure` is unset or `simple`
- **WHEN** a Nextcloud administrator opens learniq
- **THEN** the main menu MUST show, in this order: the caption Home with Dashboard, My learning and Groups; the caption Teaching with My timetable, Timetables, Lessons and assignments and Grades; the caption Learners with Learners, Attendance, Progress, Care and dossier and Compliance
- **AND** it MUST NOT show Courses, Assignments, Today's register or Enrolments as menu entries

#### Scenario: A teacher sees the teacher's ten
@e2e exclude Menu gating per role; asserted in tests/unit-js/structureProfile.test.mjs, which builds the menu with the library's buildManifest and visibleIf evaluator for every role.
- **GIVEN** a user whose primary role is `instructor`
- **WHEN** the simple menu is built
- **THEN** it MUST show Dashboard, My learning, Groups, My timetable, Lessons and assignments, Grades, Learners, Attendance, Progress and Care and dossier, and nothing else in the main list

#### Scenario: A learner keeps their own entries
@e2e exclude Menu gating per role; asserted in tests/unit-js/structureProfile.test.mjs.
- **GIVEN** a user whose primary role is `learner`
- **WHEN** the simple menu is built
- **THEN** it MUST show Dashboard, My learning, My timetable, Course catalogue, My learning record, Check in, My work groups, My evaluations, Optional lessons and Pick electives

#### Scenario: No role goes over the ceiling in any segment
@e2e exclude A count over ten roles and seven workspace states; asserted in tests/unit-js/structureProfile.test.mjs.
- **GIVEN** any primary role and any chosen segment, or none
- **WHEN** the simple menu is built
- **THEN** the role MUST see at most ten main entries, an administrator at most twelve

#### Scenario: The simple menu never widens a gate
@e2e exclude A comparison of two built menus per role; asserted in tests/unit-js/structureProfile.test.mjs.
- **GIVEN** any primary role
- **WHEN** both menus are built
- **THEN** every page a simple menu entry opens for that role MUST be a page the full menu opens for that role, except Cohorts, which had no entry

### Requirement: REQ-SSP-003 What leaves the menu is linked or named

Every entry the full menu offers a role MUST, in the simple structure, be in the menu, in settings or in the footer for that role, or be linked from a page the simple menu opens for that role, or be named for that role in the list of unlinked entries the test holds. That list MUST be exact: it fails when an entry is unlinked and not named, and when an entry is named and linked.

#### Scenario: A list that left the menu is one link away
- **GIVEN** the simple structure
- **WHEN** somebody opens Attendance
- **THEN** the page MUST offer a link to Today's register
- **AND** a page that left the menu MUST still open by its own address

#### Scenario: The set-up lists are in settings
@e2e exclude A reading of the built menu; asserted in tests/unit-js/structureProfile.test.mjs.
- **GIVEN** the simple structure
- **WHEN** the manifest is built
- **THEN** the settings section MUST hold what it holds in the full structure
- **AND** it MUST hold the templates, periods, rooms, screens, locations, schools, staff, fees, rounds and the import, export and exchange tools

#### Scenario: The links exist in the simple structure only
@e2e exclude A comparison of two built pages; asserted in tests/unit-js/structureProfile.test.mjs.
- **GIVEN** the full structure
- **WHEN** the manifest is built
- **THEN** every page MUST equal the manifest's page

#### Scenario: An unlinked entry is named
@e2e exclude An exact comparison per role; asserted in tests/unit-js/structureProfile.test.mjs.
- **GIVEN** a role and an entry of the full menu that nothing in the simple structure links for that role
- **WHEN** the test runs
- **THEN** the entry MUST be in the list of unlinked entries for that role

### Requirement: REQ-SSP-004 The structure is an app setting and simple is the default

learniq MUST show the simple structure unless the app setting `menu_structure` holds the word `full`. The admin settings page MUST offer the choice between Simple and Full. The page controller MUST provide the setting as initial state, so the menu is right on the first render. Only an administrator may change it.

#### Scenario: An administrator brings the full menu back
@e2e exclude The e2e instance runs on the full structure for the whole suite (tests/e2e/ci-seed.sh sets it through the same endpoint); MenuStructureTest and tests/unit-js/structureProfile.test.mjs cover the setting and the save.
- **GIVEN** an administrator on the admin settings page
- **WHEN** they choose Full under Menu structure
- **THEN** `menu_structure` MUST be stored as `full`
- **AND** the next time somebody opens learniq the menu MUST be the full one

#### Scenario: A stored value that is not a structure
@e2e exclude A rule on a string, covered by MenuStructureTest and tests/unit-js/structureProfile.test.mjs.
- **GIVEN** `menu_structure` holds `ful`
- **WHEN** somebody opens learniq
- **THEN** the menu MUST be the simple one

#### Scenario: A save the server did not keep is not reported as saved
@e2e exclude A rule on a response body, covered by tests/unit-js/structureProfile.test.mjs and MenuStructureTest.
- **GIVEN** the settings write answers success without the stored word
- **WHEN** the admin page saves a structure
- **THEN** it MUST report that the menu was not saved

#### Scenario: The start page survives a setting it cannot read
@e2e exclude A rule on a failing container, covered by MenuStructureTest.
- **GIVEN** the setting cannot be resolved
- **WHEN** somebody opens learniq
- **THEN** the page MUST render with the simple structure

### Requirement: REQ-SSP-005 A profile may change a page and never add or remove one

A structure profile MAY overlay a page by id. An overlay that names a page the manifest does not have MUST be skipped and reported. In this change an overlay MUST only append header links to a list page or cards to the Reports page, and each link MUST name an existing page whose address needs no parameter.

#### Scenario: An overlay only appends
@e2e exclude A comparison of built pages; asserted in tests/unit-js/structureProfile.test.mjs.
- **GIVEN** the simple structure
- **WHEN** a page with an overlay is built
- **THEN** every item the page had MUST still be there, unchanged and in the same place
