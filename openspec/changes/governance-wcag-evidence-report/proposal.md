---
kind: code
---

# Show the WCAG 2.1 AA evidence on request

## Why

GLR requirement 206617 (TenderNed) asks for WCAG 2.1 level AA, demonstrated on request. Learniq publishes an accessibility statement with evaluation method, date and known limitations (`AccessibilityStatement`, `AccessibilityLimitation`, `lib/Lifecycle/AccessibilityStatementPublishGuard.php`), but it records only the failures. It cannot show, criterion by criterion, what passed, how it was tested and where the evidence is. Totara rates yes and four others partial. The row is a tender demand row, so it is built.

One row, one change.

### Matrix rows (`learniq` `openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `gov-wcag-aa` | Meet WCAG 2.1 AA and show the evidence on request. | `partial`: `partial`: the accessibility statement, its limitations and a barrier report are built; there is no per-criterion conformance record and no evidence export |

### Demand

- `gov-wcag-aa`: tender, https://www.tenderned.nl/aankondigingen/overzicht/415112

### Competitors rated yes

- `gov-wcag-aa`, totara: "read 2026-09-26: https://totara.help/docs/accessibility; Totara publishes an 'Accessibility Compliance Report (ACR) based on the Voluntary Product Accessibility Template (VPAT). This is a report which outlines our understanding of"

## What Changes

- Add an `AccessibilityCriterionResult` schema: statement, WCAG criterion, level, result (`pass`, `fail`, `not-applicable`, `not-tested`), method, evidence reference, tested on, tested by.
- Show a conformance table on the accessibility page, with limitations linked to their failing criteria.
- Add an evidence export (JSON and CSV) that anyone can request from the public statement page, containing the table and the statement fields.

## Capabilities

### New Capabilities

- `accessibility-evidence`

### Modified Capabilities

- None in delta form.

## Impact

- **Register**: new schema `AccessibilityCriterionResult` (`lib/Settings/learniq_register.json`).
- **Backend**: an extension of `AccessibilityStatementPublishGuard` and a public export route (`#[PublicPage]`, rate limited).
- **Frontend**: conformance table on `src/views/LearniqAccessibilityStatement.vue`, edit dialog in its own file.
- **Data**: the criterion list (WCAG 2.1 A and AA, 50 criteria) is seeded from a static file, not typed by hand.
