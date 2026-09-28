# Design: statutory-field-completeness

## Context

Four MUST-tier statutory findings (2.11, 6.8, 4.9, 2.9) each name a gap that is purely data-model: an
age-derived rights flag, an enum value, a classification field, and a retention annotation. This lane's brief
explicitly says to check `openregister/openspec/specs/` for a retention dialect before building anything —
it exists (`archival-annotation-vocabulary`, `retention-management`, `archival-destruction-workflow`, all
`status: done`), so this change declares against it rather than building a learniq background job.

## Goals / Non-Goals

**Goals:** age-derived self-service-rights data on `LearnerProfile`; `referentieniveau` on `AssessmentResult`;
`flagKind` on `AttendanceFlag`; `x-openregister-archival` on six schemas.

**Non-Goals:** a portal UI gating self-service actions on the new flags (separate `code` change); enforcing
retention correctness against a specific selectielijst category (privacy-officer review, same posture as
`avg-verwerkingsregister`'s existing draft-seed pattern); covering all ten processing-activity schemas with
archival annotations in one pass.

## Decisions

### Decision 1: Self-service rights are two materialised booleans derived from `birthDate`, not a manually-set flag

**Alternatives considered:** a plain boolean a staff member toggles manually. Rejected: the right is defined
by AGE, not by staff judgement (ParnasSys's own framing: "Vanaf 18 jaar kan de student zelf bepalen" — age is
the trigger); a materialised calculation stays correct automatically as a pupil ages, where a manually-set
flag would silently go stale the day after a birthday.

`ageYears` is calculated once and reused by both threshold flags, so a wrong `unit` value (Risk 1) is fixed
in exactly one place, not three.

### Decision 2: `AttendanceFlag.flagKind` is a new enum property, not a new schema

`AttendanceFlag` already carries everything a langdurig-relatief-verzuim or thuiszitter case needs
(`learnerId`, `windowStart`/`windowEnd`, `interventions`, `dataExchangeJobId` for the SWV/leerplichtambtenaar
report) — the only missing piece is which STATUTORY CATEGORY the flag represents, which is exactly what an
enum classification property is for. A new schema would duplicate the entire existing shape for no benefit
(same reasoning as `assessment-completeness`'s Decision 4 for `GradeEntry.sourceKind`).

### Decision 3: Retention periods (2/3/5 years) per schema, and the reasoning for each

No school-specific selectielijst mapping was available in this lane's corpus (the brief's named
`legal-po-2026-09-25.md` recon file does not exist in the corpus directory — checked, absent). Absent that,
this change uses well-established, named Dutch education-sector retention conventions as a **documented
starting point for privacy-officer review**, never a final legal determination:

- `LearnerProfile` → **P5Y**: leerlingdossier retention after uitschrijving is commonly 5 years in Dutch PO/VO
  selectielijsten (ParnasSys's own finding 2.9 evidence names "2-year and 5-year terms after unenrolment" —
  this change takes the longer, safer bound for the core identity record).
- `AttendanceRecord` → **P5Y**: leerplicht/verzuim records are commonly retained on the same long horizon as
  the learner record they evidence.
- `AttendanceFlag` → **P3Y**: a formal verzuim escalation record — shorter than the core leerlingdossier but
  longer than a routine daily attendance mark, reflecting its evidentiary role in a leerplichtambtenaar
  report without assuming the same retention as the full dossier.
- `DossierNote` / `BehaviourIncident` / `WellbeingCheckIn` → **P2Y**: routine day-to-day pastoral records,
  the shortest of the three bands — these are the everyday notes `avg-verwerkingsregister`'s own
  `pupil-dossier` capability already distinguishes from the formal OPP/zorgvraag track.

### Decision 4: Use `x-openregister-archival`, not a learniq `BackgroundJob`

Per the brief's own instruction to check for a retention dialect first: `retention-management/spec.md` and
`archival-destruction-workflow/spec.md` (both `status: done`) already implement `ArchivalRetentionTask`
(hourly sweep), a full approval-gated destruction-list workflow, legal holds, and `archival.destroyed` audit
entries. Building a second implementation would violate "check for a parallel fix before building one" —
this is a platform capability learniq should consume, not reimplement (ADR-022 thin-consumer pattern, the
same one `avg-verwerkingsregister` already follows for the processing-activity register itself).

## Declarative-vs-imperative decision (ADR-031)

Every behaviour this change introduces (`ageYears`/`hasPartialSelfServiceRights`/`hasFullSelfServiceRights`
as `x-openregister-calculations`; retention as `x-openregister-archival`) is declared directly in
`lib/Settings/learniq_register.json` — no new `lib/Service/*Service.php` class, no new `BackgroundJob`. This
is the default declarative path ADR-031 prescribes, and Decision 4 above is the explicit
declarative-vs-imperative call for the retention piece specifically (a platform dialect exists, so no
imperative code is written).

## Seed Data

No seed changes needed: the three new `LearnerProfile` calculated properties materialise from existing
`birthDate` values with no backfill; `AssessmentResult.referentieniveau` and `AttendanceFlag.flagKind` are
nullable/defaulted so every existing row stays valid; `x-openregister-archival` computes `archiefactiedatum`
from each row's own creation date going forward — no existing row needs editing to gain a value.

## Risks / Trade-offs

- [Risk] `dateDiff` `unit: "years"` unverified against a live instance (see proposal Risk 1).
- [Risk] The 2/3/5-year mapping is this change's own reasoning pending privacy-officer confirmation (see
  proposal Risk 2).

## Migration Plan

Not applicable — declarative schema annotations only; `migration.md` is skipped per its own `skipWhen`
condition. `x-openregister-archival`'s own destruction path is entirely OpenRegister's platform mechanism
(cron sweep + approval workflow), not a Nextcloud `lib/Migration/` class.

## Open Questions

- Confirm `dateDiff`'s supported `unit` values against a live OpenRegister instance.
- Privacy-officer review of the 2/3/5-year mapping before any of these six schemas' rows are actually
  destroyed in a real deployment.
