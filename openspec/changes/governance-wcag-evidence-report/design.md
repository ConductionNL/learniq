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
