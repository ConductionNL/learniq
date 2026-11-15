# Tasks: goal-alignment-depth

## Implementation Tasks

### Task 1: Declare competencyAlignments on the four schemas
- **spec_ref**: `openspec/changes/goal-alignment-depth/specs/competency/spec.md#requirement-lessons-courses-assignments-and-assessments-align-to-goals-with-a-depth`
- **files**: `lib/Settings/learniq_register.json` (`Lesson`, `Course`, `Assignment`, `Assessment`: `competencyAlignments`; versions; `info.version`), `lib/Settings/learniq_mock_register.json`, `l10n/en.json`, `l10n/nl.json`, `l10n/*.js`
- **tier**: must (MVP)
- **acceptance_criteria**:
  - GIVEN each of the four schemas WHEN read THEN `competencyAlignments` is an array of `{competencyId (required, uuid, $ref Competency), depth (nullable, maxLength 64)}`, default `[]`, `widget: json`, no depth enum
  - GIVEN each of the four schemas WHEN read THEN `competencyIds` is unchanged
  - GIVEN the gate-101 checker and `npm run check:schema-l10n` WHEN run THEN both exit 0
- [x] Implement
- [x] Test

### Task 2: CompetencyAlignmentNormaliser
- **spec_ref**: `#requirement-competencyids-stays-derived-from-the-alignments`, `#requirement-readers-treat-a-flat-only-row-as-alignments-without-depth`, `#requirement-a-depth-the-goals-framework-does-not-know-is-refused`
- **files**: `lib/Service/CompetencyAlignmentNormaliser.php`, `tests/Unit/Service/CompetencyAlignmentNormaliserTest.php`
- **tier**: must (MVP)
- **acceptance_criteria**:
  - GIVEN alignments WHEN ids are derived THEN order is kept and duplicates dropped
  - GIVEN alignments and a new flat list WHEN alignments follow THEN kept goals keep depth, new goals get null, removed goals drop out
  - GIVEN a row with no alignments WHEN effective alignments are read THEN the flat list maps to depth null; a depth unknown to the framework reads as null
  - GIVEN an unknown goal, a duplicate goal or an unknown depth WHEN checked THEN a message naming the goal's code (and the allowed levels for a depth) is returned
- [x] Implement
- [x] Test

### Task 3: CompetencyAlignmentListener and its registration
- **spec_ref**: `#requirement-competencyids-stays-derived-from-the-alignments`, `#requirement-a-depth-the-goals-framework-does-not-know-is-refused`
- **files**: `lib/Listener/CompetencyAlignmentListener.php`, `lib/AppInfo/Registrar/IntegrityListenerRegistrar.php`, `tests/Unit/Listener/CompetencyAlignmentListenerTest.php`
- **tier**: must (MVP)
- **acceptance_criteria**:
  - GIVEN a create or update of one of the four schemas WHEN alignments change THEN `competencyIds` is set through `setModifiedData`
  - GIVEN an update that changes only `competencyIds` on a row with alignments WHEN handled THEN `competencyAlignments` follows
  - GIVEN a bad alignment WHEN handled THEN the event carries errors and propagation is stopped
  - GIVEN another schema, another register or an unresolvable schema WHEN handled THEN nothing is written or refused
- [x] Implement
- [x] Test

### Task 4: Register unit test
- **spec_ref**: `#requirement-lessons-courses-assignments-and-assessments-align-to-goals-with-a-depth`
- **files**: `tests/Unit/Settings/GoalAlignmentDepthRegisterTest.php`
- **tier**: must (MVP)
- **acceptance_criteria**:
  - GIVEN the register WHEN read THEN the four shapes, versions and the demo rows match design.md
- [x] Implement
- [x] Test

## Verification
- `openspec validate goal-alignment-depth --strict` passes
- The three new test classes pass; `php -l`, phpcs, phpstan on touched `lib/` files clean
- `composer check:strict` once before push

## Quality checklist
- PHPUnit covers the normaliser and the listener, each with happy path, refusal and edge cases (ADR-009).
- Newman: N/A, no new endpoint.
- Playwright: N/A, no new page; the property renders through the existing forms.
- Documentation (ADR-010): the curriculum coverage docs land in `curriculum-coverage-matrix-view`.
- i18n (ADR-005): English and Dutch values for every new schema string.
