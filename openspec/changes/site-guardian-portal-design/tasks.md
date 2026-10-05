# Tasks: site-guardian-portal-design

Built in waves. A key is declared only once portaliq development keeps it; portaliq drops an unknown key without a word.

- [x] **T1**: register: `GradeEntry.courseName`, and a server stamp that fills it from the course on create and update
  - PHPUnit for the stamp; `npm run check:register`
- [x] **T1b**: register: `Enrolment.cohortName`, stamped on create, update and cohort rename
  - PHPUnit `ReadableCopyStampTest`, `CohortNameCascadeTest`, `BackfillReadableCopiesTest`; `npm run check:register`
- [x] **T1c**: `parentGroupMemberships` projects `cohortName` (with the switcher, T4)
  - PHPUnit `PortalContributionProviderTest`
  - done by `parent-groups-read-by-name` (the collection projects `cohortName`); the switcher's subline is `records.subtitleLookup`, which waits on portaliq (T4b)
- [x] **T2**: parent grade projection gains `courseName`, `methodName`, `methodBlock`, `weight`
  - PHPUnit `PortalContributionProviderTest`
- [x] **T3**: `parentInbox` (`kind: inbox`) over `report-card-parent-notification`, through the child join; the notice carries a stamped `subject` ("Het rapport van Vera staat klaar")
  - PHPUnit `PortalContributionProviderTest`, `ReportCardPublishHandlerTest`
  - `grade-notification` stays out: its `visibleFrom` can lie in the future and portaliq has no way yet to hide a row until a date. Requested from lane L2.
- [x] **T4a**: `parentOverview` with today's keys: `home: true`, `records` over the children, `group`, the `tasks` block, two `cta` tiles on actions, the calendar, the figures, the child's absence reports and grades (`recordField`), the inbox
  - PHPUnit `GuardianSitePagesTest`; run through portaliq's own normalisers (development 69de37c): nothing dropped
- [ ] **T4b** (waits for portaliq wave 6): `records.subtitleLookup` with the group name, `cta` with `page`/`route`/`withRecord`/`{title}` (grades tile, message tile, child preset), `calendar` `range: week`, `limit` and `sort` on the collection blocks, `inbox` with `recordField`, `recordField: learnerRef` on `createExcuseRequest`
- [x] **T5**: `group` and `perRecord` on the parent pages; the collection pages keep id and route and get `menu: false`
  - PHPUnit `GuardianSitePagesTest`, `ParentRecordPageTest`
- [x] **T6a**: per-child "Afwezigheid" and "Oudergesprekken" record pages; `widget: choices` (`choiceOptions`, `otherLabel`) and `dateChoices` on `createExcuseRequest`, `requiredMessage` on the last day
  - PHPUnit `GuardianSitePagesTest`
- [ ] **T6b** (waits for portaliq): `confirmation` on `createExcuseRequest` (REQ-SMF-022 is not on portaliq development yet; `successMessage` stays)
- [x] **T7**: Dutch for every new label; the translator covers `group`, `otherLabel` and `requiredMessage`
  - PHPUnit `PortalLabelTranslatorTest`; `npm run check:l10n`
- [x] **T8**: `ExamplePortalProvisioner` writes the signed-out home
  - PHPUnit `ExamplePortalProvisionerTest`
  - done by `example-portal-declares-its-site`: the home is the board's (hero, Direct regelen, news, Mijn Wilgenboom card, Deze maand), declared in `lib/Settings/portals/po.json`, written when the portal has no page at `/` (also on an existing portal, never over an existing page)
- [x] **T9**: po example set: Sami Hulstkamp, groep 3, with attendance, a summary and a grade
  - `python3 scripts/example-sets/po.py --check`, PHPUnit `ExampleSetDescriptorContractTest`
- [ ] **T10**: e2e: the guardian switches child, reads the task, reports Vera sick from the overview
  - `tests/e2e/po-parent-flows.spec.ts`; the board checks are in `tests/e2e/portal-design/wilgenboom.spec.ts`
- [x] **T11**: the overview and the absence page follow the boards (greeting, highlight, cards, news, tiles, rows) through lane L2's block contract
  - PHPUnit `GuardianSitePagesTest`

## Follow-ups (not in this change)

- The portaliq route for "Bericht sturen aan de juf", once lane pq names it.

- `school-trip-permission`: a permission slip the school sends and the guardian signs.
- A decision on showing the teacher's comment on a grade to guardians.
