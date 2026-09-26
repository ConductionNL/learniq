---
kind: config
---

# Proposal: report-card-templates

## Summary
Add a `ReportCardTemplate` schema (index+detail) that lets a school compose its own report card
out of typed sections (grades, LVS results, attendance, social-emotional, narrative, pupil voice,
portfolio), each with a scale drawn from a shared scale library (steps, dots, smileys, grades 1-10,
letters, Cito level, text), assign one template per group, and map an imported test kind to a
section per group. `ReportCard` gains `templateId`, `ReportCardComposer` composes exactly the
sections the assigned template declares, and `ReportCardPdfDelegationService` sends the template's
own slug to docudesk instead of the fixed `'report-card'` constant it posts today.

## Motivation
Round-1 competitor research (`concurrentie-analyse/procest/_round2/compare/change-plan.md`,
learniq section, "Report cards, care and support") carries this change against six findings —
`14.3`, `7.4`, `7.5`, `7.8`, `7.7`, `7.3` — plus fifteen supplementary rows drawn from three Dutch
report-card specialists (`R-new-1` through `R-new-15`, `compare/proposed-rows.md`). learniq's own
report card is functionally complete (compose, review, finalise, publish, notify) but structurally
fixed: one `mentorComment` string and a flat `subjectGrades[]` array, with no school-chosen scale,
no narrative or pupil-voice section, and no portfolio composition. Every named competitor closes
this gap:

- **ParnasSys** (`parnassys/round1/documented-column.md` row 7.4): "per leerjaar instellen wat de
  uiterlijke kenmerken van het rapport zijn" — a per-leerjaar (i.e. per-group) template setting.
