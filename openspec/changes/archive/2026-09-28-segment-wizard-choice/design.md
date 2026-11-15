# Design: segment-wizard-choice

The descriptor contract and the endpoint shapes are in `contract.md`; this file explains the decisions behind them.

## Architecture Overview

```
manifest.setup.steps (version 2)
  welcome ─▶ example-set ─▶ load-example-set ─▶ segment ─▶ done
               │ optionsSource: profiles          │ optionsSource: segments
               │ configKey: example_profile       │ configKey: segment
               ▼                                  │ suggestFrom: example_profile
SetupController (ADR-042 contract, admin only)    ▼
  status()     profiles ◀─ SeedProfileService::listChoices()
               segments ◀─ SegmentService::listChoices()
  saveConfig() example_profile ─▶ IAppConfig
               segment         ─▶ SegmentService::setSegment() ─▶ LearniqSettings
  runAction()  load-example-set ─▶ SeedProfileService::install(id)
                                    ├─ "demo" ─▶ DemoDataService::install()
                                    └─ <id>   ─▶ ConfigurationService::importFromApp("learniq.profile.<id>")

occ learniq:example-set:remove <id> [--apply]
  SeedProfileService::uuidsFor(id) ─▶ occ openregister:objects:purge <uuids…> --force [--apply]
```

