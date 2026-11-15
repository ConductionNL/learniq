# Design: example-set-removal-in-wizard

## Architecture Overview

```
CnSetupWizard step "remove-example-set" (run-action, reported done: never auto-runs)
   │ click
   ▼
POST /api/setup/action/remove-example-set      #[AuthorizedAdminSetting(AdminSettings::class)]
   │ picked = app config example_profile
   ▼
SeedProfileService::remove(picked)
   │ appId = learniq.profile.<id>  |  learniq.demo (generated set)
   │ method_exists(ConfigurationService, 'softDeleteAppImports')?
   ├─ no  → {supported: false}  → message names the occ command
   └─ yes → softDeleteAppImports(appId)  (OpenRegister, in-process, system operation)
            → {jobs, softDeleted, errors}

POST /api/setup/config {segment}
   │ user in admin or administration-managers?  no → 403
   ▼
SegmentService::setSegment(code, uid)
```

## Decisions

### Decision 1: a run-action step, reported done
The shared `CnSetupWizard` (nextcloud-vue 2.57.1) has six step types and no conditional steps. A `run-action` step starts its action the moment it becomes current unless the server reports it done (`maybeAutoRunStep()`), and `CnAppRoot` reopens the wizard while any optional step is outstanding. A removal step that could be outstanding would therefore either delete the set as soon as someone paged onto it, or reopen the wizard on every page. The status reports it done at all times: the button stays, and only a click runs it. A custom component step with its own confirmation dialog was the alternative; it adds a component, a dialog and a registry entry for one button, and the soft delete is restorable, so the smaller step wins. The step sits last before "All set", so the first-run flow reads load, choose, and only then offers removal.

### Decision 2: the wizard's answer names the set
"This example set" is the one stored as `example_profile`, the set the load step imported. "None" or no answer means nothing to remove. An instance that loaded more than one set removes the others with occ or by picking and removing them in turn (open question).

### Decision 3: duck-typed, with the fallback in the answer
`SeedProfileService` already reaches OpenRegister through `container->get()` and an `object` return type, so an instance without OpenRegister gets a RuntimeException that names the app. `method_exists($service, 'softDeleteAppImports')` decides whether the removal can run; when it cannot, the answer names the command that can: `php occ learniq:example-set:remove <id> --apply` for a shipped set. The generated set has no descriptor for that command, so its fallback is to update OpenRegister.

### Decision 4: a job with errors stays recorded
OpenRegister keeps a job whose report has errors and forgets clean ones. The action reports `success: false` with the counts and `occ openregister:objects:purge --import-job <id>` for each unfinished job, so the admin can finish it.

### Decision 5: the load step stays answered
After a removal, `demo_data_decided` becomes `removed`, not empty: an empty value would make the load step outstanding again and reopen the wizard over every page. Running the load step again re-imports the set; OpenRegister records a new job.

### Decision 6: the segment answer checks the groups itself
The setup endpoints are admin settings, which Nextcloud lets an admin delegate to other groups. `LearniqSettings` is the record every user's menu depends on (company-segment-menu-gating), and the lane brief limits it to `administration-managers` and `admin` (lane r3-access's #1124 does not yet add that block to the schema). The controller checks the current user's groups with `IGroupManager::isInGroup()` before `SegmentService::setSegment()`, which writes as a system operation; a refused call gets 403 and writes nothing. The group names are the ones `components.securitySchemes` declares.

## Declarative-vs-imperative decision (ADR-031)
Imperative by necessity: a setup action is a request handler (ADR-042), and the removal is OpenRegister's own service call. No lifecycle, aggregation or notification is added.

## Security Considerations
- Removal is an admin setting endpoint, like every setup endpoint; OpenRegister runs it as a system operation because the import ran as one. It removes only objects the recorded import jobs created, never objects those jobs only updated.
- The segment answer is refused outside `admin` and `administration-managers`.
- No new route: the action goes through the existing `/api/setup/action/{actionId}`.

## Seed Data
No schema change, no seed.

## Risks / Trade-offs
- [A set loaded before OpenRegister recorded import jobs] → nothing to remove by job; the answer names the occ command.
- [The summary shows the removal step with a done mark] → the mark means "nothing outstanding"; the step body says it only runs on a click.

## Migration Plan
None.

## Open Questions
See the proposal.
