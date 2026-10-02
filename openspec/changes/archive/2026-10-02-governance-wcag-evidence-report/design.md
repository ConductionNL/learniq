# Design: show the WCAG 2.1 AA evidence on request

## Context

At development `acdf1dd5`:

- `lib/Settings/learniq_register.json` `AccessibilityStatement` (`evaluationMethod`, `evaluationDate`, `researchReportUrl`, `standardApplied`, `feedbackContact`), `AccessibilityLimitation` (`wcagCriterion`, `severity`, `affectedSurface`, `justification`, `plannedFixDate`), `AccessibilityFeedback`.
- `lib/Lifecycle/AccessibilityStatementPublishGuard.php:134` allows publish only with evaluation evidence and evidence-backed limitations.
- `openspec/specs/accessibility-conformance/spec.md` requires automated scans in the Playwright suite as citable evidence; the evidence reference can point at those runs.
- `src/views/LearniqAccessibilityStatement.vue` renders the statement and limitations.

## Goals / Non-Goals

**Goals**
- Conformance is shown per criterion with its evidence, and can be handed over on request.

**Non-Goals**
- Running the tests from the app.
- WCAG 2.2 or AAA criteria.

## Decisions

### D1: Records for all criteria, not only failures

A list of failures cannot show that the rest was checked. An explicit `not-tested` keeps the gap honest.

### D2: Public export without personal data

The export holds criteria, results and references only; tester names are omitted from the public file and kept in the register.


### D3: The public page is server-rendered and serves the latest published statement

There was no signed-out statement page: `LearniqAccessibilityStatement.vue` sits behind the Nextcloud login. So the change adds `GET /apps/learniq/public/accessibility-statement`, a `#[PublicPage]` guest template with the statement fields, the summary, the table and the two downloads, and `GET /apps/learniq/api/accessibility/evidence?format=json|csv`. Both are `#[AnonRateLimit]` and read only a statement whose lifecycle is `published`. With `?statement=<uuid>` they serve that published statement; without it, the published statement with the latest evaluation date. The in-app page links to both, so the school can put the public link on its website. The in-app page reads its table from the same evidence endpoint, so the page and the download cannot disagree. The in-app page shows the published statement, as it did before this change, so results are recorded against that statement there; a draft statement's results are recorded in OpenRegister until the app has a draft view. A result saved after publishing is not re-checked by the guard: the next publish is.

### D4: The criterion list ships as a static file

`lib/Service/Accessibility/wcag21-a-aa-criteria.json` holds the 50 criteria (30 at A, 20 at AA) with their number, level and W3C title. A criterion with no `AccessibilityCriterionResult` row is shown and exported as `not-tested`, so nothing is seeded into the register per statement. The W3C titles stay in English: they are the names the standard and auditors use.

### D5: A failure is covered by a linked, unfixed limitation for the same criterion

`AccessibilityCriterionResult.limitationId` names the limitation. `AccessibilityStatementPublishGuard` denies publishing when a `fail` has no `limitationId`, or the limitation is for another criterion, belongs to another statement, or is `fixed`. The denial names the criteria. Criterion numbers are compared on the leading number, because a limitation stores free text such as `2.1.1 Keyboard`. The guard also reads OpenRegister's entities through `jsonSerialize()`: the existing fully-compliant check indexed them as arrays, which only array-returning test doubles satisfied.

### D6: What the export leaves out

The export holds the criterion, level, title, result, method, evidence reference, test date and the linked limitation's description, plus the statement's public fields. `testedBy` and the statement's `approvedBy` are never in it. The CSV holds one row per criterion; the statement fields are in the JSON and on the page.
