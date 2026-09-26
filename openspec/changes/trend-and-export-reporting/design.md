# Design: trend-and-export-reporting

## Architecture Overview
Four independent, declarative pieces:

1. `columns` + `actionToggles` on 4 existing index pages (`LearnerProfiles`, `Cohorts`,
   `ReportCards`, `AttendanceRecords`) — pure manifest config, verified against the manifest v2
   schema's own documented `page.config.columns`/`page.config.actionToggles.showMass*` properties.
2. One new nav entry (`ImportExportToolsMenu`) reusing the existing `DataExchangeJobs` page.
3. `GradeScale.kind` gains two enum values.
4. Nothing else — the Out of Scope items in proposal.md (import wizard, trend-chart year axis,
   quick-filter chips) are genuine Vue/`code` work or depend on data this register does not have
   yet, and are not attempted here.

## API Design
Not applicable — no endpoint added or changed.

## Database Changes
Additive `GradeScale.kind` enum values only; see migration.md.

## Nextcloud Integration
- Controllers/Services/Mappers: none added or changed.
- Events/Hooks: none.

## Security Considerations
No new read/write surface: `actionToggles` only makes an ALREADY-DEFAULT-ON platform capability
(`CnIndexPage`'s built-in mass import/export/copy/delete) explicit; it does not change who may use
it (governed by the schema's own existing RBAC, unchanged here). `ImportExportToolsMenu` routes to
a page every `admin`/`administration-manager` user (the existing `GroupDataExchange` group's own
gate) could already reach via `DataExchangeJobsMenu`.

## NL Design System
No new UI surface — reuses `CnIndexPage`'s existing columns/mass-action rendering and the existing
`DataExchangeJobs` page.

## File Structure
```
src/
  manifest.d/
    people.json        (MODIFIED — columns/actionToggles on LearnerProfiles, AttendanceRecords)
    learning.json       (MODIFIED — columns/actionToggles on Cohorts, ReportCards)
    data-exchange.json  (MODIFIED — new ImportExportToolsMenu nav entry)
lib/
  Settings/
    learniq_register.json (MODIFIED — GradeScale.kind gains dle/leerrendement)
tests/
  Unit/
    Settings/GradeScaleDleLeerrendementRegisterTest.php (NEW)
```

## Declarative-vs-imperative decision (ADR-031)
| Behaviour | Path chosen | Rationale |
|---|---|---|
| Index columns/mass-action toggles | Declarative (manifest `config.columns`/`config.actionToggles`) | Both are documented, existing `page.config` properties — no new query or component logic. |
| Import & export nav entry | Declarative (`menu[]` entry, existing route) | Reuses an existing page; no new page, no new logic. |
| `GradeScale.kind` new values | Declarative (JSON Schema `enum`) | Plain enum extension, no calculation attached (explicitly a vocabulary-only declaration per L-new-1's own framing). |

## Seed Data
Not applicable — no new schema; the two new `GradeScale.kind` enum values need no seed object of
their own (no `GradeScale` mock rows currently use them, and none is required to demonstrate a
bare enum addition — `GradeScaleDleLeerrendementRegisterTest` covers the shape directly).

## Trade-offs
Considered adding a bespoke "Reports" landing page composing the four indexes' export actions in
one place. Rejected: that is a new custom page (`code`-shaped, and a strong candidate for the
duplicate-index/role-lens concern ADR-097 Decision 5 names) where declaring the columns/toggles
directly on each existing index, plus one nav entry into the existing admin tooling, achieves the
same discoverability without a new page or component.

## Open Questions
None outstanding.
