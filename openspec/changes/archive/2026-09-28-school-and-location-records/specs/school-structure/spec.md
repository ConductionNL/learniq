# School Structure — Programmes, Curriculum Plans, Cohorts, Sessions

## ADDED Requirements

### Requirement: School and Location are persisted as OpenRegister records
The system MUST persist `School` (BRIN, name, pedagogical concept) and `Location` (vestigingscode, onderwijslocatiecode, address, `schoolId` reference) as OpenRegister objects, each a plain resource-metadata schema with no lifecycle. The `Location` schema's internal key/slug is `Vestiging` (`location` is already claimed by `shillinq` on the shared OpenRegister; see design.md Decision 4); this requirement uses "Location" throughout for the user-facing concept, matching the page title and menu label. — the same shape as `Room` (see "Room is persisted as a bookable resource"). No bestuur/board entity is introduced this round (decision D2); `School` is the top-level record.

#### Scenario: A school and its locations are recorded
- **GIVEN** the `School` and `Location` schemas are registered
- **WHEN** an administrator creates a `School` with a BRIN and a `Location` referencing it via `schoolId`
- **THEN** both persist as OpenRegister objects and the `Location` resolves back to its `School`

### Requirement: School declares a BRIN and a pedagogical concept
`School.brin` MUST be a pattern-validated DUO BRIN-nummer (4 characters: two digits followed by two alphanumeric characters). `School.pedagogicalConcept` MUST be one of `regular`, `montessori`, `dalton`, `jenaplan`, `freinet`, `vrijeschool`, `other`, defaulting to `regular`.

#### Scenario: A montessori school records its pedagogical concept
- **GIVEN** a `School` being created for a montessori primary school
- **WHEN** `pedagogicalConcept` is set to `montessori`
- **THEN** the value persists and later school-year/reporting changes can read it to select the montessori-shaped reporting profile

#### Scenario: An out-of-pattern BRIN is rejected
- **GIVEN** a `School` being created
- **WHEN** `brin` is submitted as a value that does not match the two-digit-plus-two-alphanumeric pattern
- **THEN** OpenRegister's schema validation rejects the write

### Requirement: Location declares vestigingscode and an independent onderwijslocatiecode
`Location.vestigingscode` MUST be a required string identifying the DUO/RIO vestiging. `Location.onderwijslocatiecode` MUST be an independent, nullable string — a vestiging MAY have more than one onderwijslocatie (`legal-po-2026-09-25.md`: "BRIN, vestigingscode, onderwijslocatie" are three distinct codes, not one).

#### Scenario: A vestiging with a separate onderwijslocatie is recorded
- **GIVEN** a `Location` with `vestigingscode` "02VG00"
- **WHEN** `onderwijslocatiecode` is set to a different RIO onderwijslocatie code for a satellite building
- **THEN** both codes persist independently on the same `Location` object

#### Scenario: A location without a separate onderwijslocatie is unaffected
- **GIVEN** a `Location` with no `onderwijslocatiecode` set
- **WHEN** it is read
- **THEN** `onderwijslocatiecode` resolves to `null` and the location is otherwise complete with just its `vestigingscode`

### Requirement: Cohort names the one Location it runs at
`Cohort.locationId` MUST be an additive, nullable `$ref Location` field. This is the enforcement point for the DUO rule that a groep belongs to exactly one location (`legal-po-2026-09-25.md`: "one groep per location"): a `Cohort` object has at most one `locationId`, never an array.

#### Scenario: A groep is assigned to its location
- **GIVEN** a `Cohort` representing a PO groep
- **WHEN** `locationId` is set to a `Location` object's UUID
- **THEN** the cohort resolves to exactly that one location, never more than one

#### Scenario: A pre-existing Cohort without a location is unaffected
- **GIVEN** a pre-existing `Cohort` row with no `locationId` set
- **WHEN** it is read
- **THEN** `locationId` resolves to `null` and the cohort's existing `programmeId`/`courseId`/`teacherIds`/`learnerIds` fields and lifecycle are unchanged

### Requirement: Frontend is declarative for School and Location
`School` and `Location` MUST render as manifest-declared index+detail page pairs under the existing People domain (`src/manifest.d/people.json`), matching the Enrolment/Credential convention: a data widget, a related widget, and an audit-history sidebar tab. No custom Vue view and no PHP CRUD controller.

#### Scenario: A coordinator opens a School's detail page
- **GIVEN** the School/Location pages are configured
- **WHEN** a coordinator navigates to People → Schools and opens a School
- **THEN** the detail page renders from the manifest (data + related widgets, audit tab), with no bespoke Vue component
