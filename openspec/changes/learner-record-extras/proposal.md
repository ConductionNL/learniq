---
kind: config
depends_on: []
---

# Proposal: learner-record-extras

## Summary

Learniq's pupil card (`LearnerProfileDetail`) is the school's single most-visited page and is missing
fields every competitor in the round carries: a home address, a guardian's legal-authority (gezag) flag,
emergency contacts, medical conditions and allergies with a yearly consent date, and a duty-of-care first-aid
record. It also has no standing care/plusklas subgroup type (subgroups today only exist inside a GroupPlan)
and no tabbed layout, so the fields it does have are either buried in a 16-field "show all" expander or,
for guardians, shown as a raw UUID list that renders as a dash. This change adds the missing `LearnerProfile`
fields, a `FirstAidIncident` schema, a `Cohort.kind` property for standing care/plusklas groups, and
reorganises `LearnerProfileDetail` into a tabbed pupil card using the existing `tabs` widget type
(`registerDashboardWidget('tabs', ...)` in `@conduction/nextcloud-vue`), which no learniq manifest uses yet.

## Motivation

`findings.md` rows 2.13, 2.1, 2.12, 1.14, and PO-research findings G-new-12/G-new-15 (learniq round-1
competitor comparison, `learniq-round1/compare/`) all point at the same gap: `src/manifest.d/people.json`'s
`LearnerProfileDetail` and `lib/Settings/learniq_register.json`'s `LearnerProfile` schema carry identity,
guardian refs, enrolments, attendance, and dossier links, but no address, no gezag flag, no emergency
contact, no medical/allergy record, and no first-aid record — every one of gibbon (`gibbonCustomField`,
`gibbonFirstAid`/`FollowUp`), ParnasSys (`documented-column.md` row 2.4: "per verzorger aangeven of de
verzorger wettelijk gezag heeft"; row 2.6: medical data + Parro-portal updates), and frappe-education
carries at least a partial version of these. `learniq-baseline/pupil-card-anatomy.md`'s live capture
confirms the UX cost directly: "Guardian Refs... the value shown was '—' for Bram de Vries... This is the
ONE detail page in the app observed to show its own object's fields prominently" yet still buries guardians
and has "no photo, no tabs" per this lane's brief. Finding 1.14 (ParnasSys/ESIS "sublesgroep"/
"instructiegroepen") shows a standing care/plusklas group is a named competitor pattern that
`GroupPlanSubgroup` cannot express (it requires a `groupPlanId`, so it dies with the plan).

## Affected Projects

- [x] Project: `learniq` — schema additions on `LearnerProfile` and `Cohort`, a new `FirstAidIncident`
  schema, and a tabbed `LearnerProfileDetail` manifest layout plus two new manifest pages
  (`FirstAidIncidents`/`FirstAidIncidentDetail`).

## Scope

### In Scope

