# Design: competency-year-scope

## Architecture Overview
`Competency` is a recursive taxonomy node under a `CompetencyFramework` (register `learniq`, slug `competency`).
This change adds two optional properties to it and nothing else. OpenRegister imports the register JSON through
the existing repair step, the declarative `Competencies` index and `CompetencyDetail` pages render every property
through the data widget and the create and edit forms, and the Related panel resolves the new `$ref`.

The four round 2 curriculum changes build on each other:

| Order | Change | What it adds |
|---|---|---|
| 1 | `competency-year-scope` (this one) | year and subject on the goal |
| 2 | `goal-alignment-depth` | a depth per lesson, course, assignment and assessment link |
| 3 | `curriculum-coverage-rollup` | derived planned and assessed coverage per framework, year and subject |
| 4 | `curriculum-coverage-matrix-view` | the matrix and gap list |

## Field contract (published to lane r2-slo)

This is the exact shape. `/home/rubenlinde/memcap-work/lq-lanes/CONTRACT-competency-fields.md` carries the same
text.

| Property | JSON type | Required | Default | Constraint | `$ref` |
|---|---|---|---|---|---|
| `applicableYears` | `array` of `string` | no | `[]` | `uniqueItems: true`; items `minLength: 1`, `maxLength: 64` | none |
| `subjectId` | `string`, nullable | no | `null` | `format: uuid` | `Course` |

**Enum values: none.** Neither property declares an enum. The only enums a Competency importer meets are the
existing, unchanged ones: `CompetencyFramework.sourceAuthority` (`sbb-kwalificatiedossier`, `slo-kerndoelen`,
`slo-eindtermen`, `esco`, `school-defined`, `other`), `CompetencyFramework.level` (`po`, `vo`, `mbo`, `hbo`, `wo`,
`corporate`), `Competency.requiredForRoles` items and `Competency.lifecycle` (`draft`, `published`, `archived`).

### `applicableYears` labels
- One scheme per framework, chosen by the school:
  - year levels: `groep 1` to `groep 8` (primary), `leerjaar 1` to `leerjaar 6` (secondary and MBO), `jaar 1` to
    `jaar 4` (HBO and WO); or
  - academic years in the `Cohort.academicYear` format: `YYYY` or `YYYY-YYYY`, e.g. `2026-2027`.
- Canonical spelling: lower case, one space, arabic numeral. Readers compare after trim and lower-casing, so
  `Groep 5` and `groep 5` fall in one bucket, but writers use the canonical form.
- `[]` means every year of the framework.

### `subjectId`
- A `Course` UUID. learniq has no Subject schema: PR 929 (`subject-and-teacher-assignment`) added
  `SubjectTeacherAssignment` and `Staff`, and both it and `SubjectChoice` treat a `Course` as the subject.
- `null` means cross-subject or not yet linked.

### Read rule
A node with an empty `applicableYears` inherits the nearest ancestor's non-empty value; a node with a null
`subjectId` inherits the nearest ancestor's non-null value. The effective value is computed by readers and never
written back, so an importer may set either property on a domain node only.

## Decisions

### D1: Free labels, not an enum or a SchoolYear reference
Year levels differ per sector (groep, leerjaar, jaar, and a training institute has none), and learniq already
stores years as free strings (`Cohort.academicYear`, `SubjectChoice.academicYear`, `ReportPeriod.academicYear`).
An enum would fit primary school and fail everyone else. A reference to `ReportPeriod` would tie a national goal
to one calendar term, which is the wrong grain.
Alternatives considered: an integer `leerjaar` like `Enrolment.leerjaar` (rejected: 1 to 8 cannot say "groep 5"
versus "leerjaar 5", and HBO has no leerjaar); two separate properties for year level and academic year
(rejected: a school plans with one scheme, and the rollup only needs an opaque label to group by).

### D2: `subjectId` points at `Course`
learniq models a subject as a `Course`. Pointing at `Course` matches `SubjectTeacherAssignment.courseId` and
lets the rollup reuse the courses the school already has. The property is named `subjectId`, not `courseId`,
because `Course` already has its own meaning on `Lesson.courseId` and friends, and because a later Subject schema
can take over the reference without renaming the property.
Alternative considered: a free-text subject label like `GroupPlan.subject` (rejected: a label cannot be joined to
the lessons that teach it, and the coverage rollup needs that join).

### D3: Inheritance is a read rule, not a stored copy
Setting years on a domain node and letting kerndoelen inherit keeps an import small and an edit in one place.
Stamping the value onto every descendant would need a listener and would go stale when a node moves.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Store the years and subject of a goal | Declarative: two properties in `learniq_register.json` | Plain data, no behaviour |
| Resolve the effective value from ancestors | Deferred to `curriculum-coverage-rollup` | Only a reader needs it; this change adds no reader |

No new service, listener, controller or Vue component.

## API Design
No new endpoint. The existing OpenRegister object API accepts the two properties; see `contract.md`.

## Nextcloud Integration
- Controllers: none.
- Services: none.
- Mappers/Entities: none (OpenRegister stores the object).
- Events/Hooks: none.

## Security Considerations
No security impact. `Competency` keeps its current access rules. Both properties are curriculum metadata, not
personal data about a pupil.

## File Structure
```
lib/Settings/learniq_register.json        Competency: two properties, version 0.1.0 -> 0.2.0; info.version bump
lib/Settings/learniq_mock_register.json   three Competency demo rows carry the two fields
l10n/en.json, l10n/nl.json, l10n/*.js      keys for two titles and two descriptions
tests/Unit/Settings/CompetencyYearScopeRegisterTest.php   new
```

## Seed Data
`Competency` ships no `x-openregister-seed` rows today; its demo rows live in `learniq_mock_register.json`. The
three existing demo rows gain the two fields so the demo shows each state:

### Schema: `competency`
| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | `competency-voorbeeld-title-1-1` | `competency-voorbeeld-title-2-2` | `competency-voorbeeld-title-3-3` |
| `applicableYears` | `["groep 5", "groep 6"]` | `["2026-2027"]` | `[]` (every year) |
| `subjectId` | `00000000-0000-4000-8000-000000000000` | `null` | `00000000-0000-4000-8000-000000000002` |

Object 1 uses year levels, object 2 an academic year, object 3 the framework-wide default. The UUIDs follow the
existing placeholder pattern in the demo file.

**Related items per object:** none; the demo rows are schema-shaped placeholders.

## Trade-offs
Free labels trade validation for reach: a typo such as `groep5` creates its own column in the matrix. The
canonical spelling in this document and the lower-cased comparison limit the damage, and the matrix view (change
4) shows every distinct label, so a stray one is visible rather than hidden.
