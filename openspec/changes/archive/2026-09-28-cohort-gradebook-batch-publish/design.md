# Design: cohort-gradebook-batch-publish

## Architecture Overview

```
CohortGradebookView (route /grades/cohort/:cohortId/plan/:planId)
  CnDataMatrix grid (unchanged)
  publish panel
    scope <select> (one component | all)
    gradebookPublish.js: scopeEntries() -> distribution(), publishable()
    "Publish N marks" -> confirm -> for each entry:
        POST /apps/openregister/api/objects/{id}/transition { action: publish }
          ReportPeriodLockGuard, gradePublished, GradeRollupHandler (unchanged)
    publishReport() -> "N published", refused list
```

## Nextcloud Integration
- Frontend only: `@nextcloud/axios`, `@nextcloud/router`, Nextcloud components.
- Reads the plan's `GradeScale` (`objects/learniq/grade-scale/{gradeScaleId}`) for `passThreshold`.

## Decisions

### D1: One transition per entry, from the client
OpenRegister's transition endpoint is per object, and every guard (period lock, fraud block) and
side effect (roll-up, parent fan-out, visibility window) hangs off that one transition. Looping on
the client keeps all of it. Alternative rejected: a learniq batch endpoint, which would duplicate
the transition engine for no gain.

### D2: Sequential, not parallel
Each publish triggers a FinalGrade recompute for the learner. Parallel requests for the same learner
race on that recompute. Sequential is slower and correct.

### D3: Default scope is the first component
Publishing "all" is one choice away, but the safe default is one column: a teacher usually finishes
one test at a time.

### D4: Histogram bands follow the scale
A scale spanning ten points or fewer (the Dutch 1 to 10) gets one band per whole point. Wider
scales get five equal bands between the scale's `min` and `max`, or the observed range when the
scale has none.

### D5: No modal
The confirmation is an inline block with two buttons, so no dialog file is needed (ADR-004 modal
isolation) and screen readers stay in the page flow.

## Declarative-vs-imperative decision (ADR-031)
| Behaviour | Path | Rationale |
|---|---|---|
| Distribution of marks | frontend helper | Display arithmetic over the loaded grid; nothing is stored. An OpenRegister aggregation would need a round trip per scope change. |
| Publishing | existing declarative `publish` transition | Unchanged; fired per entry. |

## Security Considerations
No new endpoint. Each transition is authorized by OpenRegister as the signed-in teacher (GradeEntry
`update`: instructors, hr, compliance-officers, team-leads) and guarded by `ReportPeriodLockGuard`.

## NL Design System
Nextcloud components and CSS variables. Histogram bars carry their count as text, so the
spread never depends on colour or bar length alone.

## File Structure
```
src/views/CohortGradebookView.vue
src/utils/gradebookPublish.js          (new)
tests/unit-js/gradebookPublish.test.mjs (new)
l10n/en.json, l10n/nl.json (+ generated .js)
```

## Seed Data
No schema change.

## Trade-offs
Notifications stay one per grade (shaped by instant or digest preference), short of the spec's "one
per recipient per batch". Named in the proposal as a follow-up.
