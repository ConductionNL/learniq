---
kind: code
depends_on:
  - segment-runtime-bridge
---

# Proposal: segment-wizard-choice

## Summary
The setup wizard asks "What kind of organisation is this?" with six cards (primary school, secondary school, MBO, HBO/WO, company, training institute) and writes the answer to `LearniqSettings.segment`. Its example data step becomes `example-set` with `optionsSource: profiles`: one card per example set shipped under `lib/Settings/profiles/`, next to the existing generated set. A new `SeedProfileService` lists and loads those sets, mirroring decidesk's `seed-profiles` change, and a new `occ learniq:example-set:remove` removes one through OpenRegister's own purge command. This change fixes the profile descriptor contract (file name per segment, required keys, fixed fictional uuids, how a set is selected, loaded and removed) that six data set lanes will each add one file to. It ships no set itself; the primary school set is the next change.

## Motivation
Decision D21 (Ruben, 2026-09-27): six example sets at once, one lane per set, chosen in the setup wizard; the segment is single-select. Today the wizard offers exactly one dataset (`DemoDataService::listChoices()` returns `none` and `demo`), a generated one that mixes primary school, MBO (BPV), higher education (BSA) and corporate schemas with no separation (recon A, section 1). The segment value that `segment-runtime-bridge` now publishes at runtime has no way to be set during setup.

Recon A section 1 names the working precedent: decidesk moved 334 always-imported seeds into four opt-in example sets (`decidesk/openspec/changes/seed-profiles/proposal.md`, `lib/Service/SeedProfileService.php`, `lib/Settings/profiles/*.json`, `src/manifest.json` step `example-set`). Recon A open question 2 recommends single-select for learniq (a school is normally one kind); D21 confirms it.

Competitor evidence (recon A section 2): Moodle ships one canned demo school ("Mount Orange School"), not a picker per organisation kind; neither the round 1 corpus nor recon A's web check found a competitor offering segment-specific example data. The precedent is internal to the fleet. Round 1 finding 14.6 (`_round1/compare/findings.md:51`, rung 1) covers the segment flag half: Canvas and Moodle both configure features per account or site.

## Affected Projects
- [x] Project: `learniq`: new `SeedProfileService`; `SegmentService` gains a writer and the choice list; `SetupController` serves `profiles` and `segments`, stores both answers and loads a set; new occ command `learniq:example-set:remove` (delegates to `openregister:objects:purge`); `src/manifest.json` setup steps; `tests/e2e/ci-seed.sh` settles the new step; the demo-data e2e spec follows the new contract.

## Scope

### In Scope
- A `lib/Settings/profiles/` directory and the profile descriptor contract, written down in design.md and enforced by a contract test that every future profile file must pass.
- `SeedProfileService`: `listProfiles()`, `listChoices()`, `isKnown()`, `install()`, `uuidsFor()`. Same shape and ids as decidesk's service; `uuidsFor()` is new and returns exactly the fixed uuids a descriptor declares, in reverse load order.
- Wizard steps: `welcome` → `example-set` (single-select cards) → `load-example-set` → `segment` (six cards, pre-selected from the example set picked) → `done`. `setup.version` 2, so an existing install is asked the new question once.
- `SegmentService::listChoices()` (the six cards), `hasSegment()`, `setSegment()`; `SetupController` stores `segment` through it.
- Legacy ids keep working: `demo_dataset`, `install-demo-data`, `load-demo-data`, `skip-demo-data`.
- `occ learniq:example-set:remove <id> [--apply]`, which hands the set's uuids to `occ openregister:objects:purge --force`, so "you can remove it again" is true. Dry run by default, like the purge command.

### Out of Scope
- The six data sets themselves: `segment-example-datasets-po` (this lane, next) and one sibling lane per other segment.
- Menu gating on the segment: `segment-menu-gating`, the fourth change in this lane.
- Removal over HTTP. Three schemas in a school set are archival (`LearnerProfile`, `AttendanceRecord`, `DossierNote`); OpenRegister refuses every HTTP delete on them and keeps the shell (`openregister:objects:purge --force`) as the one deliberate exit for fixtures and mistakes. Removal therefore stays an occ command.
- OpenRegister's import-batch purge (`demo-data-purge-by-batch`, wave 2, recon A section 4). Fixed uuids make an exact removal possible without it.

## Approach
Copy decidesk's `SeedProfileService` and two-step choice then run-action wizard pattern, with three learniq differences, each explained in design.md: single-select, a `segment` step after the load step (gate 100 requires the offer at step 2), and a removal path through OpenRegister's purge command. The segment step uses the wizard's existing `suggestFrom` and `suggestMap` so picking the primary school set pre-selects "Primary school".

## New Dependencies
None.

## Impact
- PHP: new `SeedProfileService`, new `ExampleSetRemoveCommand`, changed `SetupController`, `SegmentService`, `DemoDataService` (label only); `appinfo/info.xml` registers the command.
- Manifest: `setup` block (steps, version 2).
- Tests: new `SeedProfileServiceTest`, `ExampleSetRemoveCommandTest`, `ExampleSetDescriptorContractTest`, updated `SetupControllerTest`, `SegmentServiceTest`, `DemoDataServiceTest`; e2e `ci-seed.sh` and `demo-data-setup-step.spec.ts`.
- l10n: wizard copy and card labels in en and nl.

## Cross-Project Dependencies
None at build time. Five sibling data set lanes (vo, mbo, he, corporate, training) consume the descriptor contract this change defines.

## Risks

### Risk 1: a sibling lane writes a descriptor that does not load
**Severity:** High. **Mitigation:** `ExampleSetDescriptorContractTest` checks every `lib/Settings/profiles/*.json`: required keys, id equals file name and a segment code, every object on a learniq schema with the right `@self`, fixed uuids in the segment's namespace and unique, every uuid reference resolving inside the set, the declared `objectCount` true, and each object valid against its schema's required fields, enums and formats.

### Risk 2: an existing install sees the wizard again
**Severity:** Medium. **Mitigation:** intended once: `setup.version` 2 re-offers the wizard so an existing admin can answer the new question. It stays dismissible, and a segment already set on the settings page marks the step done.

### Risk 3: removal leaves a record a user added
**Severity:** Low. **Mitigation:** removal purges only the uuids the descriptor declares. A record a user created against an example pupil stays; design.md says so.

### Risk 4: purging archival rows
**Severity:** Low. **Mitigation:** the rows are fixtures with fixed uuids in an example namespace, never a school's records. The command only ever passes uuids from the descriptor, and it is dry-run unless `--apply` is given, exactly like OpenRegister's purge.

## Rollback Strategy
Revert the PR. The wizard returns to the single `demo-data` step. Stored `example_profile` and `segment` answers are harmless leftovers; a loaded example set can be removed with the command before reverting.

## Open Questions
None. D21 settles six sets and single-select.
