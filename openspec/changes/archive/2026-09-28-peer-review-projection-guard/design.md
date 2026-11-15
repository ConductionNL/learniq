# Design: peer-review-projection-guard

## Architecture Overview

```
PeerReviewMarkingView (reviewer)
  GET /apps/learniq/api/peer-review/{id}/work ------------------+
  GET /apps/learniq/api/peer-review/{id}/work/files/{fileId} ---+
                                                                v
PeerReviewWorkController (#[NoAdminRequired], caller from IUserSession)
        v
PeerReviewWorkProjection
  resolve(): PeerReview (no RBAC) -> caller is reviewerId or admin? else FORBIDDEN
             Submission, Assignment (no RBAC) -> anonymity, hideAuthor
  project(): whitelist { peerReviewId, submissionId, assignmentId, submittedAt,
                         anonymity, authorIds|null, files[] (neutral names if hidden) }
  file():    only a file of that Submission, under the projected name
```

Mirror of the author's side: `PeerFeedbackAggregator` gives the author a server-built summary
instead of raw `PeerReview` rows; this gives the reviewer a server-built view of the work instead
of the raw `Submission`.

## API Design

### `GET /api/peer-review/{peerReviewId}/work`
**Response 200:**
```json
{
  "peerReviewId": "<peer-review-uuid>",
  "submissionId": "<submission-uuid>",
  "assignmentId": "<assignment-uuid>",
  "submittedAt": "2026-09-20T10:00:00+00:00",
  "anonymity": "double-blind",
  "authorIds": null,
  "files": [{ "id": "12", "name": "file-1.pdf", "size": 1024, "mimetype": "application/pdf" }]
}
```
401 without a session, 403 for anyone but the reviewer or an admin, 404 for an unknown review.

### `GET /api/peer-review/{peerReviewId}/work/files/{fileId}`
The file as an attachment (`Content-Disposition: attachment; filename="..."; filename*=UTF-8''...`).
`#[NoCSRFRequired]` because a plain link opens it; read-only, same caller check. 404 for a file that
is not one of the Submission's.

## Nextcloud Integration
- Controller: `PeerReviewWorkController` (new), `#[NoAdminRequired]` with a per-object check.
- Services: `PeerReviewWorkProjection` (new), `OCA\OpenRegister\Service\ObjectService`,
  `OCA\OpenRegister\Service\FileService::getFiles()`.
- Responses: `JSONResponse`, `DataDisplayResponse` with an attachment disposition (not
  `DataDownloadResponse`, whose Symfony header helper is absent in the unit environment, and whose
  behaviour this reproduces).

## Decisions

### D1: A projection endpoint, not property-level RBAC
OpenRegister supports per-property read rules, but a rule cannot see the Assignment's anonymity (another
object), and opening Submission read to reviewers would also expose the teacher's marks and every
other field, and calculated fields such as `effectiveGrade`. A fixed whitelist built by the server is
narrower and testable.

### D2: Reviewer or admin only
The reviewer is the audience. Teachers already read Submissions directly; they gain nothing here.

### D3: Neutral file names under double-blind
A pupil's name in a file name (`Alice_de_Vries_essay.pdf`) would undo the withheld `authorIds`. Names
become `file-N.ext` in a stable order (by file id), in the listing and in the download header.

### D4: Fail closed on anonymity
An Assignment that cannot be read counts as double-blind. An unset value is the schema default
`blind`, which is what OpenRegister would have stored.

### D5: The marking view stops fetching the Submission
That fetch failed for every pupil reviewer anyway. The view now gets authors, time and files from one
call.

## Declarative-vs-imperative decision (ADR-031)
| Behaviour | Path | Rationale |
|---|---|---|
| Reviewer-facing view of the work | imperative (service + controller) | A cross-object projection conditioned on another object's field (Assignment anonymity) and on the caller, plus file streaming. No schema declaration expresses it; same exception class as `PeerFeedbackAggregator`. |
| Anonymity description | declarative (register text) | Schema documentation. |

## Security Considerations
- Per-object authorization before any read beyond the PeerReview: reviewer or admin (gate 7 shape:
  caller from `IUserSession`, 403 on refusal).
- Fixed output whitelist; no marking, no portal keys.
- File endpoint: only files of the reviewed Submission (no bare file-id lookup), attachment
  disposition with an ASCII fallback so a name cannot inject header content.
- `#[NoCSRFRequired]` only on the read-only file download.

## NL Design System
Nextcloud markup and CSS variables; download links are real anchors with the `download` attribute.

## File Structure
```
lib/Service/PeerReviewWorkProjection.php          (new)
lib/Controller/PeerReviewWorkController.php       (new)
appinfo/routes.php                                (two routes)
src/views/PeerReviewMarkingView.vue
lib/Settings/learniq_register.json                (Assignment.peerReviewAnonymity description, versions)
src/manifest.d/learning.json                      (PeerReviewMarkingView note)
tests/Unit/Service/PeerReviewWorkProjectionTest.php
tests/Unit/Controller/PeerReviewWorkControllerTest.php
l10n/en.json, l10n/nl.json (+ generated .js)
```

## Seed Data
No new schema and no new property. The Assignment change is its description only; existing seed
assignments already carry `peerReviewAnonymity`.

## Trade-offs
Staff reviewers can still read the Submission directly (proposal, out of scope). File content is
served through learniq, which buffers the file in memory; submissions are documents, not videos.
