# Tasks: take up an absence flag and report it from its page

## 1. Page

- [x] 1.1 `src/manifest.d/people.json` AttendanceFlagDetail: add `"lifecycleActions": {"field": "lifecycle"}`; keep `readOnly: true`. Update the page `_note`. Verify: `npm run check:manifest`; the page header lists "Take up" on an open flag.
  > DONE: `lifecycleActions: {field: lifecycle}` beside `readOnly: true`, `_note` updated; `npm run check:manifest` passes. The header itself is seen in the browser with 2.2.
- [x] 1.2 Add the four transition labels (design table) as `label` on each transition in `lib/Settings/learniq_register.json` `attendance-flag` `x-openregister-lifecycle.transitions`, and the Dutch strings to `l10n/nl.json`. Verify: `npm run check:l10n`, `npm run check:schema-l10n` if present.
  > DONE: `label` on the four transitions (Take up, Mark as reported, Close, Record the municipality's answer); Dutch in `l10n/nl.json` and `l10n/nl.js` (Oppakken, Gemeld bij leerplicht, Reactie gemeente vastleggen; "Close" already reads "Sluiten" and was left). `check:l10n` at the baseline; `check:schema-l10n` does not count transition labels.
- [ ] 1.3 `recordMunicipalityFeedback.inputs`: `[{field: "municipalityFeedback.masRoute", required: true}, {field: "municipalityFeedback.note"}]`. Check `CnTransitionInputDialog` draws a dotted field; if not, leave this task open and reference the nextcloud-vue issue. Verify: the dialog shows two fields.
  > Open: the installed nextcloud-vue does not resolve a dotted `field`. Declared instead as one object input narrowed to its sub-fields, `{field: municipalityFeedback, fields: [masRoute, note]}`, which draws exactly those two fields (D3). `masRoute` cannot be marked required that way without adding `required: [masRoute]` to the schema object, which would also bind every save; that needs dotted-field support in nextcloud-vue.
- [ ] 1.4 `flag-related`: confirm the data-exchange job shows with `exchangeStatus`; if the related widget cannot show that field, add an `object-list` widget on integriq's job filtered by `@object.dataExchangeJobId` with the status column.
  > Open (2026-11-14): `AttendanceFlagDetail` no longer has a `flag-related` widget; development rebuilt the page as tabs (Overview, Registrations, Record history), so the job is not shown at all. The fallback filter on `@object.dataExchangeJobId` would match on `id`, an entity column OpenRegister does not filter on; the job's own link back is `recordIds: [<flag id>]`, an array, and whether an `object-list` filter matches an array member needs a live instance. Decide the filter there, then add the widget to the Overview tab.
- [x] 1.5 AttendanceFlags index: add `lifecycle` as a column and a facet filter.
  > DONE: `lifecycle` was already a column; the property is now `facetable: true` (schema 0.2.5), which is where the index page reads its facets from.

## 2. Tests

- [x] 2.1 PHPUnit: `AttendanceFlagReportGuardTest` keeps passing; add a test that the refusal reason text is the one the page shows.
  > DONE: `AttendanceFlagReportGuardTest::testTheRefusalIsTheSentenceThePageShows` pins the sentence word for word and checks it names no job id or status.
- [ ] 2.2 Playwright `tests/e2e/attendance-flag-handling.spec.ts`: as an instructor open a seeded open flag, take it up, see `in-handling`; with the seeded succeeded job mark it reported; as a compliance officer record a MAS route. Tag the four requirement scenarios with `@e2e`.

## 3. Close out

- [ ] 3.1 Set row `att-report-absence-to-authority` to `built`, `learniq: yes`, evidence with the page and the e2e, `reachedOn: "/attendance/flags/:id > Actions"`; archive this change.
