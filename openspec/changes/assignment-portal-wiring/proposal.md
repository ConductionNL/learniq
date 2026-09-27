---
kind: code
---

# Proposal: assignment-portal-wiring

## Summary
A pupil hands in an assignment through the portal with a real file. Learniq's `createSubmission`
portal action declares portaliq's file field on `attachmentRefs`, the hand-in is scoped by a scalar
`Submission.learnerRef`, and the server stamps what a portal create cannot send (`learnerIds`,
`learnerRefs`, `tenant_id`) from the portal subject before the row is written. A staff or in-app
create still has to name its learners and tenant: the rule moves from the JSON schema to a server
listener that enforces it for every caller.

## Motivation
Round 2 recon C (`learniq-mi/learniq/_round2/recon/C-tests-grading-assignments.md`, section 1 and
journey step 2b) rates "pupil submits work via portaliq" as DECLARED, NOT USABLE. Decision D15 puts
this first: "fixing the portal file upload comes first (wave 1)". Portaliq shipped its half in
ConductionNL/portaliq#745 (a `fieldConfigs.<field>.type: file` form field, create then upload). That
PR names two learniq facts that still stop the hand-in, both verified in this repo:

1. `createSubmission` and `studentSubmissions` scope by `learnerRefs`, an array. Portaliq's writer
   stamps one string into the scope field and its reader compares `(string)$row[$scopeField]`, so
   an array never matches: the create writes a string into an array property and the list reads
   empty.
2. `Submission.required` lists `learnerIds` and `tenant_id`. The portal create sends neither, and
   OpenRegister validates `required` in `ObjectService::saveObject()` before
   `ObjectCreatingEvent` fires. So no listener can fill them in time while they stay in `required`.

Capability rows (round 1 `compare/M1-rows-draft.md`): 6.1 "Assignments with submissions, deadlines,
rubrics" and 5.13 "Homework visible to pupils and parents". Competitor evidence, recon C section 2:
Moodle `mod/assign` (online file submissions, `moodle/round1/M1-moodle-column.md`) and Woots
paper-scan upload (`assessment/round1/notes-woots.txt`). Rung 1: wiring on an existing action and
one property, no new page.

## Affected Projects
- [x] Project: `learniq`: portal contribution (`createSubmission` file field, scalar scope), the
  Submission schema (`learnerRef`, `required`), a new `SubmissionOwnerStamp` listener and a small
  `LearnerProfileLookup` service.

## Scope

### In Scope
- `createSubmission` declares `fieldConfigs.attachmentRefs` with the keys #745 documents (`type:
  file`, `multiple`, `accept`, `maxSizeMb`) and `minTrust: low`.
- `createSubmission` and `studentSubmissions` scope by the scalar `learnerRef`.
- `Submission.learnerRef` (LearnerProfile UUID), always set by the server.
- `SubmissionOwnerStamp` on `ObjectCreatingEvent` and `ObjectUpdatingEvent`:
  - a portal hand-in (no Nextcloud session, `learnerRef` stamped by portaliq, no `learnerIds`) gets
    `learnerIds`, `learnerRefs` and `tenant_id` from the pupil's LearnerProfile and the Assignment;
  - every other write gets `learnerRef` derived from `learnerIds[0]`, overwriting a client value;
  - every write that ends without `learnerIds` or `tenant_id` is refused, staff included.
- `learnerIds` and `tenant_id` leave `Submission.required`; register and schema versions bumped.

### Out of Scope
- Handing the portal draft in. The portal can create and attach, not fire `submit`/`submitLate`.
  Portaliq's `set` writes a field and would skip `SubmissionWindowGuard`, so it is not used. A
  hand-in endpoint action (the assertion receiver from `assessment-portal-endpoints`) is the
  follow-up.
- A pick list for `assignmentId` in the portal form (assignments are not scoped per learner, so a
  portaliq `collection` options provider has nothing to read yet).
- A bulk back-fill of `learnerRef` on existing Submissions. They gain it on their next write.
- The same `required` problem on `ExcuseRequest` (`learnerId`, `submittedBy`, `submittedAuthLevel`,
  `tenant_id`), named as a finding.

## Approach
Declarative where OpenRegister allows it (the manifest keys, the new property, the `required` set)
and one pre-write listener where it does not: OpenRegister has no pre-validation hook that can read
another object, so the stamp runs on `ObjectCreatingEvent` and the requirement moves with it. The
listener follows the stamp pattern of learniq PR 1020 (`GradeEntryLearnerRefStamp`): it never trusts
a client value for the learner, it fails closed when the learner cannot be resolved, and it
re-derives on update. Details in design.md.

## New Dependencies
None.

## Impact
- `lib/Portal/PortalContributionProvider.php`: `studentActions()`, `studentActivityCollections()`.
- `lib/Settings/learniq_register.json`: Submission 0.2.0 to 0.3.0, register `info.version` bump.
- `lib/Listener/SubmissionOwnerStamp.php` (new), `lib/Service/Portal/LearnerProfileLookup.php`
  (new), `lib/AppInfo/Registrar/IntegrityListenerRegistrar.php`.
- `l10n/en.json`, `l10n/nl.json` for the new property text.

## Cross-Project Dependencies
- Consumes ConductionNL/portaliq#745 (file field). Without #745 portaliq drops the unknown `type:
  file` key fail-closed and the form shows a text box, as today. No breakage either way.

## Risks

### Risk 1: portaliq's create stamps `subjectRef`, not the resolved `learnerRef` claim
**Severity:** Medium. **Mitigation:** `ContributionController::create()` stamps
`subject.subjectRef` into the scope field while reads resolve `scopeClaim`. Learniq's provider
documents that a student's `subjectRef` is their LearnerProfile UUID, so both agree when accounts
are provisioned that way. If they differ, the stamp finds no profile and refuses the create, so no
orphan row is written. Reported to portaliq as a finding.

### Risk 2: staff forms lose the required marker on learners and tenant
**Severity:** Low. **Mitigation:** the server still refuses a write without them, with a message
that names both. The schema `x-notes` say why the rule moved.

## Rollback Strategy
Revert the PR. The register version bump re-imports the previous Submission schema; rows written in
the meantime keep a harmless `learnerRef` value.
