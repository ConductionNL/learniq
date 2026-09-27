---
kind: config
depends_on:
  - segment-wizard-choice
  - segment-example-datasets-corporate
  - segment-example-datasets-training
---

# Proposal: example-set-regulation-rows

## Summary
The company and training example sets now ship the regulations their courses, credentials and attestations point at: VCA, NEN 3140, BHV, F-gassen, the forklift certificate, NIS2, the code of conduct and information security for the company; VCA, the Arbowet BHV and prevention rules, the forklift rule and NIS2 for the training institute. To make that possible, the example set contract takes the envelope slug from the object's own `slug` for a schema that declares its own slug pattern, as Regulation does (`^[A-Z0-9_-]+$`).

## Motivation
Decision D29 (Ruben, 2026-09-27, `_round1/compare/decisions.md:33`): "The example set contract takes the slug from the object for schemas with their own slug pattern, so Regulation rows join the company and training sets."

Both sets left Regulation out because the contract demanded the slug `<id>-<schema>-<NNN>` for every object, while Regulation's own `slug` property is the regulation code and must match `^[A-Z0-9_-]+$` (`scripts/example-sets/training.py`, "WHAT IS LEFT OUT"). The two keys are one key: OpenRegister stores the object's `slug` as its identifier, and the register's own AVG seed row already carries `@self.slug: "AVG"`. Without the rows, 1,615 company objects and 897 training objects name a regulation that does not exist in the example data, the Compliance overview has nothing to roll up (it counts only published regulations), and "assign this regulation" has nothing to assign.

## Affected Projects
- [x] Project: `learniq`: the segment-wizard-choice contract and `ExampleSetDescriptorContractTest`; `scripts/example-sets/corporate.py` and `training.py`; the regenerated `lib/Settings/profiles/corporate.json` and `training.json`; `CorporateExampleSetTest` and `TrainingExampleSetTest`.

## Scope

### In Scope
- Contract amendment: for a schema whose `slug` property declares a `pattern`, the object's `slug` is the regulation-style identifier; it must match that pattern and be unique in the file, and the `<id>-` prefix rule does not apply. The fixed `uuid` rule still applies.
- A new contract rule: a set must not re-ship an own-slug row the register already seeds (the register seeds `AVG`), because a second row with the same identifier is a duplicate.
- Regulation rows, published and active, with audiences that match who the generators actually train: 8 in the company set, 5 in the training set. AVG is left out of both, because the register seeds it.
- `profile.objectCount` and `info.version` updated; the set tests read the regulation audiences instead of hardcoding them.

### Out of Scope
- Publishing the register's own AVG seed row (it has no lifecycle, so the Compliance overview ignores it). That touches `learniq_register.json`, which lane r3-access is editing; named as an open question.
- Regulation rows in the school sets: no school course carries a `regulationSlug`.
- A `bhv` role on LearnerProfile: the BHV obligation applies to designated people, which the audience model cannot express yet (see design).

## Approach
- The contract test learns `ownsSlug(schema)`: true when `properties.slug.pattern` is set. The existing value check already enforces the pattern.
- Each generator appends `regulation` to the end of its `SCHEMAS` list, so no existing uuid moves (the uuid encodes the schema's position), and sets the object's `slug` to the regulation code.
- Regenerate both files; the `--check` mode proves the generators and the files agree.

## New Dependencies
None.

## Impact
- `openspec/changes/segment-wizard-choice/contract.md` (the contract says a rule change lands in the contract and the test in the same PR).
- `tests/Unit/Settings/ExampleSetDescriptorContractTest.php`, `CorporateExampleSetTest.php`, `TrainingExampleSetTest.php`.
- `scripts/example-sets/corporate.py`, `training.py`; `lib/Settings/profiles/corporate.json` (5,741 to 5,749 objects), `training.json` (3,831 to 3,836).

## Cross-Project Dependencies
None.

## Risks

### Risk 1: loading both the company and the training set duplicates VCA and NIS2
**Severity:** Low. **Mitigation:** each set must stand alone, so both carry those two rows; the wizard loads one set. Named in the design and the PR.

### Risk 2: an install that loaded a set before this change
**Severity:** Low. **Mitigation:** the import is idempotent by uuid; a re-load adds exactly the new Regulation rows and nothing else, because no existing uuid changed.

## Rollback Strategy
Revert the PR. Installs that loaded the new rows keep them until the set is removed.

## Open Questions
- Should the register's AVG seed row be published (and carry the example tenant), so AVG counts in the Compliance overview?
