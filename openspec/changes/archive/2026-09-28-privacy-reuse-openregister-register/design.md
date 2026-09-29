# Design: privacy-reuse-openregister-register

## Source facts

OpenRegister `development` at `d611a366`, `lib/Settings/data_subject_request_register.json`:

- register `data-subject-requests`, schema `dataSubjectRequest` version 1.2.0;
- required: `subjectId`, `type`, `status`, `receivedAt`;
- `type`: access, rectification, erasure, restriction, portability, objection;
- `status` lifecycle: received → verifying / in-progress / evidence-collection / denial-drafted → fulfilled / refused / closed;
- `notes` free text, max 4000; `handler` a Nextcloud uid used for reminders;
- no `authorization` block.

Learniq's retired schema (`lib/Settings/learniq_register.json` 0.24.9): `kind` correction | deletion, `learnerId`, `submittedBy`, `description`, `requestedAt`, `decidedAt`, `auditTrail[]`, lifecycle requested → in-review → completed | rejected.

## Field mapping

| learniq | OpenRegister | note |
|---|---|---|
| `kind` correction / deletion | `type` rectification / erasure | |
| lifecycle requested / in-review / completed / rejected | `status` received / in-progress / fulfilled / refused | |
| `learnerId` | `subjectId`, `subjectType: nextcloud-user` | |
| `requestedAt` | `receivedAt` | |
| `decidedAt` | `closedAt` | only on fulfilled or refused |
| `submittedBy` | `handler` | the staff member who logged it gets the deadline reminders |
| `description`, `auditTrail` | `notes` | under the marker line `learniq-migration: <uuid>` |
| `tenant_id` | (none) | OpenRegister scopes by organisation itself |

## Decisions

- **Idempotent on the target, like pipelinq.** The marker line is read back on every run; a source with a case is skipped. Writing a marker onto the source is not needed and would need a write to a schema that is being retired.
- **Source rows stay.** Nothing reads them after this change. Deleting an AVG record in a repair step is the wrong default; a school can drop them after checking.
- **Order.** Before `InitializeSettings` in `post-migration`, so the retired schema and its rows are certainly present when the copy runs. Not under `<install>`: a fresh install has nothing to move.
- **No RBAC in the repair step.** A repair step has no session; every read and write passes `_rbac: false`, `_multitenancy: false`, as `BackfillGradeEntryLearnerRef` does.
- **Dashboard with library widgets only.** `stat` tiles read `twoFactor.enabledCount` (caption `of {twoFactor.eligibleCount} staff accounts`), `dataExchange.pending|approved|rejected`; `object-table` reads `groups` with columns id, provisioned, memberCount. `useEndpointSource` shares one request per render, so five widgets cost one call. `emptyText: unknown` keeps the old page's rule that a null is never shown as 0.
- **Titles.** The pages now show every AVG right, so "Correction & deletion requests" becomes "Privacy requests".

## Declarative-vs-imperative decision

| behaviour | path | reason |
|---|---|---|
| request storage and lifecycle | declarative, OpenRegister's own register | D20 |
| overview tiles | declarative manifest widgets | ADR-012, no custom component |
| overview figures | existing imperative controller | group counts and 2FA state come from Nextcloud, not from objects |
| moving existing rows | imperative repair step | one-time data migration |

## Seed Data

The three learniq seed rows for `data-subject-request` are removed with the schema. OpenRegister's register carries its own `x-openregister-seeds`; learniq adds none.
