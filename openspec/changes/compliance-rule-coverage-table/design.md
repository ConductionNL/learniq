# Design: read every rule's coverage next to each other on one page

## Context

At development `acdf1dd5`:

- `lib/Service/ComplianceRollupService.php:81` `byDepartment`; `:165` `regulationFigures` returns `obligations` and `covered` for one learner across regulations; `:222` `isCovered` asks the external training service.
- `lib/Settings/learniq_register.json` `Regulation` has `ragRedThreshold` and `ragAmberThreshold`.
- `src/views/LearniqCompliance.vue` mounts `DepartmentComplianceWidget` in a `widget-department-compliance` slot.

## Goals / Non-Goals

**Goals**
- One table answers which rules are covered and which are not.

**Non-Goals**
- Per-learner drill-down (exists on the regulation page).
- Exports.

## Decisions

### D1: Reuse the learner loop

`byRegulation` iterates the same learner set and calls the same `isCovered`, so the table can never disagree with the department roll-up.

### D2: RAG from the regulation

Each regulation carries its own thresholds, so the table uses them and invents none.

### D3: Route, filter and order

`GET /api/compliance/coverage-by-regulation?department=<path>` sits beside the department roll-up and asks the same `compliance.department-rollup` action. The department filter keeps a learner whose department, or one of its parent levels, is the chosen path, the same levels the roll-up groups by. Rows come back by name; the widget sorts by percentage, with a rule nobody is in scope for always last. A row links to the regulation page by its slug, the route that page uses. A learner exempt from a rule is counted as excused, not in scope, as in the roll-up. The figures live in `RegulationCoverageService`, which takes the regulations, learners and exemptions from `ComplianceRollupService::population()` and asks its `isCovered()`: the same sources as the roll-up, kept in one class each so neither grows past the complexity limit.

### D4: The regulation assignment answers 404 for an unknown id

While adding the route, `ComplianceRollupController::assignRegulation` let `ObjectService::find()`'s exception for an unknown id escape as a 500. It now answers 404, with a test.
