# Design: goal-alignment-depth

## Architecture Overview
Four schemas that already carry `competencyIds` (`Lesson`, `Course`, `Assignment`, `Assessment`) gain a structured
`competencyAlignments` list. A pre-save listener keeps the flat list derived from it and refuses a depth the goal's
framework does not know. Every current reader of `competencyIds` keeps working unchanged:

| Reader | Reads | Effect of this change |
|---|---|---|
| `GradeEvidenceRollup` / `CompetencyAttainmentRollupHandler` | `Assignment.competencyIds`, `Assessment.competencyIds` | none, the list stays filled |
| `SkillsGapDashboard.vue`, manifest filters | `competencyIds` | none |
| `curriculum-coverage-rollup` (change 3) | effective alignments (read rule below) | new reader |

```
save Lesson/Course/Assignment/Assessment
   -> OpenRegister ObjectCreatingEvent / ObjectUpdatingEvent (pre-save)
      -> CompetencyAlignmentListener
           resolve schema slug (ListenerSchemaResolver::guardSchemaSlug), skip if not one of the four
           decide: alignments changed? flat list changed? neither?
           alignments changed -> resolve each goal + its framework levels (ObjectService, _rbac false)
                               -> CompetencyAlignmentNormaliser::problem() -> refuse (setErrors + stopPropagation)
                               -> setModifiedData(competencyIds = ids of alignments)
           only flat list changed on a row with alignments
                               -> setModifiedData(competencyAlignments = alignments following the ids)
```

## Property shape (all four schemas, identical)

```json
"competencyAlignments": {
  "type": "array",
  "default": [],
  "widget": "json",
  "items": {
    "type": "object",
    "required": ["competencyId"],
    "properties": {
      "competencyId": { "type": "string", "format": "uuid", "$ref": "Competency" },
      "depth": { "type": "string", "nullable": true, "maxLength": 64 }
    }
  }
}
```

`depth` has no enum: its valid values are the `levelId`s of the aligned goal's
`CompetencyFramework.proficiencyLevels` (A4). The listener, not JSON Schema, enforces that, because the allowed set
depends on another object.

## Decisions

### D1: A pre-save listener, not a calculation
`x-openregister-calculations` cannot map a nested array to a flat list, and cannot look up another object's scale.
A pre-save listener can do both inside the same write, so `competencyIds` is never stale on disk.
Alternatives considered: making `competencyIds` a materialised calculation (rejected: not expressible, and it would
break writers that set `competencyIds` directly, such as imports); a post-save listener that writes a second time
(rejected: a second write fires every other listener again and leaves a window where the lists disagree).

### D2: Two-way sync with the alignments winning
Both properties stay writable, because existing forms, imports and API clients write `competencyIds`. When only the
flat list changes on a row with alignments, the alignments follow instead of silently reverting the edit. When both
change in one save, the alignments win, because they carry more information.

### D3: Refuse, do not repair, a bad depth
Silently dropping or nulling a depth would hide a teacher's mistake. The refusal names the goal's code and the
allowed levels. A stored depth that later stops matching (the framework's levels were edited) is read as `null` by
readers, so an old row never blocks and never disappears from coverage.

### D4: A pure normaliser behind the listener
`CompetencyAlignmentNormaliser` holds the list logic (ids from alignments, alignments following ids, the problem
check, the effective-alignments read rule) with no I/O, so it is unit-tested without mocks and reused by the
coverage rollup in change 3.

### D5: JSON editor in the form
The shared form renders an array of objects as a tag input, which cannot hold objects. `widget: json` makes it a JSON
editor, which works today. A depth picker that lists the framework's levels is a follow-up, not part of this change.

## Mixed-spec rationale (ADR-032)
The register properties and the listener are one contract: a `competencyAlignments` property without the listener
would let `competencyIds` drift from it on the first save, which breaks the attainment rollup. Splitting them would
ship a broken intermediate state, so this change is declared `kind: code` with the property declarations as its
data, following `po-schooladvies-flow`.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Store alignments | Declarative, register properties | Plain data |
| Derive `competencyIds` from alignments | Imperative, pre-save listener | Nested-to-flat mapping is not expressible as a calculation |
| Refuse an unknown depth | Imperative, pre-save listener (ADR-031 exception: guard) | The allowed set lives on another object |
| Resolve effective alignments for readers | Pure service method, used by change 3 | Read rule, no storage |

## Security Considerations
The listener reads `Competency` and `CompetencyFramework` with `_rbac: false` to validate a depth, and returns only
the goal's code and level ids in the refusal. Both are curriculum metadata, readable by every authenticated user
under the current authorization blocks. It never widens who may write the four schemas: their `authorization`
blocks are unchanged. It skips objects outside the `learniq` register and never fails another app's write.

## Nextcloud Integration
- Controllers: none.
- Services: `CompetencyAlignmentNormaliser` (new, pure); `ObjectService` (OpenRegister) for lookups.
- Mappers/Entities: none.
- Events/Hooks: `OCA\OpenRegister\Event\ObjectCreatingEvent`, `ObjectUpdatingEvent`, registered directly in
  `IntegrityListenerRegistrar` (not through `ObjectEventSubscription`, whose shared proxy does not consult
  `isPropagationStopped()` between subscriptions).

## File Structure
```
lib/Listener/CompetencyAlignmentListener.php             new
lib/Service/CompetencyAlignmentNormaliser.php            new
lib/AppInfo/Registrar/IntegrityListenerRegistrar.php     two registrations
lib/Settings/learniq_register.json                       four properties, four schema versions, info.version
lib/Settings/learniq_mock_register.json                  demo rows
l10n/en.json, l10n/nl.json, l10n/*.js                     keys
tests/Unit/Listener/CompetencyAlignmentListenerTest.php  new
tests/Unit/Service/CompetencyAlignmentNormaliserTest.php new
tests/Unit/Settings/GoalAlignmentDepthRegisterTest.php   new
```

## Seed Data
The four schemas keep their existing demo rows in `learniq_mock_register.json`; one demo row per schema gains an
alignment list, so the demo shows both a depth and an open depth.

### Schema: `lesson`, `course`, `assignment`, `assessment` (first demo row of each)
| Field | Value |
|-------|-------|
| `competencyAlignments` | `[{"competencyId": "00000000-0000-4000-8000-000000000000", "depth": "Voorbeeld Levelid 1"}, {"competencyId": "00000000-0000-4000-8000-000000000001", "depth": null}]` |
| `competencyIds` | `["00000000-0000-4000-8000-000000000000", "00000000-0000-4000-8000-000000000001"]` (derived) |

The depth `Voorbeeld Levelid 1` is the `levelId` of the first demo `CompetencyFramework`, so the demo row is
consistent with the listener's rule. The goal UUIDs follow the placeholder pattern already used by the demo file.

**Related items per object:** none.

## Trade-offs
A listener costs one `Competency` and one `CompetencyFramework` lookup per alignment on the saves that change
alignments (frameworks are cached per request). Saves that do not touch alignments, including every lifecycle
transition, cost only the slug check.
