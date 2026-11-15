---
kind: code
depends_on:
  - segment-wizard-choice
---

# Proposal: example-set-removal-in-wizard

## Summary
The setup wizard gets a last step, "Remove the example data". It asks OpenRegister to soft-delete exactly the objects the loaded example set created, through `ConfigurationService::softDeleteAppImports('learniq.profile.<id>')` (openregister PR 4080). On an OpenRegister without that method the step says which `occ` command does the same. The step only runs when the admin clicks it. The wizard's segment answer is now written only for a user in `admin` or `administration-managers`, the groups the lane brief assigns to the segment.

## Motivation
- The lane brief (r3-segments, change 3) and D21: six example sets are chosen and loaded in the wizard, so removing one belongs there too. Until now removal was `occ learniq:example-set:remove <id> --apply`, a server command most school and company admins never run.
- OpenRegister PR 4080 (merged 2026-09-27, `c53dd0685c`, spec `openspec/changes/demo-data-purge-by-batch`) stamps every app import with an import job id and adds `listImportJobs()` and `softDeleteAppImports()`: "The call a setup wizard's 'remove this example set' makes." Objects a job only updated are not removed, and removal is a soft delete that can be restored from the trash.
- Removal by job is more exact than removal by the descriptor's uuids: it removes what the import created on this instance, and nothing that existed before.

## Affected Projects
- [x] Project: `learniq`: `SeedProfileService::remove()`, `SetupController` (a `remove-example-set` action, a done-always status entry, a group check on the segment answer), the manifest's setup steps, the segment-wizard-choice contract, `docs/installation.md`, en and nl catalogue entries, tests.

## Scope

### In Scope
- `SeedProfileService::remove(profileId)`: resolves the import app id (`learniq.profile.<id>`, or `learniq.demo` for the generated set), duck-types `softDeleteAppImports`, and reports what was soft-deleted.
- `POST /api/setup/action/remove-example-set`: removes the set stored as the wizard's answer. No set loaded: nothing to remove. Method absent: the `occ` fallback in the message. Errors: the count and the `occ openregister:objects:purge --import-job` command that finishes the job.
- The step is always reported done, so the wizard never runs it by itself (CnSetupWizard auto-runs an outstanding run-action step) and never reopens for it.
- The segment answer: 403 unless the current user is in `admin` or `administration-managers`.

### Out of Scope
- Hiding the step when nothing is loaded: the shared wizard has no conditional steps; the step says there is nothing to remove instead.
- The `LearniqSettings` authorization block itself: lane r3-access owns `learniq_register.json`.
- Retiring `occ learniq:example-set:remove`: it stays as the fallback for an older OpenRegister.

## Approach
Code: one service method, one controller action, one group check, one manifest step. The removal call runs in-process as OpenRegister's system operation, as the import did; who may run it is decided here (admin setting, as every setup endpoint).

## New Dependencies
None. `softDeleteAppImports` is used when present (OpenRegister after PR 4080) and its absence is handled.

## Impact
- `lib/Service/SeedProfileService.php`, `lib/Service/DemoDataService.php` (its import app id becomes public), `lib/Controller/SetupController.php`.
- `src/manifest.json` (setup steps), `l10n/en.json`, `l10n/nl.json` and the generated `.js`.
- `openspec/changes/segment-wizard-choice/contract.md`, `docs/installation.md`.
- `tests/Unit/Service/SeedProfileServiceTest.php`, `tests/Unit/Controller/SetupControllerTest.php`, `tests/unit-js/setupSteps.test.mjs`.

## Cross-Project Dependencies
- openregister PR 4080 (merged): `ConfigurationService::softDeleteAppImports()`.
- learniq lane r3-access: the brief names `administration-managers` and `admin` as the groups r3-access gives the segment. Its PR #1124 (read 2026-09-28) leaves `LearniqSettings.authorization` empty, so this change enforces the two groups in the controller now and does not touch the schema; the block can follow in `learniq_register.json`, which r3-access owns.

## Risks

### Risk 1: a removal nobody asked for
**Severity:** High. **Mitigation:** the step is reported done at all times, so `CnSetupWizard::maybeAutoRunStep()` never starts it; only the button runs it. Removal is a soft delete, restorable from OpenRegister's trash. A controller test pins the done-always status.

### Risk 2: objects created before PR 4080 have no import job
**Severity:** Medium. **Mitigation:** a set loaded on an older OpenRegister has no recorded job, so the step reports nothing to remove; the message then names `occ learniq:example-set:remove <id> --apply`, which removes by uuid.

### Risk 3: a delegated admin outside the two groups
**Severity:** Low. **Mitigation:** the segment answer is refused with 403 and a reason; the example set steps keep the admin-setting rule they have.

## Rollback Strategy
Revert the PR. The occ command keeps working.

## Open Questions
- Should the wizard also offer "remove" per loaded set when an instance loaded more than one? This change removes the set the wizard last stored as its answer.
