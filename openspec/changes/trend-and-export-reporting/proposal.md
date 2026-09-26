---
kind: config
---

# Proposal: trend-and-export-reporting

## Summary
Declares columns and explicit built-in mass-import/export/copy/delete toggles on the
`LearnerProfiles`, `Cohorts`, `ReportCards`, and `AttendanceRecords` indexes; adds a discoverable
"Import & export" nav entry pointing at the existing `DataExchangeJobs` admin page; and declares
`dle` (didactische leeftijd) and `leerrendement` as two new `GradeScale.kind` values for later LVS
data.

## Motivation
Round-1 competitor research (`compare/change-plan.md`, "Report cards, care and support" table)
carries this change against findings `12.6`, `12.2`, `12.3`, `14.7`, `L-new-1`:

- **12.6** Custom reports and exports (`compare/findings.md`): testvision, remindo, facet, cirrus,
  studytube (+14 more). learniq's own row: "partial: `src/manifest.json#Reports` (card launcher;
  CSV/Excel only via platform index-page mass export)" — the platform's generic mass-export
  ALREADY exists on every index page (`CnIndexPage`'s `showMassExport`, verified in the manifest v2
  schema's `actionToggles`), but it isn't declared explicitly on the indexes this round's findings
  care about, so it reads as an implicit default rather than an intentional capability.
- **14.7** Data import/export tooling for admins (`compare/findings.md`): studytube, docebo,
  talentlms (+9 more). learniq's row: "partial: `DataExchangeJobs` ... platform index mass
  import/export; no admin import wizard". The residual named — a guided multi-step import wizard —
  is genuinely new Vue UI (`code`), out of scope here; what this change closes is discoverability:
  today `DataExchangeJobsMenu` sits two menu levels deep with a generic "Exchange jobs" label that
  does not read as "the place to bulk import/export my school's data."
- **12.2** Group overview of LVS results and trends, **12.3** cohort/school trend reports across
  years, **L-new-1** DLE/leerrendement scales (`compare/findings.md`): both 12.2/12.3 are gated on
  LVS data this round's corpus confirms does not exist yet in learniq
  (`lvs-import-contract`, tier B, not built) — a year axis on `GroupTrendHeatmap.vue` is itself
  genuine Vue work (`code`), not attempted here (see Out of Scope). What IS additive and safe now:
  declaring the DLE/leerrendement scale vocabulary on the existing `GradeScale` schema, so the
  eventual LVS-import change has a scale to attach results to rather than inventing one alongside
  its own, larger scope.

## Affected Projects
- [x] Project: `learniq` — manifest column/toggle declarations, one new nav entry,
  `GradeScale.kind` enum extension.

## Scope

### In Scope
- `columns` declared on `LearnerProfiles`, `Cohorts`, `ReportCards`, `AttendanceRecords` (index
  pages that currently declare none, defaulting to a generic column set).
- `actionToggles: {showMassImport: true, showMassExport: true, showMassCopy: true,
  showMassDelete: true}` declared explicitly on those same four indexes — the platform default
  made an intentional, documented, self-evident capability rather than an implicit one, closing
  12.6/14.7's "partial" framing for these four specific indexes.
- A new `ImportExportToolsMenu` nav entry under the existing `GroupDataExchange` menu group,
  labelled "Import & export", routing to the existing `DataExchangeJobs` page (reusing it — no new
  page, per ADR-097 Decision 5's own "role lens, not a page" preference already applied in
  `care-and-support-index`, a sibling change this round).
- `GradeScale.kind` gains `dle` and `leerrendement` enum values (a vocabulary declaration; no
  calculation, no new schema — matches L-new-1's own framing, "declared for later LVS data").

### Out of Scope
- **A guided admin import wizard** (14.7's named residual): genuine new Vue UI, `code`-shaped,
  ADR-032 forbids mixing into this `config` spec.
- **A year axis on `GroupTrendHeatmap.vue`/`CourseQualityReport.vue`** (12.2/12.3): both are
  bespoke Vue components (`type: "custom"` pages); adding an axis is a template/computed-property
  change, `code`-shaped. Additionally, 12.2 specifically needs LVS trend data that does not exist
  in this register yet (`lvs-import-contract`, tier B) — a year axis with nothing but grade-trend
  data to show would not close the finding's own evidence gap regardless.
- **"Quick filters"** as an in-page, interactive filter-chip UI: investigated. The manifest v2
  schema has no `quickFilters`/`quickFilter` property anywhere (verified against the shared
  `@conduction/nextcloud-vue` schema); the only real preset-filter mechanism this app's manifest
  documents is `menu[].query` (a nav-level deep link, used by `care-and-support-index`'s
  `CareTeamOverviewMenu`), which does not fit an in-page, always-visible filter-chip row. Building
  one would mean new Vue UI, `code`-shaped. Deferred, not attempted here.
- **Custom, schema-specific bulk actions** beyond the platform's built-in mass
  import/export/copy/delete: no finding this round names a specific custom bulk operation (e.g. "
  bulk-assign coordinator"); the built-in toggles this change declares explicitly are the
  documented, verified mechanism, and inventing a bespoke one without a named need would be
  scope creep.
- **The relocation of `GroupDataExchange` to the Nextcloud Admin Settings page**: an existing,
  separate, unmerged proposal (`relocate-dataexchange-remove-assistant`) already plans this; this
  change adds `ImportExportToolsMenu` to the CURRENT, still-live `GroupDataExchange` group rather
  than block on or duplicate that unrelated in-flight change.

## Approach
Every addition is a declarative manifest change (`columns`, `actionToggles`, one `menu[]` entry) or
a schema enum extension (`GradeScale.kind`). No PHP, no Vue file touched.

## New Dependencies
None.

## Impact
- `src/manifest.d/people.json`: `columns`/`actionToggles` on `LearnerProfiles`/`AttendanceRecords`.
- `src/manifest.d/learning.json`: `columns`/`actionToggles` on `Cohorts`/`ReportCards`.
- `src/manifest.d/data-exchange.json`: new `ImportExportToolsMenu` nav entry.
- `lib/Settings/learniq_register.json`: `GradeScale.kind` gains `dle`/`leerrendement`.
- `tests/Unit/Settings/GradeScaleDleLeerrendementRegisterTest.php` (new): enum shape assertion.

## Cross-Project Dependencies
None.

## Risks

### Risk 1: Declaring columns changes what an existing user sees by default
**Severity:** Low — **Mitigation:** `columns` only picks a curated subset of already-existing
fields to show by default; it adds no new data and does not remove any user's ability to see full
detail on the record's own detail page. Chosen columns mirror fields these schemas' own detail
pages already surface prominently.

## Rollback Strategy
Every change here is a manifest/enum addition; reverting removes the columns/toggles/nav entry and
the two enum values with no data or route impact — no existing `GradeScale` row uses the new
`kind` values, so removing them is non-breaking.

## Open Questions
None — corpus evidence and the manifest v2 schema's own documented mechanisms are specific enough
to proceed; scope boundaries above record judgment calls made under headless operation.
