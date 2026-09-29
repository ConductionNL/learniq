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
