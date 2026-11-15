# competency Specification

## ADDED Requirements

### Requirement: A Competency declares the years it is taught in

`Competency` MUST declare an optional `applicableYears` property: an array of string labels (`uniqueItems:
true`, each item `minLength: 1` and `maxLength: 64`), default `[]`, with no enum. A label is either a year level
(canonical spelling `groep 1` to `groep 8`, `leerjaar 1` to `leerjaar 6`, `jaar 1` to `jaar 4`) or an academic
year in the `Cohort.academicYear` format (`YYYY` or `YYYY-YYYY`). An empty array MUST mean the goal applies to
every year of its framework, so every `Competency` stored before this change stays valid and in scope for every
year. The property is additive: `frameworkId`, `parentId`, `code`, `title`, `description`, `order`,
`requiredForRoles` and `lifecycle` keep their names, types and meaning.

#### Scenario: A kerndoel is allocated to two year levels

<!-- @e2e exclude Pure register shape with no learniq DOM surface of its own; the existing Competency data widget and form render every schema property. Covered by CompetencyYearScopeRegisterTest::testApplicableYearsIsAnOptionalFreeLabelArray. -->

- **GIVEN** a `CompetencyFramework` with `sourceAuthority: slo-kerndoelen`
- **WHEN** a curriculum designer saves a leaf `Competency` under it with `applicableYears: ["groep 5", "groep 6"]`
- **THEN** the object persists with both labels
- **AND** a second save with `applicableYears: ["groep 5", "groep 5"]` is rejected by the `uniqueItems` constraint

#### Scenario: A Competency stored before this change applies to every year

<!-- @e2e exclude Back-compat default on a register property; no DOM surface. Covered by CompetencyYearScopeRegisterTest::testNewPropertiesAreAdditiveAndOptional. -->

- **GIVEN** a `Competency` row created before this change, with no `applicableYears`
- **WHEN** it is read
- **THEN** `applicableYears` resolves to `[]`, meaning the goal is in scope for every year of its framework
- **AND** `required` on `Competency` is unchanged (`frameworkId`, `code`, `title`, `tenant_id`)

### Requirement: A Competency declares the subject it belongs to

`Competency` MUST declare an optional `subjectId` property: a nullable string with `format: uuid` and `$ref:
Course`, default `null`. learniq models a subject as a `Course` row, the same reference
`SubjectTeacherAssignment.courseId` uses, so `subjectId` MUST NOT reference any other schema. A null value MUST
mean the goal is cross-subject or not yet linked.

#### Scenario: A rekenen-wiskunde goal is linked to the school's rekenen course

<!-- @e2e exclude Pure register shape; the relation renders through the existing Related panel on CompetencyDetail. Covered by CompetencyYearScopeRegisterTest::testSubjectIdReferencesCourse. -->

- **GIVEN** a `Course` row named "Rekenen-wiskunde" standing for the subject
- **WHEN** a curriculum designer sets a `Competency`'s `subjectId` to that course's UUID
- **THEN** the object persists with the reference
- **AND** the `Competency` detail page resolves `subjectId` to the course through its Related panel

### Requirement: Readers resolve an empty year or subject from the nearest ancestor

Any reader that groups `Competency` rows by year or subject (the coverage rollup in learniq, and the SLO
importer in integriq when it decides where to set a value) MUST resolve an effective value per node: the
node's own non-empty `applicableYears`, else the nearest ancestor's (via `parentId`) non-empty
`applicableYears`, else `[]`; and the node's own non-null `subjectId`, else the nearest ancestor's non-null
`subjectId`, else `null`. Labels MUST be compared after trimming whitespace and lower-casing. The effective
value MUST NOT be written back onto the node: it is a read rule, so editing a domain node's value changes every
descendant that has no value of its own.

#### Scenario: Years set on a domain node apply to its kerndoelen

<!-- @e2e exclude Read rule for a server-side consumer that ships in curriculum-coverage-rollup; no DOM surface in this change. Pinned by the spec text and by CompetencyYearScopeRegisterTest::testDescriptionsStateTheInheritanceRule. -->

- **GIVEN** a domain `Competency` with `applicableYears: ["groep 7", "groep 8"]` and `subjectId` set to the
  rekenen course
- **AND** two child kerndoelen with `applicableYears: []` and `subjectId: null`
- **WHEN** a reader resolves the effective year and subject of each child
- **THEN** both children resolve to `groep 7` and `groep 8` and to the rekenen course
- **AND** neither child row is modified

#### Scenario: A child's own value wins over its parent's

<!-- @e2e exclude Same read rule as above; no DOM surface in this change. -->

- **GIVEN** a domain `Competency` with `applicableYears: ["groep 7", "groep 8"]`
- **AND** a child kerndoel with `applicableYears: ["groep 8"]`
- **WHEN** a reader resolves the child's effective years
- **THEN** it resolves to `groep 8` only
