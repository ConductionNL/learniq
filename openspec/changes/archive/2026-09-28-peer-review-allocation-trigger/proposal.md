---
kind: code
depends_on: [assignment-missing-submissions-view]
---

# Proposal: peer-review-allocation-trigger

## Summary
Teachers get an "Allocate reviewers" button on an assignment with peer review turned on. It calls
the allocation endpoint that was built, routed and tested but never called from any screen. Building
the button showed that the endpoint could not have worked either: its service read nothing and a
teacher could not write the reviews. Both are fixed here, so pressing the button creates the
reviews.

## Motivation
Round 2 recon C, section 1 ("Peer review: manual path works, automatic allocation is built but has
no UI"): `PeerReviewAllocationService` and `PeerReviewController::allocate()` are routed
(`POST /api/peer-review/{assignmentId}/allocate`) and permission-checked, but a repo-wide grep for
"allocate" in `src/` returns zero matches.

Reading the service to wire it found three defects that the unit tests could not see, because
their fake answers any config:

- Both `findAll()` calls pass `register`/`schema` at the top level of the config. OpenRegister's
  `ObjectService::prepareFindAllConfig()` reads them only from `filters`, so the read runs without
  a schema and returns nothing: no submissions, no reviews, `createdCount: 0`.
- The reads and the `PeerReview` writes run as the caller. The controller lets a cohort's teacher
  allocate, but `PeerReview` create is limited to `compliance-officers` and `team-leads`, and read to
  those plus the reviewer. A teacher in `instructors` could not write a single review, and could
  not see existing ones, which would break the "never duplicate" rule.
- Drafts count as submissions, so a pupil who never handed in joins the reviewer pool and gets
  reviewed.

Capability row 6.2 (peer review and self assessment; learniq "yes", round 1
`compare/M1-learniq-baseline.md` row 6.2). Competitor evidence: Moodle, round 1
`moodle/round1/M1-moodle-column.md` row 6.2, "`public/mod/workshop` (peer and self assessment with
allocation)". Rung 3: a section on an existing detail page.

## Affected Projects
- [x] Project: `learniq`: a peer review section on `AssignmentDetail`; the allocation service reads
  and writes the way OpenRegister expects.

## Scope

### In Scope
- `PeerReviewAllocationService`: `register`/`schema` nested under `filters`, an explicit limit, reads
  and writes with `_rbac: false` (the controller has already checked admin or cohort teacher), and
  only handed-in submissions (`submitted`, `late`, `returned`) in the pool and as work to review.
- `src/components/sections/AssignmentPeerReviewAllocation.vue`: shown to staff on an assignment with
  `peerReviewEnabled`. It states the strategy and reviewers per submission, and offers "Allocate
  reviewers" (not for `manual`), then reports how many reviews it created.
- `src/utils/peerReviewAllocation.js`: pure helpers for what the section shows.
- A second `bodyWidgets` entry on `AssignmentDetail` and a `kind: "section"` registry entry.
- Tests: the service test asserts the config shape and the RBAC flag; node tests for the helpers.

### Out of Scope
- Changing who may allocate (`PeerReviewController::canAllocate()` is unchanged).
- The double-blind projection (change `peer-review-projection-guard`).
- Group submissions (deferred).

## Approach
Stacked on `assignment-missing-submissions-view`: it adds the second body section to the same
`bodyWidgets` list and reuses that change's staff-view gate.

## New Dependencies
None.

## Impact
- `lib/Service/PeerReviewAllocationService.php`, `tests/Unit/PeerReview/PeerReviewAllocationServiceTest.php`.
- New `src/components/sections/AssignmentPeerReviewAllocation.vue`, `src/utils/peerReviewAllocation.js`,
  `tests/unit-js/peerReviewAllocation.test.mjs`.
- `src/manifest.d/learning.json`, `src/registry.js`, `l10n/*`.

## Cross-Project Dependencies
None.

## Risks

### Risk 1: `_rbac: false` in a service
**Severity:** Medium. **Mitigation:** the only caller is `PeerReviewController::allocate()`, which
checks admin or a teacher of the assignment's cohort before it calls the service. The service
reads and writes only the assignment's own submissions and reviews.

### Risk 2: Allocating before everyone has handed in
**Severity:** Low. **Mitigation:** allocation is idempotent and tops up. The section says so when
the deadline has not passed yet, so a teacher can allocate again later.

## Rollback Strategy
Revert the PR. The endpoint returns to reading nothing; created reviews stay and are valid.
