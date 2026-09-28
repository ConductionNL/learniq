# data-exchange delta: data-exchange-to-integriq

## REMOVED Requirements

### Requirement: Persist DataExchangeJob and DataMappingProfile in OpenRegister
**Reason**: Decision D7 moves the job and the mapping to integriq's own `job` and `mapping` schemas.
**Migration**: `MigrateDataExchangeToIntegriq` sends every job to integriq with its history and every customised profile as a `learniq-custom-*` mapping; the 23 seeded profiles are integriq seeds `learniq-*`. All rows are archived to `data-exchange-archive/retired-data-exchange.json`.

### Requirement: Delegate wire protocols to OpenConnector
**Reason**: Learniq no longer calls a connector at all; integriq runs the job and owns the adapters.
**Migration**: learniq asks integriq with `ExchangeJobRequestedEvent` (see "Learniq asks integriq to carry an exchange").

### Requirement: OSO parent-review is a lifecycle gate
**Reason**: The job and its lifecycle live in integriq; the parent's decision is a learniq record now.
**Migration**: "The gate refuses an OSO or SWV file until a parent approved it".

### Requirement: Frontend is declarative with named custom views
**Reason**: `OsoDossierReviewView` is replaced by a declarative detail page; the data exchange pages read integriq's schemas.
**Migration**: "The Data exchange menu is a read-only status panel beside the gate pages".

### Requirement: Verzuimloket dossier composition mirrors the OSO dossier composer
**Reason**: The composition is part of what may leave now, answered in the gate.
**Migration**: "What may leave is decided by learniq, per mapping".

### Requirement: OSO-format dossier parent-review gate covers the SWV zorgvraag target too
**Reason**: Same move as the OSO parent review.
**Migration**: "The gate refuses an OSO or SWV file until a parent approved it".

### Requirement: Persist ExchangeRejection mapped from job rejections
**Reason**: Rejections are integriq `sync_item_dead_letter` rows (D7).
**Migration**: the migration carries every rejection into the migrated job's dead letters, its status translated by integriq's `learniq-exchange-rejection-status` mapping.

### Requirement: Resolve a job's rejected records to their Scholiq source object
**Reason**: Integriq keeps the owner reference (`learner-profile/<uuid>`) on the dead letter.
**Migration**: the rejections page shows the owner reference.

### Requirement: Inline correction worklist with per-rejection resubmission
**Reason**: Resubmit and waive are integriq's (`/api/exchange/rejections/{id}/resubmit`, `/waive`).
**Migration**: an administrator corrects the source record in learniq and resubmits in integriq.

### Requirement: Track rejection age as an informational urgency signal
**Reason**: The dead letter carries its capture time and correction deadline.
**Migration**: none needed.

### Requirement: DUO error-code catalogue as local reference data
**Reason**: The catalogue is an integriq seed per target.
**Migration**: custom codes are archived to the file; the seeded ones exist in integriq.

## ADDED Requirements

### Requirement: Learniq asks integriq to carry an exchange
Learniq MUST request every data exchange through integriq's `ExchangeJobRequestedEvent`, with its own id as owner, the row that caused it as `ownerRef` and selectors only in the scope. It MUST fail closed when integriq is absent: no job, and a message that integriq is needed. Learniq MUST NOT store a job of its own.

#### Scenario: an attendance flag asks for a leerplicht report
- GIVEN integriq is installed and an attendance threshold's crossing names the target `leerplicht`
- WHEN the flag is created
- THEN learniq dispatches `ExchangeJobRequestedEvent` for `leerplicht`, `export`, mapping `learniq-leerplicht-export-melding`
- AND the flag's `dataExchangeJobId` is the integriq job id

#### Scenario: integriq is absent
- GIVEN integriq is not installed
- WHEN a support request is submitted
- THEN no job id is stored and no dossier review is created
- AND the failure is logged, not thrown into the submit

### Requirement: The gate refuses an OSO or SWV file until a parent approved it
For an `oso` or `swv` export, learniq's gate MUST refuse unless a `DossierReview` for that integriq job is `approved`. Only a parent listed on the learner's profile MUST be able to approve or reject it.

#### Scenario: no parent approved yet
- GIVEN an `swv` job whose `DossierReview` is `pending`
- WHEN integriq asks the gate
- THEN the gate refuses with `parent-review-pending`

#### Scenario: a parent approves
- GIVEN a `DossierReview` for learner L and a user listed in L's `parentIds`
- WHEN that user approves it
- THEN it is `approved` with `reviewedBy` and `reviewedAt` stamped, and the gate allows the job

