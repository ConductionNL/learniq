## ADDED Requirements

### Requirement: Persist FirstAidIncident as a fourth pupil-dossier domain object

The system MUST persist `FirstAidIncident` as an OpenRegister object, `appendOnly: true` (ADR-008 — a
correction is a new record, never an in-place edit of prior evidence about a named minor), separate from
`DossierNote`, `BehaviourIncident`, and `WellbeingCheckIn`, and separate from `LearnerProfile`'s standing
`medicalConditions`/`allergies` fields (a first-aid incident is a single dated event, not a standing
condition — finding G-new-15).

`FirstAidIncident` MUST carry `learnerId`, `reportedBy`, `occurredAt`, `whatHappened`, a nullable
`treatmentGiven`, `notifiedGuardian` (boolean, default `false`), a nullable `notifiedGuardianAt`, an
append-only `followUpActions` array (each entry: `recordedBy`, `recordedAt`, `action` — same shape as
`BehaviourIncident.followUpActions`), a nullable `resolution`, `tenant_id`, and an
`x-openregister-lifecycle` (`open → in-handling → resolved`, mirroring `BehaviourIncident`).

Read access MUST be restricted server-side to admin/mentor/coordinator/the incident's own `reportedBy` (the
same tightest-common-floor `x-property-rbac` pattern as `DossierNote`/`BehaviourIncident`), and creation
MUST fire an `nc-notification` to the `mentor`/`coordinator` groups, mirroring
`BehaviourIncident.x-openregister-notifications.incidentRecorded`.

#### Scenario: A staff member records a first-aid incident

<!-- @e2e exclude a manifest-declarative object-list/detail page over an already-covered pupil-dossier
     pattern (DossierNote/BehaviourIncident); asserted by PHPUnit register-shape tests
     (ProcessingActivityCatalogueTest) and manifest validation (npm run check:manifest), not a distinct
     browser scenario beyond what pupil-dossier.spec.ts already exercises for the sibling schemas -->

- **GIVEN** the `FirstAidIncident` schema is registered
- **WHEN** a staff member saves a `FirstAidIncident` for a learner with `whatHappened` and `occurredAt`
- **THEN** the incident is stored as an `appendOnly` OpenRegister object with `lifecycle: "open"`
- **AND** a notification is sent to the `mentor`/`coordinator` groups

#### Scenario: A first-aid incident tracks follow-up to resolution

<!-- @e2e exclude lifecycle transitions on an appendOnly object are backend/register mechanics with no
     distinct DOM surface; transition correctness is asserted by the register's own
     x-openregister-lifecycle declaration, the same pattern already relied on for BehaviourIncident -->

- **GIVEN** a `FirstAidIncident` with `lifecycle: "open"`
- **WHEN** staff append a `followUpActions` entry and transition it through `startHandling` to `resolve`
- **THEN** `lifecycle` becomes `"resolved"` and `resolution` is set
- **AND** every prior `followUpActions` entry remains unchanged (append-only)

#### Scenario: Only admin, mentor, coordinator, or the reporting staff member can read an incident

<!-- @e2e exclude server-side x-property-rbac enforcement is OpenRegister's read-time gate, asserted by the
     schema's own x-property-rbac declaration plus the platform's existing RBAC test suite, not a new
     scholiq-side check -->

- **GIVEN** a `FirstAidIncident` reported by staff member A
- **WHEN** a user who is not admin, mentor, coordinator, or staff member A requests the record
- **THEN** OpenRegister's `x-property-rbac` read gate rejects the request
