---
kind: code
depends_on: []
---

# Proposal: privacy-reuse-openregister-register

## Summary

Learniq stops keeping its own copy of the AVG request register. The privacy request pages now read OpenRegister's shared `data-subject-requests` register, a repair step moves existing requests there, and the privacy governance overview becomes a typed dashboard page instead of a custom Vue page. Decision D20, closing the D13 proposal.

## Motivation

Decision D20 (Ruben, 2026-09-27, `learniq-mi/learniq/_round1/compare/decisions.md`): "the request pages point at OpenRegister's data subject request register, learniq drops its copy, the overview becomes a typed dashboard page."

The measurement behind D13: OpenRegister ships `lib/Settings/data_subject_request_register.json` (register `data-subject-requests`, schema `dataSubjectRequest` 1.2.0) with six request types (access, rectification, erasure, restriction, portability, objection), the art-12 deadline, escalation, a handling service (`lib/Service/Gdpr/DataSubjectRequestService.php`), a case controller (`DsarCaseController`) and its own AVG screen (`src/views/avg/AvgIndex.vue`). Learniq's `DataSubjectRequest` (merged in #912, privacy-governance-surfaces) was a smaller copy: two kinds, four states, no deadline. Pipelinq already made the same move (`consume-or-dsar`, `MigrateAvgVerzoekenToOrDsar`).

The privacy governance page (#912) was a custom Vue component. It is read-only and reads one endpoint, which a typed dashboard page can do through `CnStatWidget`'s `endpointSource`. That brings the custom pages in `src/manifest.d/dashboard.json` from ten back to nine, the count the gate-69 ratchet flagged on #912.

## Affected Projects

- [x] Project: `learniq`: register, mock register, repair step, manifest, settings view, registry, l10n, tests.

## Scope

### In Scope

- Remove the `DataSubjectRequest` schema and its slot in the register's schema list; bump `info.version` to 0.25.0. Remove its three seed rows from the mock register.
- New repair step `MigrateDataSubjectRequestsToOpenRegister`, before `InitializeSettings` in `post-migration`: copies each learniq request into OpenRegister as a `dataSubjectRequest` case, idempotent on the target.
- Point the `DataSubjectRequests` index and `DataSubjectRequestDetail` pages at register `data-subject-requests`, schema `dataSubjectRequest`, lifecycle field `status`; retitle them "Privacy requests".
- Repoint the settings section's recent-requests list (`LearniqSettings.vue`) at the same register and field names.
- Rebuild `PrivacyGovernanceDashboard` as `type: "dashboard"`: four `stat` tiles and one `object-table`, all reading `/api/privacy-governance/overview` through `endpointSource`. Delete `src/views/PrivacyGovernanceDashboard.vue` and its registry entry.
- Tests for the retirement, the page bindings (checked against the real controller payload) and the repair step.

### Out of Scope

- Evidence harvesting: a learniq `EvidenceSourceProvider` for OpenRegister's `EvidenceSourceRegistry`, so a DSAR case finds a pupil's learniq data. A follow-up change.
- Deleting the old learniq rows. The repair step leaves them in place until a school has checked the migrated cases.
- The Privacyconvenant record and the partner approval gate from #912: they have no OpenRegister equivalent and stay as they are (D13).
- Changes to OpenRegister's register, its RBAC or its AVG screen.

## Approach

Follow pipelinq's `consume-or-dsar`: map fields where OpenRegister has one, write the rest (description, audit trail) into `notes` under a marker line that makes the migration idempotent. The dashboard page uses the library's widgets only, so no app code renders it. Detail in design.md.

## New Dependencies

None. OpenRegister already ships the register.

## Impact

The Compliance menu's privacy request pages, the settings section, the privacy governance page, the register import and the upgrade path.

## Cross-Project Dependencies

Reads OpenRegister's `data-subject-requests` register (core, shipped on OpenRegister `development`). No OpenRegister change.

## Risks

### Risk 1: who can read OpenRegister's register

**Severity:** Medium. **Mitigation:** OpenRegister's `dataSubjectRequest` declares no `authorization` block, so its read access follows OpenRegister's default, not learniq's `instructors`/`compliance-officers` floor. Named in the PR for the reviewer; tightening it is an OpenRegister change.

### Risk 2: the list shows other apps' requests

**Severity:** Low. **Mitigation:** the register is shared by design (a pipelinq DSAR for the same person is the same case). The index shows every case the reader may see.

### Risk 3: a failed migration

**Severity:** Low. **Mitigation:** each failed row is counted and logged; the source row stays; the next upgrade retries. A re-run never duplicates a case.

## Rollback Strategy

Revert the merge commit. The migrated cases stay in OpenRegister and the source rows are untouched, so a revert loses nothing.
