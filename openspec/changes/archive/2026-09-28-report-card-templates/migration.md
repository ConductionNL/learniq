# Migration: report-card-templates

## Current State
`lib/Settings/learniq_register.json` declares `ReportCard` (no `templateId`) and `Cohort` (no
`reportCardTemplateId`); no `ReportCardTemplate` schema exists. `ReportCardComposer` always
populates the fixed `subjectGrades[]`/`attendanceSummary`/`mentorComment` shape.
`ReportCardPdfDelegationService` always sends `templateSlug: 'report-card'`.

## Target State
`learniq_register.json` gains the `ReportCardTemplate` schema; `ReportCard.templateId` (nullable
`$ref: ReportCardTemplate`) and `Cohort.reportCardTemplateId` (nullable `$ref:
ReportCardTemplate`) are added as new nullable properties on existing schemas.

## Migration Class
Not applicable — this app has no Doctrine database tables of its own (ADR: "thin client — Scholiq
owns no database tables"). OpenRegister's own `ConfigurationService`/schema-apply path (invoked on
app enable/upgrade, same as every prior schema addition in this register) provisions the new
`ReportCardTemplate` object table and reconciles the two new nullable columns on `ReportCard`/
`Cohort` from the JSON schema diff. No `lib/Migration/VersionXXXXXXXXXX.php` file is added.

## Migration Steps
1. Merge the `ReportCardTemplate` schema block and the two new nullable properties into
   `lib/Settings/learniq_register.json`.
2. On next app enable/upgrade, OpenRegister's schema-apply step creates the `ReportCardTemplate`
   object storage and adds the two nullable columns to the existing `ReportCard`/`Cohort` object
   tables (both a no-op for every existing row: nullable, no default required).
3. Seed data (`learniq_mock_register.json`) is loaded on fresh install only, per this app's
   existing `DemoDataService` convention — it does not run against a populated production
   instance.

## Data Impact
Zero rows change value on existing `ReportCard`/`Cohort` objects (both new properties are nullable
with no default, per OpenRegister's additive-schema-change convention already used for every
`$ref`-nullable property in this register, e.g. `ReportCard.learnerRef`, `Cohort.ncGroupId`). No
data loss, no transformation. Safe on a live, populated instance.

## Rollback Procedure
Revert the `learniq_register.json` patch (remove the `ReportCardTemplate` schema block and the two
new properties). Any `ReportCardTemplate` objects already created become orphaned register data
(the same rollback shape as any other schema removal in this register) but do not block the
`ReportCard`/`Cohort` schemas from continuing to validate, since removing a nullable property that
was `null` on every existing row is non-breaking.

## Validation
- `php -l lib/Settings/learniq_register.json` is not applicable (JSON, not PHP) — validate instead
  with `python3 -m json.tool lib/Settings/learniq_register.json > /dev/null` (well-formed JSON) and
  the existing `*RegisterTest` PHPUnit convention (`ReportCardTemplateRegisterTest` — new — asserts
  the schema block parses and its `x-openregister` block is well-formed, mirroring
  `ReportCardComposerRegisterTest`'s existing pattern for `ReportCard`).
- `ReportCardComposerTest`'s new fallback scenario asserts an untemplated `Cohort` still composes
  the pre-change fixed shape (see test-plan.md).