- `LearnerProfile`: `address` (object), `emergencyContacts` (array of object, ordered by priority),
  `medicalConditions` (array of string), `allergies` (array of string), `medicalConsentDate` (date), and
  `hasParentalAuthority` (nullable boolean gezag flag, meaningful on a guardian's own profile).
- A new `FirstAidIncident` schema (learnerId/reportedBy/occurredAt/whatHappened/treatmentGiven/
  notifiedGuardian/followUpActions/resolution/lifecycle), mirroring `BehaviourIncident`'s shape, plus its
  own index+detail manifest pages and a `Pupil dossier` menu entry.
- `Cohort.kind` (`teaching` / `care` / `plusklas`, default `teaching`) — a standing subgroup type that does
  not require a `GroupPlan`.
- `LearnerProfileDetail` reorganised into a `tabs` widget with four tabs (Identity; Address & contact;
  Guardians — the existing `related` widget promoted out of the bottom of the page into a top-level tab;
  Medical & first aid, including a `FirstAidIncident` object-list) instead of one long scroll.
- Register-level PHPUnit test updates (`ProcessingActivityCatalogueTest`) and `npm run check:manifest`.

### Out of Scope

- A generic custom-field engine (the fuller G-new-12 ambition in `change-plan.md`). This change ships the
  concrete fields PO research and the findings actually named; a school-configurable field builder is a
  materially larger, separately-scoped change and is not built here.
- Per-child gezag (a guardian's authority differing child by child in blended families). `hasParentalAuthority`
  is one flag per guardian profile; the finer per-relationship case is a documented limitation, not silently
  dropped.
- A photo/avatar field. No competitor evidence in this lane's corpus specifies a storage mechanism (file vs.
  URL vs. NC avatar reuse) precise enough to commit to one without guessing; tracked as an open question below.
- Any change to `GroupPlanSubgroup` itself — the standing-group gap is closed on `Cohort`, not by changing the
  GroupPlan-scoped subgroup's required `groupPlanId`.

## Approach

All changes are declarative: JSON Schema property additions in `lib/Settings/learniq_register.json` and
manifest fragment edits in `src/manifest.d/people.json` / `src/manifest.d/pupil-record.json`. No PHP
controller, service, or Vue component is added — `FirstAidIncident` is served by OpenRegister's generic
object API exactly as `DossierNote`/`BehaviourIncident`/`WellbeingCheckIn` already are (thin-consumer
pattern, ADR-022). The tabbed layout uses `registerDashboardWidget('tabs', { renderer: CnTabsWidget, ... })`,
which is already registered by `@conduction/nextcloud-vue` (`CnWidgetGrid/registerDashboardWidgets.js`) and
declared via `content.tabs: [{ widgetId, label?, icon? }]` referencing sibling widget definitions on the
same page — no new frontend code, only manifest config.

## Capabilities

### Modified Capabilities

- `avg-verwerkingsregister` — `LearnerProfile`'s processing-catalogue `dataCategories` gain the new personal
  data categories (address, emergencyContacts, medicalConditions, allergies), and a fourth pupil-dossier-style
  processing activity (`scholiq-first-aid-incidents`) joins the three already declared.
- `pupil-dossier` — a `FirstAidIncident` schema joins `DossierNote`/`BehaviourIncident`/`WellbeingCheckIn` as
  a fourth append-only duty-of-care record type.
- `school-structure` — `Cohort` gains a `kind` property so a standing care/plusklas subgroup no longer
  requires a `GroupPlan`.

## New Dependencies

None.

## Impact

- `lib/Settings/learniq_register.json` — `LearnerProfile` (6 new properties + `dataCategories` update),
  `Cohort` (`kind` property), new `FirstAidIncident` schema.
- `src/manifest.d/people.json` — `LearnerProfileDetail` widgets/layout reorganised into tabs.
- `src/manifest.d/pupil-record.json` — `FirstAidIncidents`/`FirstAidIncidentDetail` pages + menu entry.
- `tests/Unit/Settings/ProcessingActivityCatalogueTest.php` — activity count 10 → 11, new
  `FirstAidIncident` row.

## Cross-Project Dependencies

None — single-project, no new or changed API surface consumed by another apps-extra project.

## Risks

### Risk 1: `hasParentalAuthority` models one flag per guardian, not per guardian-child pair

**Severity:** Low — **Mitigation:** documented as an explicit out-of-scope limitation in this proposal and
in the schema property's own `description`; the common case (a guardian's authority status does not vary
across their own children) is served, and the finer case is a candidate for a future change once a real
customer needs it (per the corpus's own "no board switcher until a multi-bestuur pilot customer exists"
precedent for deferring by absence of a concrete need).

### Risk 2: `FirstAidIncident.learnerId`/`reportedBy` follow the existing `DossierNote`/`BehaviourIncident`
convention of describing a Nextcloud user ID while sibling detail-page filters resolve it via `@objectId`
(the `LearnerProfile` object UUID)

**Severity:** Low — **Mitigation:** this is a pre-existing, already-shipped ambiguity in every sibling
pupil-dossier schema (`learniq-baseline/pupil-card-anatomy.md` did not flag it as new), not something this
change introduces; `FirstAidIncident` copies the identical convention for consistency rather than
introducing a second, differently-documented pattern.

## Rollback Strategy

Every change is additive (new nullable/defaulted properties, a new schema, new manifest pages, a
reorganised — not removed — set of existing widgets). Revert is a straight `git revert` of this change's
commit(s); no data migration or backfill is introduced, so no destructive rollback step exists.

## Open Questions

- Should a pupil photo live as an OpenRegister file attachment (mirroring `Credential`'s `cred-files`
  integration widget) or as a URL property? Deferred — no competitor evidence in this lane's corpus specifies
  which, and guessing the storage shape risks a breaking change later. Decision: out of scope this change.