### Requirement: The gate enforces partner approval, teldatum confirmation and flag handling
The gate MUST refuse a job whose target has an `ExchangePartnerApproval` row and none `approved`; a job whose scope names a `teldatumDate` without a `confirmed` `TeldatumCheck` for that date and target; and a `leerplicht` job whose attendance flag is still `open`. A target with no partner approval row MUST NOT be blocked by partner approval.

#### Scenario: a partner link awaits approval
- GIVEN an `ExchangePartnerApproval` for `swv` in status `pending`
- WHEN integriq asks the gate for an `swv` job whose file a parent approved
- THEN the gate refuses with `partner-approval-missing`

#### Scenario: the teldatum is not confirmed
- GIVEN a `bron-rod` job whose scope names `teldatumDate` 2026-10-01 and no confirmed check for it
- WHEN integriq asks the gate
- THEN the gate refuses with `teldatum-unconfirmed`

#### Scenario: nobody took up the flag
- GIVEN a `leerplicht` job whose attendance flag is `open`
- WHEN integriq asks the gate
- THEN the gate refuses with `flag-not-in-handling`

### Requirement: What may leave is decided by learniq, per mapping
When every other condition passes, the gate MUST hand integriq only the fields the job's mapping reads, per record `{recordId, sourceKind, data}`, with the leerplicht and SWV files composed as before, and never `bsnEncrypted`, `bsnHash` or `email`. A statutory target (`bron-rod`, `oso`, `swv`) without a known mapping MUST be refused `disclosure-undefined`. A `bron-rod`, `leerplicht` or `oso` record missing a statutory field MUST refuse the job `statutory-incomplete`, naming fields and references, never values.

#### Scenario: a ROD export hands over five fields
- GIVEN a `bron-rod` job with mapping `learniq-bron-rod-export-learner` over two complete learner profiles
- WHEN integriq asks the gate
- THEN the gate allows with two records whose data holds only `eckId`, `givenName`, `familyName`, `birthDate`, `schoolId`

#### Scenario: a statutory export without a mapping
- GIVEN a `bron-rod` job for a school advice with no mapping
- WHEN integriq asks the gate
- THEN the gate refuses with `disclosure-undefined` and composes no record

#### Scenario: a record misses its birth date
- GIVEN a `bron-rod` job whose second learner has no `birthDate`
- WHEN integriq asks the gate
- THEN the gate refuses with `statutory-incomplete`, naming `birthDate` and that learner's reference

### Requirement: Learniq serves its gate decision over HTTP for people
`GET /api/exchange-gates/{jobId}` MUST answer `{jobId, decision, code, reason, checkedAt}` for an integriq job owned by learniq, without the records, to admins, administration managers, compliance officers and coordinators; 404 for a job that is not learniq's or when integriq is absent.

#### Scenario: a coordinator checks why a job waits
- GIVEN an integriq job owned by learniq whose teldatum is unconfirmed
- WHEN a coordinator calls `GET /api/exchange-gates/{jobId}`
- THEN the answer is `refuse` with `teldatum-unconfirmed` and no records

### Requirement: The Data exchange menu is a read-only status panel beside the gate pages
The Data exchange menu MUST show integriq's jobs and rejections owned by learniq, read-only, and the partner approval, teldatum check and dossier review pages. It MUST NOT offer to create or edit a job.

#### Scenario: an administrator opens the panel
- GIVEN integriq holds two jobs owned by learniq and one owned by another app
- WHEN an administrator opens Exchange jobs
- THEN two rows show, without an add or edit action

### Requirement: Existing exchange rows are moved to integriq, or archived
A repair step MUST archive every row of the four retired schemas to app data, and when integriq is installed MUST send each job (with its rejections) and each customised mapping profile to integriq, once. It MUST NOT delete the old rows.

#### Scenario: an install with integriq
- GIVEN two learniq jobs, one with a waived rejection, and integriq installed
- WHEN the repair step runs
- THEN integriq receives two job requests with history, the archive file holds both jobs and the rejection, and a second run sends nothing

#### Scenario: an install without integriq
- GIVEN learniq jobs and no integriq
- WHEN the repair step runs
- THEN the archive file holds every row and nothing is dispatched

### Requirement: A succeeded SWV exchange routes its support request
When integriq concludes a learniq `swv` job `succeeded`, learniq MUST move the support request named in the job's scope to `routed-to-swv`; any other outcome MUST change nothing.

#### Scenario: the SWV hand-off succeeded
- GIVEN a submitted support request whose `swv` job integriq concluded `succeeded`
- WHEN the concluded event arrives
- THEN the support request is `routed-to-swv`
