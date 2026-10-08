# Tasks: take up an absence flag and report it from its page

## 1. Page

- [ ] 1.1 `src/manifest.d/people.json` AttendanceFlagDetail: add `"lifecycleActions": {"field": "lifecycle"}`; keep `readOnly: true`. Update the page `_note`. Verify: `npm run check:manifest`; the page header lists "Take up" on an open flag.
- [ ] 1.2 Add the four transition labels (design table) as `label` on each transition in `lib/Settings/learniq_register.json` `attendance-flag` `x-openregister-lifecycle.transitions`, and the Dutch strings to `l10n/nl.json`. Verify: `npm run check:l10n`, `npm run check:schema-l10n` if present.
- [ ] 1.3 `recordMunicipalityFeedback.inputs`: `[{field: "municipalityFeedback.masRoute", required: true}, {field: "municipalityFeedback.note"}]`. Check `CnTransitionInputDialog` draws a dotted field; if not, leave this task open and reference the nextcloud-vue issue. Verify: the dialog shows two fields.
- [ ] 1.4 `flag-related`: confirm the data-exchange job shows with `exchangeStatus`; if the related widget cannot show that field, add an `object-list` widget on integriq's job filtered by `@object.dataExchangeJobId` with the status column.
- [ ] 1.5 AttendanceFlags index: add `lifecycle` as a column and a facet filter.

## 2. Tests

- [ ] 2.1 PHPUnit: `AttendanceFlagReportGuardTest` keeps passing; add a test that the refusal reason text is the one the page shows.
- [ ] 2.2 Playwright `tests/e2e/attendance-flag-handling.spec.ts`: as an instructor open a seeded open flag, take it up, see `in-handling`; with the seeded succeeded job mark it reported; as a compliance officer record a MAS route. Tag the four requirement scenarios with `@e2e`.

## 3. Close out

- [ ] 3.1 Set row `att-report-absence-to-authority` to `built`, `learniq: yes`, evidence with the page and the e2e, `reachedOn: "/attendance/flags/:id > Actions"`; archive this change.
