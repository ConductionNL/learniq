# Test Plan: assignment-portal-wiring

## Test Cases

### TC-1: A stubbed portal create is stamped from the pupil's profile
- **spec_ref**: `openspec/changes/assignment-portal-wiring/specs/assignments/spec.md#requirement-the-server-stamps-who-a-submission-belongs-to`
- **type**: security
- **preconditions**: LearnerProfile `lp-1` (`ncUserId: pupil-1`, tenant `t-1`), Assignment `as-1` (tenant `t-1`), no Nextcloud session
- **steps**: dispatch `ObjectCreatingEvent` with the body portaliq's writer produces: `{assignmentId, learnerRef, organisation}`
- **expected result**: modified data carries `learnerIds: [pupil-1]`, `learnerRefs: [lp-1]`, `tenant_id: t-1`; not stopped
- **test command**: `vendor/bin/phpunit --filter SubmissionOwnerStampTest`

### TC-2: Unknown, merged or foreign pupils are refused
- **spec_ref**: `openspec/changes/assignment-portal-wiring/specs/assignments/spec.md#requirement-the-server-stamps-who-a-submission-belongs-to`
- **type**: security
- **preconditions**: no profile / merged profile / missing assignment / assignment in `t-2`
- **steps**: the same stubbed portal create
- **expected result**: the event is stopped with a reason
- **test command**: `vendor/bin/phpunit --filter SubmissionOwnerStampTest`

### TC-3: Staff and app writes keep the owner rule
- **spec_ref**: `openspec/changes/assignment-portal-wiring/specs/assignments/spec.md#requirement-the-server-stamps-who-a-submission-belongs-to`
- **type**: regression
- **preconditions**: a signed-in user
- **steps**: create without `learnerIds`; create with a forged `learnerRef`; update that blanks `tenant_id`; update whose lookup throws
- **expected result**: refused; replaced by the derived ref; refused; stored ref kept
- **test command**: `vendor/bin/phpunit --filter SubmissionOwnerStampTest`

### TC-4: The profile lookup reads the right keys
- **spec_ref**: `openspec/changes/assignment-portal-wiring/specs/assignments/spec.md#requirement-the-server-stamps-who-a-submission-belongs-to`
- **type**: functional
- **steps**: `byRef` on active, merged, deleted and missing profiles; `refForUser` with a merged and a surviving profile
- **expected result**: active row / null / null / null; the survivor's uuid; `register` and `schema` nested in `filters`, filter key `ncUserId`
- **test command**: `vendor/bin/phpunit --filter LearnerProfileLookupTest`

### TC-5: The manifest carries the file field and the scalar scope
- **spec_ref**: `openspec/changes/assignment-portal-wiring/specs/portal-contribution/spec.md#requirement-a-pupil-hands-in-work-through-the-portal-with-a-real-file-req-pcon-007`
- **type**: api
- **steps**: `getContribution(student)`
- **expected result**: `fieldConfigs.attachmentRefs` meets #745's limits; both submission entries scope by `learnerRef`, which the register declares
- **test command**: `vendor/bin/phpunit --filter PortalContributionProviderTest`

## Coverage Summary
- assignments: The server stamps who a submission belongs to: TC-1, TC-2, TC-3, TC-4.
- portal-contribution: REQ-PCON-007: TC-5.

## Out of Scope
- A live portal upload: portaliq#29 (lazy register folder) breaks the first upload on a fresh
  instance, and lanes do not touch the shared instance.
