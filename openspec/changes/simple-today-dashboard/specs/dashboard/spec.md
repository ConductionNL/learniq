## ADDED Requirements

### Requirement: REQ-STD-001 The start page is Today for the teaching roles in the simple structure

In the simple structure the page at `/` MUST be a typed dashboard titled Today for a user whose primary role is `instructor`, `coordinator`, `administration-manager` or `admin`. For every other role, and in the full structure, the page MUST be the role dashboard the manifest declares. The page MUST keep its id and its address, and MUST hold library widgets only.

#### Scenario: An administrator opens learniq on the simple structure and lands on Today
- **GIVEN** `menu_structure` is unset or `simple`
- **WHEN** a Nextcloud administrator opens learniq
- **THEN** the page MUST be titled Today
- **AND** it MUST show "Mijn lessen vandaag" with a link to "Weekrooster", and the counters "Lessen vandaag", "Deze week", "In te leveren", "Ongeoorloofd", "Na te kijken" and "Aanwezig vandaag"
- **AND** the Teaching dashboard MUST be one card away

#### Scenario: A school's teacher reads the counters of her own day
@e2e exclude Example-set wording; the boards wilgenboom/LqVandaag and vaartveld/LqVandaag are the reference.
- **GIVEN** a teacher in an example school set
- **WHEN** she opens Today
- **THEN** in the primary school she reads "Verwacht in de klas" and "Wacht op u"
- **AND** in the secondary school she reads "Na te kijken" and "Afwezig gemeld"

#### Scenario: A learner keeps the role dashboard
@e2e exclude A comparison of built pages per role; asserted in tests/unit-js/structureProfile.test.mjs with the library's buildManifest and visibleIf evaluator.
- **GIVEN** a user whose primary role is not one of the four
- **WHEN** the simple structure is built
- **THEN** the page at `/` MUST equal the manifest's page

#### Scenario: The full structure is unchanged
@e2e exclude A comparison of built pages per role; asserted in tests/unit-js/structureProfile.test.mjs.
- **GIVEN** `menu_structure` is `full`
- **WHEN** the manifest is built for any role
- **THEN** the page at `/` MUST equal the manifest's page

#### Scenario: A condition nobody can judge leaves the page alone
@e2e exclude A rule on the build function; asserted in tests/unit-js/structureProfile.test.mjs.
- **GIVEN** an overlay with a `when` and no evaluator
- **WHEN** the manifest is built
- **THEN** the overlay MUST be skipped

### Requirement: REQ-STD-002 Every number on Today opens the list it counts

Each of the four counts "Lessen vandaag", "Deze week", "In te leveren" and "Ongeoorloofd" MUST be a filter on fields the schema has, and MUST open a list of the same schema with the same filter: either a list that carries that filter itself, or a list opened with the filter in the address. "Na te kijken" and "Aanwezig vandaag" are figures and open nothing. A tile label MUST be at most eighteen characters. The First today card MUST show only when its count is above zero, and its button MUST open exactly the records it counts.

#### Scenario: A tile and its list agree
@e2e exclude A comparison of each tile's filter with its list's filter or address; asserted in tests/unit-js/structureProfile.test.mjs against the register.
- **GIVEN** a count on Today
- **WHEN** its filter and its link are read
- **THEN** every field in the filter MUST exist on the schema
- **AND** the list it opens MUST show the same schema with the same filter

#### Scenario: Nothing open, no card
@e2e exclude A reading of the card's condition; asserted in tests/unit-js/structureProfile.test.mjs. The live card depends on seeded attendance flags.
- **GIVEN** no attendance flag is open
- **WHEN** Today renders
- **THEN** the First today card MUST NOT show

### Requirement: REQ-STD-003 Today links no page a role could not already reach

Every page Today links for a role MUST be a page the full menu puts within one step for that role.

#### Scenario: The links follow the role
@e2e exclude A comparison per role; asserted in tests/unit-js/structureProfile.test.mjs.
- **GIVEN** one of the four roles
- **WHEN** the links on Today are read
- **THEN** each MUST open a page the full menu offers that role, in the menu or one step from it

### Requirement: REQ-STD-004 Today is laid out in two columns as the board draws it

Under the attention card, Today MUST show a main column with "Mijn lessen vandaag" and "Signalen in mijn groepen", and a side column with the six counters in two columns, then "Nakijken" and "Berichten van ouders", so no label is cut. The lessons list MUST read sessions that start today and open a lesson and the Lessons today list; the flags list MUST read open flags and open a flag and the Attendance flags list with the same filter. Both lists MUST be library widgets. Other dashboards MUST follow below at full width.

#### Scenario: The two lists open what they show
@e2e exclude A reading of the two widgets against the pages, asserted in structureProfile.test.mjs.
- **GIVEN** the Today dashboard
- **WHEN** its lists are read
- **THEN** each row opens a detail page of the schema the list reads
- **AND** "View all" opens an index page of that schema with the same filter

#### Scenario: The columns hold what the board draws
@e2e exclude A reading of the layout, asserted in structureProfile.test.mjs.
- **GIVEN** the Today dashboard
- **WHEN** its layout is read
- **THEN** the lessons and the signals sit at the left
- **AND** the six counters sit at the right in two columns, with "Nakijken" and "Berichten van ouders" under them