- **Easyrapport** (`compare/findings.md` row `14.3`/`7.4`, `compare/proposed-rows.md` `R-new-1`,
  `R-new-2`, `R-new-3`): a full scale library ("Trapjes, Bolletjes, Smileys, Cijfers, Tekst,
  Cito-niveau, Percentages"), a report built "in de huisstijl en visie van uw school", and
  per-group mapping of an imported methode/LVS test to a report section ("De leerkracht bepaalt
  welke toets bij welk rapportonderdeel hoort").
- **MijnRapportfolio** (`R-new-1`, `R-new-6`, `R-new-7`, `R-new-8`, `R-new-9`): subjects and
  assessment forms chosen per school, a personal profile page ("Dit ben ik"), personal learning
  goals, and child/teacher side-by-side questionnaires.
- **IEP / Bureau ICE** (`R-new-6`, `R-new-7`, `R-new-13`): growth graphs and spider diagrams
  ("groeigrafieken en spindiagrammen"), tracked learning goals, and reading guides per grade band.
- **gibbon** (`compare/findings.md` row `14.3`): a Template Builder
  (`gibbonReportTemplate`/`Section`/`Font`).

learniq's own baseline capture (`learniq-baseline/report-card-anatomy.md`) confirms the mechanism
around the content is otherwise sound: `ReportPeriods`, `ReportCards` and
`RapportvergaderingReviewView` all work; only `ReportCardDetail` renders blank, and that is a
confirmed nextcloud-vue related-widget resolver defect (PascalCase `$ref` titles are requested
against OpenRegister's kebab-case object-API slugs) tracked in another lane
(`slugify-ref-relation-resolver`), not a register or template problem — this change does not work
around it.

## Affected Projects
- [x] Project: `learniq` — `ReportCardTemplate` schema, `ReportCard.templateId`,
  `ReportCardComposer` section-driven composition, `ReportCardPdfDelegationService` template-slug
  passthrough, `src/manifest.json` index+detail pages.

## Scope

### In Scope
- A `ReportCardTemplate` schema: named, per-tenant, with a `sections[]` array (each section has a
  `kind` — grades, lvs-results, attendance, social-emotional, narrative, pupil-voice, portfolio —
  a `scale` drawn from a shared enum-shaped scale library, an `order`, and a `label`), and a
  `testKindSectionMap[]` for per-group mapping of an imported test kind (methodetoets/LVS) to a
  section.
- `ReportCardTemplate` assignment per group: a `groupTemplateAssignments[]` collection (or an index
  reachable from `Cohort`) resolving which template a given `Cohort`/leerjaar uses for a given
  `ReportPeriod`.
- `ReportCard.templateId` (`$ref: ReportCardTemplate`), set at compose time from the learner's
  cohort's assignment.
- `ReportCardComposer` reads the assigned template's `sections[]` and populates exactly those kinds
  (existing `subjectGrades`/`attendanceSummary`/`mentorComment` map to `grades`/`attendance`/
  `narrative`; `pupil-voice`, `social-emotional`, `lvs-results` and `portfolio` sections are stored
  generically pending their own data sources — LVS import in particular depends on the tier-B
  `lvs-import-contract` change and is explicitly out of scope here beyond the mapping shape).
  Composing without an assigned template falls back to today's fixed shape (`subjectGrades` +
  `attendanceSummary` + `mentorComment`) so existing periods keep composing unchanged.
- `ReportCardPdfDelegationService` reads `ReportCardTemplate.slug` (or a `docudeskTemplateSlug`
  override) for the report card's assigned template and sends it as `templateSlug` in the docudesk
  payload, replacing the hardcoded `self::TEMPLATE_SLUG = 'report-card'` constant. A report card
  with no assigned template still sends `'report-card'` (unchanged default).
- A `ReportCardTemplateDetail`/`ReportCardTemplateIndex` manifest page pair under the existing
  Configuration domain (clustered with `CourseTemplateDetail`'s pattern).

### Out of Scope
- Rendering the PDF itself in the school's house style (logo, colours, layout) — that is
  `filinq-configurable-report-templates` (filinq, a sibling repo change), which this change's
  `templateSlug` passthrough is the contract for. This change only ships the slug the delegation
  service sends; filinq owns what docudesk does with it.
- LVS test-result ingestion (`lvs-import-contract`, tier B, depends on the `uwlr` contract) — the
  `lvs-results` section kind and `testKindSectionMap` are declared now so a future LVS import can
  slot in without a further schema change, but no LVS data source exists yet to populate them.
- E-signature or pupil-authored moderated text as a live editing workflow (`R-new-4`, `R-new-5`,
  `R-new-9` — young-pupil login, teacher moderation UI, side-by-side questionnaires): the
  `pupil-voice` section kind stores free text a mentor edits the same way `mentorComment` already
  works today; a dedicated pupil-facing authoring surface is a future change.
- Bulk ZIP export per class and a leavers' archive (`R-new-10`, `R-new-11`) — carried in
  `trend-and-export-reporting` (a sibling change in this same lane's plan), not here.
- Print-shop-quality PDF output (`R-new-12`) and admin impersonation (`R-new-15`) — unrelated to
  template richness.
- The `slugify-ref-relation-resolver` nextcloud-vue fix that currently blanks `ReportCardDetail` —
  tracked and owned by another lane; this change does not add a workaround for it.

## Approach
Add `ReportCardTemplate` as a new OpenRegister schema (config-only: JSON register + manifest
pages), extend `ReportCard` with one nullable `$ref` property, and patch two existing PHP classes
(`ReportCardComposer`, `ReportCardPdfDelegationService`) to read the template instead of assuming a
fixed shape. No new lifecycle machinery beyond `ReportCardTemplate`'s own `active`/`archived`
states (mirrors `CourseTemplateDetail`'s existing pattern). Scale library and section kinds are
enums declared on the schema itself, not a separate register object, per ADR-011 (check for
existing platform primitives first — none of OpenRegister's declarative blocks model a
report-section scale, so this is genuinely new schema surface, not a duplicate).

## New Dependencies
None.

## Impact
- `lib/Settings/learniq_register.json`: new `ReportCardTemplate` schema; `ReportCard` gains
  `templateId`.
- `lib/Listener/ReportCardComposer.php`: reads the assigned template's `sections[]` to decide what
  to populate; falls back to the current fixed shape when no template is assigned.
- `lib/Service/ReportCardPdfDelegationService.php`: resolves and sends the template's slug instead
  of the `TEMPLATE_SLUG` constant.
- `src/manifest.json` / `src/manifest.d/learning.json`: `ReportCardTemplate` index+detail pages.
- `tests/Unit/Listener/ReportCardComposerTest.php`,
  `tests/Unit/Service/ReportCardPdfDelegationServiceTest.php`: new coverage for template-driven
  composition and slug passthrough.

## Cross-Project Dependencies
- **nextcloud-vue** `slugify-ref-relation-resolver` (another lane, code, S): must land before
  `ReportCardDetail` (and this change's `ReportCardTemplateDetail`) render their related-object
  panels; this change does not depend on it functionally (the register and composer work
  regardless), only the detail page's visual completeness does.
- **filinq** `filinq-configurable-report-templates` (sibling repo, code, M): consumes the
  `templateSlug` this change starts sending; filinq's docudesk-side template rendering is not part
  of this change.
- **learniq** `lvs-import-contract` (same repo, tier B, not yet built): the eventual data source for
  the `lvs-results` section kind.

## Risks

### Risk 1: Composer regression for existing (un-templated) report periods
**Severity:** Medium — **Mitigation:** `ReportCardComposer` keeps its current fixed-shape
composition path as the default when a `Cohort` has no `groupTemplateAssignments[]` entry for the
period's `ReportPeriod`; a new PHPUnit case asserts an untemplated period composes identically to
today's behaviour.

### Risk 2: Section-kind sprawl with no data source (lvs-results, social-emotional)
**Severity:** Low — **Mitigation:** these two kinds are stored as free-form/mentor-edited content
(same shape as `mentorComment`) until their real data sources (`lvs-import-contract`; no
social-emotional data source proposed this round) land; declared now so the template shape does not
need to change again when they do.

## Rollback Strategy
`ReportCardTemplate` is additive (new schema, new nullable `ReportCard.templateId`); disabling or
removing it leaves every existing `ReportCard`/`ReportPeriod` composing exactly as before, since the
composer's template-driven path only activates when `templateId`/an assignment is present. Revert
the two PHP patches and the manifest pages to fully roll back.

## Open Questions
None — the corpus (`change-plan.md`, `findings.md`, `proposed-rows.md`,
`learniq-baseline/report-card-anatomy.md`) is specific enough to proceed; scope boundaries above
record the judgment calls made under headless operation.