## Nextcloud Integration
- Controllers: `SetupController` (existing routes `setup#status`, `setup#saveConfig`, `setup#runAction`; no new route).
- Services: new `SeedProfileService`; `SegmentService` gains `listChoices()`, `hasSegment()`, `setSegment()`; `DemoDataService` unchanged except its card label.
- Command: new `OCA\Learniq\Command\ExampleSetRemoveCommand`, registered in `appinfo/info.xml` `<commands>`.
- OCP: `IAppConfig` (answers), `IUserSession` (who set the segment), `IAppManager` (paths, installed apps), `Psr\Container\ContainerInterface` (OpenRegister's importer, resolved only after checking it is installed), Symfony Console `Application::find()` (the purge command).

## Decisions

### Decision 1: mirror decidesk's service, ids and step pair
`SeedProfileService` keeps decidesk's method names, its `none` answer, its file-read (never path-built) id resolution, its skip-a-malformed-file behaviour, its `x-openregister.profile` block and its `appId.profile.<id>` config id. `SetupController` keeps the choice step then run-action step pair, because `CnSetupWizard::runAction()` posts with no body and an action cannot carry the answer. Sibling apps and reviewers then read one pattern, not two.

### Decision 2: single-select
D21 and recon A open question 2: a school is one kind. `multiple: false` keeps the example set and the segment one answer each, and `saveConfig` stores one id (a one-element list is still read, for a caller that posts the wizard's multi-select shape).

### Decision 3: the segment step comes after the load step
Gate 100 (`check_setup_demo_first.py`, ADR-111 rule 4) requires `welcome` at step 1 and the offer (`example-set`) at step 2, so "What kind of organisation is this?" cannot sit between them. It follows the load step, and uses the wizard's existing `suggestFrom: example_profile` plus `suggestMap` (`po → po`, …, `training → training`) so the kind is pre-selected from the set just loaded. The map lists all six now: a suggestion only fires when the chosen set id matches, and the six segment options always exist, so a set a sibling lane has not shipped yet cannot pre-select a missing option.

### Decision 4: the server owns both option lists
Both steps declare `optionsSource` and no options of their own, exactly like the existing dataset step: the label, description and count come from the descriptor that will be imported, and the six segments from `SegmentService::listChoices()`, which a unit test compares to the schema enum. A manifest that restated either list could disagree with what the server accepts.

### Decision 5: the segment answer writes the record, and "done" reads the record
`saveConfig` passes `segment` to `SegmentService::setSegment()`: it updates the current row (the one `currentSegment()` reads) or creates one, stamping `setBy` with the admin's user id and `setAt` with now, with `_rbac: false` (ADR-042: setup writes run with system privileges). The status reports the step done when `hasSegment()` finds a valid row, so an admin who already chose on the App settings page is not asked again.

### Decision 6: setup version 2
The wizard's dismissal is remembered per `setup.version`. Without a bump, an existing install that dismissed version 1 would never see the new question. Version 2 re-offers the wizard once; the example set steps already answered stay done.

### Decision 7: the generated set keeps the id `demo`
decidesk calls its generated set `generated`. Learniq has stored `demo` in `demo_dataset` since #800, and `ci-seed.sh`, the e2e spec and runbooks post it, so the id stays `demo` and only its card label changes to "Every schema, generated values", which tells it apart from the curated sets.

### Decision 8: removal goes through OpenRegister's purge command
A school set holds `LearnerProfile`, `AttendanceRecord` and `DossierNote` rows, and all three schemas carry `x-openregister-archival`. `ObjectService::deleteObject()` refuses them unless the retention cron asks (`rejectIfArchivalImmutable()`), and OpenRegister's `PurgeObjectCommand` docblock names the one deliberate exit: `occ openregister:objects:purge <uuids> --force`, "a test fixture" among its listed uses, CLI-only because shell access is a real authorization boundary. Learniq therefore adds no HTTP delete and does not borrow the cron's `_retentionSweep` flag. `occ learniq:example-set:remove <id>` computes the uuid list from the descriptor (fixed uuids make it exact) and runs the purge command with `--force`, passing `--apply` through, so its dry-run default is OpenRegister's own.

Alternatives considered: `deleteObject(permanent: true)` per object (refused for the three archival schemas); a learniq HTTP action (would cross the boundary OpenRegister drew on purpose); waiting for `demo-data-purge-by-batch` in OpenRegister (wave 2; fixed uuids already make the purge exact).

### Decision 9: OpenRegister classes are resolved late
`SeedProfileService` reaches `ConfigurationService` through the container only after `getInstalledApps()` confirms OpenRegister, as `DemoDataService` and decidesk do, so the setup status still answers on an instance without OpenRegister (ADR-083). The command resolves nothing from OpenRegister at construction; it looks the purge command up by name when it runs.

## Declarative-vs-imperative decision (ADR-031)
No lifecycle, aggregation, calculation, relation, notification or widget is introduced. The imperative parts are the ADR-042 setup contract (list, store, import) and one admin command, which ADR-031 lists as legitimate: importing configuration and administering data are not behaviours a schema can declare.

## Security Considerations
- Every setup endpoint stays `#[AuthorizedAdminSetting(AdminSettings::class)]`.
- `saveConfig` reads only the named keys `example_profile`, `demo_dataset` and `segment`; posted keys are never looped over, because this app's settings share the app-config namespace.
- A profile id from the request is resolved by comparing it with the ids read from the files, never by building a path (`../../config/config` is refused, tested).
- A segment is validated against `SegmentService::SEGMENTS` before anything is written.
- Removal exists only as an occ command, only ever passes uuids from a shipped descriptor, and defaults to a dry run.

## File Structure
```
appinfo/info.xml                                  (<commands>: ExampleSetRemoveCommand)
lib/
  Command/ExampleSetRemoveCommand.php             (new)
  Controller/SetupController.php                  (profiles, segments, segment answer, version 2)
  Service/SeedProfileService.php                  (new)
  Service/SegmentService.php                      (listChoices, hasSegment, setSegment)
  Service/DemoDataService.php                     (card label)
  Settings/profiles/.gitkeep                      (the directory the sets land in)
src/manifest.json                                 (setup steps, version 2)
l10n/en.json, l10n/nl.json (+ .js)
tests/
  Unit/Service/SeedProfileServiceTest.php         (new)
  Unit/Command/ExampleSetRemoveCommandTest.php    (new)
  Unit/Settings/ExampleSetDescriptorContractTest.php (new; runs over lib/Settings/profiles and a fixture)
  fixtures/profiles/fixture.json                  (a minimal valid descriptor)
  Unit/Controller/SetupControllerTest.php         (rewritten for the new contract)
  Unit/Service/SegmentServiceTest.php             (writer cases)
  e2e/ci-seed.sh                                  (settles the segment step)
  e2e/spec-coverage/demo-data-setup-step.spec.ts  (new ids)
```

## Seed Data
This change adds no schema and no set. The descriptor contract it defines is in `contract.md`; the first set (`po.json`) is `segment-example-datasets-po`. The contract test runs over a minimal fixture descriptor (one school, one location, one cohort) so it is not vacuous before the first set lands.

## Risks / Trade-offs
- [A set is large, and loading is one request] → the generated set already takes 40 to 50 seconds on siblings (e2e note); a set of a few thousand objects is the same order. Seeding skips lifecycle listeners (system operation), which is most of the per-object cost.
- [Wizard copy promises removal] → the load step says an administrator can remove the set with one command; `docs/` names the command.
- [Five lanes add files and catalogue keys at once] → each adds one file under `profiles/` and its own l10n keys; the contract test catches a malformed file in that lane's PR, not after landing.

## Migration Plan
No data migration. Existing installs keep `demo_dataset` (still accepted); the wizard re-opens once (version 2). Rollback: revert the PR; remove a loaded set with the command first.

## Open Questions
None.
