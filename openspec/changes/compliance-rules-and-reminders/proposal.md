---
kind: code
---

# Proposal: compliance-rules-and-reminders

## Summary

Seven compliance rows are built in the register and the services, but no requirement says them. `compliance-audit` covers attestations, the evidence log and the audit pack. `compliance-rule-coverage` covers the per rule table. Neither says what a rule holds, how a course is bound to it, how coverage rolls up through departments, or who is reminded when. This change writes those down. One of the seven, the renewal cycle, is stored but read by no code: it gets tasks to make it count.

## The rows this covers

Source: learniq `openspec/parity/capabilities.json`.

| row | capability | built |
|---|---|---|
| `comp-regulations-registered` | Register the rules that need mandatory training. | `Regulation` schema with slug, name, audience and lifecycle |
| `comp-own-rag-thresholds` | Set your own red and amber limits for a rule's coverage. | `Regulation.ragRedThreshold`, `.ragAmberThreshold`, read by `RegulationCoverageService` and the `ragStatus` calculation |
| `comp-renewal-cycle-months` | Say how often a rule needs retraining, in months. | `Regulation.renewalCycleMonths` is stored; no code reads it |
| `comp-course-bound-to-rule` | Mark a course as the mandatory training for one rule. | `Course.regulationSlug`, read by `RegulationAssignmentService` |
| `comp-roll-up-by-department` | Roll compliance status up from team to department to directorate. | `ComplianceRollupService::byDepartment()`, `GET /api/compliance/departments` |
| `comp-learner-reminded-before-due` | Remind the learner three days before a mandatory course is due. | `Enrolment` notification `dueReminder` |
| `comp-manager-told-when-overdue` | Tell a line manager when someone on their team runs past the deadline. | `Enrolment` notification `overdue` |

## What changes

1. ADDED requirements on `compliance-rule-coverage` for the seven rows.
2. The renewal cycle takes effect: a certificate issued for a course bound to a rule, where the course sets no validity of its own, expires after the rule's renewal cycle.
3. `@spec` tags on the services and the register entries that carry each requirement.

## Screens

The rule list and the rule page draw all of it: see `screens.md`.

## Out of scope

- Assignment from HR data, which `compliance-assign-from-hr-roles` owns.
- The audit pack and the evidence log, which `compliance-audit` owns.
