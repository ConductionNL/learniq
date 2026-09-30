# Proposal: the register ships no example rows

## Why

Found testing a primary school end to end on a clean install (2026-09-30). The admin loaded only the primary school set, yet the app also held three compliance courses (NIS2, AVG refresher, BIO2 awareness), a cohort "All Employees 2026", two programmes, learners Anna, Bram and Admin, and three enrolments.

These twelve rows sit in `lib/Settings/learniq_register.json` under `components.objects`. The register is imported when the app is enabled, before the setup wizard asks anything, so every install gets them: a primary school, a college and a company alike, and also an install whose admin picks "None". They are company example data, which the corporate example set already covers. The example-sets spec already moved the register's other example seeds into the sets; these twelve were missed.

## What changes

- `components.objects` keeps only the `AVG` regulation, which the sets reference by code.
- `info.version` moves up one patch version.
- A register test fails when the register seeds anything other than a reference row.

## Not changed

- An existing install keeps the rows it already imported; the importer does not delete. An admin removes them by hand or with `occ openregister:objects:purge`.
