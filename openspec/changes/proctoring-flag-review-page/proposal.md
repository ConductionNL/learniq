---
kind: code
---

# Review proctoring flags in one queue

## Why

Decision 100 (8 Oct) reopened the decided-no row `ass-review-proctoring-flags` because the design canvas draws the screen: board `LqProctoring` ("Proctoring: vlaggen beoordelen") on canvas part 2 (`QAAxpcsFKBCvDUGbQbwtCa`).

Most of it already exists. Native test mode (`src/views/TakeAssessmentView.vue:1305-1338`) writes `tab-hidden`, `window-blur`, `fullscreen-exit`, `blocked-navigation` and `concurrent-session-detected` flags into `ProctoringSession.flags[]`, and the schema carries `reviewDecision`, `reviewedBy` and `reviewedAt` per flag. `src/views/ProctoringReviewQueue.vue` lists sessions with pending flags and records allow or annul. Three things stop it from working:

1. No page reaches the queue. The manifest split (`4e49128f`, 20 Aug) dropped the `ProctoringReviewQueue` page; the component is still registered (`src/registry.js:322`) but nothing routes to it.
2. A learner can decide their own flags. `proctoring-session` grants update to the learner on their own session while it is `created` or `active`, and nothing stops that write from setting `reviewDecision: "allowed"` or removing a flag.
3. The queue shows a user id where the board shows the learner's name, the test and the minute the event happened, and it does not show who already decided a flag.

### Matrix rows (`openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `ass-review-proctoring-flags` | Review what a proctoring session flagged, in one queue. | `partial`: flags are written and the queue component exists, but no page reaches it and a learner can overwrite a decision |

`ass-proctor-an-exam` (an external proctoring service) stays decided-no. This change needs no external provider: native test mode fills the queue on its own.

## What changes

- The page comes back at `/assessments/proctoring/review`, reached from the Assessments page and the Proctoring sessions list, for `instructors` and `compliance-officers`.
- Each session card names the test, the learner, the provider and the number of open flags; each flag says what happened in words, when, and how many minutes into the attempt.
- Allow and Annul sit on each open flag. A decided flag shows "Allowed by {name} on {date}" and no buttons.
- A pre-write guard refuses any change to `reviewDecision`, `reviewedBy` or `reviewedAt` by anyone outside `instructors` and `compliance-officers`, refuses a learner removing or rewriting an existing flag, and stamps `reviewedBy` and `reviewedAt` on the server.
- Annulling a flag still changes nothing on the result. The page says so under the list and points to the result page.

## Capabilities

### Modified capabilities

- `assessment`: ADDED requirements for the page, the guard and the card contents.

## Impact

- **Frontend**: `src/manifest.d/learning.json` (page, menu entry under Assessments, link from `ProctoringSessions`), `src/views/ProctoringReviewQueue.vue`, l10n.
- **Backend**: a new pre-write listener `lib/Listener/ProctoringFlagReviewGuard.php`, registered with the other assessment listeners.
- **Register**: none. The flag shape already carries every field.
