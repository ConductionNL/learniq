---
kind: code
---

# Proposal: peer-review-projection-guard

## Summary
A peer reviewer now reads the work under review through a projection the server builds:
`GET /api/peer-review/{peerReviewId}/work` and a file download next to it. For a double-blind
review the projection withholds the authors and gives the files neutral names. It never includes
the teacher's marking. The reviewer never needs the raw Submission, so the reviewee side of
double-blind becomes a server-enforced guarantee on the reviewer's path, where it was a UI
convention.

## Motivation
Round 2 recon C, section 1 ("Peer-review double-blind anonymity is UI-only, not server-enforced")
and open question 4, recommendation (a): "add a server-side field-projection guard mirroring the
author-side protection that already exists". The assignments spec states the gap itself: "The
reviewee-identity axis for `double-blind` ... is NOT a server-enforced guarantee".

Reading the marking screen to build this found that the problem is wider than a leak.
`PeerReviewMarkingView` fetches the Submission only to show the author's name. A pupil reviewer may
not read another pupil's Submission (its read rule is staff plus the authors), so that fetch fails
for every real reviewer. The screen shows no submitted work at all: a pupil scores a rubric without
seeing what they are scoring. The projection fixes both, the anonymity and the missing work, without
widening who may read a Submission.

Capability row 6.2 (peer review and self assessment). Competitor evidence: Woots, round 1
(recon C section 2, `assessment/round1/notes-woots.txt`), "anonymous peer review of open answers";
Moodle, round 1 `moodle/round1/M1-moodle-column.md` row 6.2, `public/mod/workshop`. Rung 3: a
section on an existing custom page, backed by one read endpoint.

## Affected Projects
- [x] Project: `learniq`: `PeerReviewWorkProjection`, `PeerReviewWorkController` and two routes;
  `PeerReviewMarkingView` reads the projection and shows the work; `Assignment.peerReviewAnonymity`'s
  description.

## Scope

### In Scope
- `PeerReviewWorkProjection` (service): resolves a PeerReview for a caller (the reviewer or an admin,
  else refused) and projects `{ peerReviewId, submissionId, assignmentId, submittedAt, anonymity,
  authorIds, files }`. `authorIds` is null and file names are `file-N.ext` when the caller is the
  reviewer and the review is double-blind. An unreadable Assignment counts as double-blind; an
  unset anonymity is the schema default `blind`.
- `PeerReviewWorkController`: `show` (JSON) and `file` (attachment download, only files of the
  reviewed Submission, RFC 6266 file name).
- `PeerReviewMarkingView`: reads the projection, shows the hand-in time and the files with download
  links, and shows the authors only when the projection names them.
- Register: `Assignment.peerReviewAnonymity`'s description now says what the server does; Assignment
  and register versions bumped. Manifest note on `PeerReviewMarkingView` updated.
- Spec: the anonymity requirement whose last scenario said "UI-level only" is replaced by one that
  keeps its first three scenarios word for word and makes the fourth server-enforced on the
  reviewer's path; a new requirement covers the projection.

### Out of Scope
- Staff who are also a reviewer can still read the Submission through OpenRegister, because
  marking needs that read. The projection guarantees the reviewer's path, not every path of every
  role.
- Names inside the files themselves.
- Group submissions (deferred).

## Approach
Mirror `PeerFeedbackAggregator`: the author's side already reads peer feedback through a server-built
shape instead of the raw rows. The reviewer's side gets the same kind of shape for the work.

## New Dependencies
None.

## Impact
- New: `lib/Service/PeerReviewWorkProjection.php`, `lib/Controller/PeerReviewWorkController.php`,
  two tests.
- Changed: `appinfo/routes.php`, `src/views/PeerReviewMarkingView.vue`,
  `lib/Settings/learniq_register.json`, `src/manifest.d/learning.json`, `l10n/*`.

## Cross-Project Dependencies
None. Complements #1030 (`peer-review-allocation-trigger`), which makes allocation create reviews;
this makes an allocated review usable.

## Risks

### Risk 1: Reads without RBAC in the projection
**Severity:** Medium. **Mitigation:** the caller check (reviewer of that PeerReview, or admin) runs
before anything is read beyond the PeerReview itself. The output is a fixed whitelist of fields,
and the file endpoint serves only files of that Submission.

### Risk 2: A name in a file's content
**Severity:** Low. **Mitigation:** out of reach of any server; the file names are neutralised,
and the PR names the limit.

## Rollback Strategy
Revert the PR. The marking view returns to fetching the Submission, which fails for pupils as it
does today.
