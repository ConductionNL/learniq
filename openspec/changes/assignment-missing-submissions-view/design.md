# Design: assignment-missing-submissions-view

## Architecture Overview

```
AssignmentDetail (manifest detail page)
  config.bodyWidgets[asn-hand-in] -> registry "AssignmentHandInStatus" (kind: section)
        |
        v  props { assignmentId: @objectId }
  AssignmentHandInStatus.vue
        |-- GET objects/learniq/assignment/{id}
        |-- GET objects/learniq/cohort/{cohortId}         (or ?courseId= when no cohort)
        |-- GET objects/learniq/submission?assignmentId=  (_limit 500)
        |-- GET objects/learniq/learner-profile           (names, _limit 1000)
        v
  handInStatus.js: rosterFor() -> handInRows() -> handInSummary()
```

## Nextcloud Integration
- Frontend only. `@nextcloud/axios`, `@nextcloud/router`, `@nextcloud/initial-state`
  (`dashboardRoles`, provided by `PageController`).
- `bodyWidgets` and `kind: "section"` from `@conduction/nextcloud-vue` (`CnBodySections`). Same
  pattern pipelinq uses for `ArticleUsageSection`.

## Decisions

### D1: A body section, not a new page
The recon asks for the status "on AssignmentDetail". A section keeps it next to the submissions
list the teacher already opens. Alternative rejected: a `type: custom` page, one more click.

### D2: Diff on the client
Same as `AttendanceRegisterView`: the roster and the submissions are two reads the teacher may
already make. A server endpoint would duplicate OpenRegister's list API (ADR-022).

### D3: Staff gate on dashboard views
A pupil can read the cohort roster (Cohort read matches `ncGroupId` on the user's groups) but only
their own submissions, so the diff would call every classmate missing. `dashboardRoles` from
initial state is the server's own role resolution (`DashboardRoleService`); `teacher` or `admin`
shows the section. This is a display gate; the data stays protected by the schemas'
authorization blocks either way.

### D4: Draft counts as started, not handed in
`SubmissionWindowGuard` makes `draft` the pre-hand-in state. A teacher wants to know who began,
so drafts get their own group instead of hiding in "not started".

### D5: New module, not `customPages.js`
Every lane-C change touches the helpers. A separate `handInStatus.js` keeps the diffs apart so the
PRs land in series without conflicts.

## Declarative-vs-imperative decision (ADR-031)
| Behaviour | Path | Rationale |
|---|---|---|
| Roster minus submitters | frontend helper | A diff across two schemas (Cohort, Submission) with no stored result. OpenRegister aggregations count one schema; they cannot subtract one set from another. Nothing is persisted. |
| Section on the detail page | declarative (`bodyWidgets`) | Manifest entry naming a registered component. |

## Security Considerations
No new endpoint and no new permission. Reads go through OpenRegister's RBAC as the signed-in user.
The staff gate (D3) prevents a misleading display; it is not the access control.

## NL Design System
Nextcloud components only (`NcLoadingIcon`, `NcNoteCard`, `NcEmptyContent`), CSS variables, no
hardcoded colours. The overdue mark is text plus colour, never colour alone.

## File Structure
```
src/utils/handInStatus.js                      (new)
src/components/sections/AssignmentHandInStatus.vue      (new)
src/registry.js                                (one entry)
src/manifest.d/learning.json                   (AssignmentDetail.config.bodyWidgets)
tests/unit-js/handInStatus.test.mjs            (new)
tests/unit-js/registryComponentCoverage.test.mjs (bodyWidgets check)
l10n/en.json, l10n/nl.json (+ generated .js)
```

## Seed Data
No schema change. Existing seed assignments, cohorts and submissions feed the section.

## Trade-offs
- Names come from a capped read of 1,000 profiles, like the attendance register. Past that, the
  user id shows instead of the name.
