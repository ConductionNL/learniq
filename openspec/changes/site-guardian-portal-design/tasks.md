# Tasks: site-guardian-portal-design

Specs only so far. Build starts after Ruben approves the specs and portaliq's `site-mijn-omgeving-components` names the keys it accepts.

- [ ] **T1**: register: `GradeEntry.courseName`, and a server stamp that fills it from the course on create and update
  - PHPUnit for the stamp; `npm run check:register`
- [ ] **T1b**: register: `Enrolment.cohortName`, stamped on create, update and cohort rename; projected in `parentGroupMemberships`
  - PHPUnit for the stamp; `npm run check:register`
- [ ] **T2**: parent grade projection gains `courseName`, `methodName`, `methodBlock`, `weight`
  - PHPUnit `PortalContributionProviderTest`
- [ ] **T3**: `parentInbox` (`kind: inbox`) over `report-card-parent-notification` and `grade-notification`, through the child join, `visibleFrom` passed
  - PHPUnit `PortalContributionProviderTest`, `ParentRecordPageTest`
- [ ] **T4**: `parentOverview` page (`home: true`, `records` with `subtitleLookup`): tasks, four `cta` tiles (`withRecord`, `{title}`), week, figures, latest report, newest grades, newest messages with `recordField`; `recordField: learnerRef` on `createExcuseRequest`
  - PHPUnit `ParentRecordPageTest`
- [ ] **T5**: `group` and `perRecord` on the parent pages; the collection pages keep id and route and get `menu: false`
  - PHPUnit `ParentRecordPageTest`
- [ ] **T6**: per-child "Afwezigheid" and "Oudergesprekken" record pages; `widget: choices` and `dateChoices` and a `confirmation` on `createExcuseRequest`
  - PHPUnit `PortalContributionProviderTest`
- [ ] **T7**: Dutch for every new label; translator covers task, quick action and menu group labels
  - PHPUnit `PortalLabelTranslatorTest`; `npm run check:l10n`
- [ ] **T8**: `ExamplePortalProvisioner` writes the signed-out home on `created` only
  - PHPUnit `ExamplePortalProvisionerTest`
- [ ] **T9**: po example set: Sami Hulstkamp, groep 3, with attendance, a summary and a grade
  - `python3 scripts/example-sets/po.py --check`, PHPUnit `ExampleSetDescriptorContractTest`
- [ ] **T10**: e2e: the guardian switches child, reads the task, reports Vera sick from the overview
  - `tests/e2e/po-parent-flows.spec.ts`

## Follow-ups (not in this change)

- The portaliq route for "Bericht sturen aan de juf", once lane pq names it.
- "Ziek", "Dokter of tandarts" and "Een andere reden" as cards (`choiceOptions`, `otherLabel`), once Ruben decides.

- `school-trip-permission`: a permission slip the school sends and the guardian signs.
- A decision on showing the teacher's comment on a grade to guardians.
