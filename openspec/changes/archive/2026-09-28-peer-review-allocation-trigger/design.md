# Design: peer-review-allocation-trigger

## Architecture Overview

```
AssignmentDetail.config.bodyWidgets
  [asn-hand-in]      AssignmentHandInStatus          (assignment-missing-submissions-view)
  [asn-peer-review]  AssignmentPeerReviewAllocation  (this change)
        |  GET objects/learniq/assignment/{id}
        |  POST /apps/learniq/api/peer-review/{id}/allocate
        v
  PeerReviewController::allocate()  -- canAllocate(): admin or cohort teacher (unchanged)
        v
  PeerReviewAllocationService::allocate()
        findAll(filters: {register, schema, assignmentId}, limit, _rbac: false)
        saveObject(peer-review, _rbac: false)
```

## Nextcloud Integration
- Controller and route unchanged (`peerReview#allocate`).
- Service: `OCA\OpenRegister\Service\ObjectService` with nested filters and `_rbac: false`.
- Frontend: `@nextcloud/axios`, `@nextcloud/router`, `@nextcloud/initial-state`; `CnBodySections`.

## Decisions

### D1: Fix the service in the same change
A button that calls an endpoint which creates nothing is the silent no-op this programme keeps
finding. The fix is two config shapes, three RBAC flags and one lifecycle filter, all inside the
code path the button exists to reach.

### D2: `_rbac: false` behind the controller's check
`PeerReview` create is `compliance-officers`/`team-leads` in the register, which leaves out the
cohort teacher the controller authorizes. Widening the schema's create rule would let every
instructor create reviews for any assignment through the generic API. Keeping the schema strict
and letting the authorized endpoint write as the system is narrower.

### D3: Handed-in work only
The spec draws the pool from "people who actually did the work". A `draft` is not handed in.

### D4: A section, not a header action
Header actions cannot be role-gated per user, and `api-call` would show a pupil a button the server
refuses. The section reuses the staff-view gate from the stacked change.

### D5: Explicit limits
Submissions `limit: 1000`, reviews `limit: 5000`, instead of OpenRegister's default page.

## Declarative-vs-imperative decision (ADR-031)
| Behaviour | Path | Rationale |
|---|---|---|
| Matching reviewers to submissions | imperative (existing service) | Batch matching over a set; unchanged ADR-031 exception. |
| The section | declarative (`bodyWidgets`) + registered component | Manifest entry. |

## Security Considerations
- Authorization stays in `PeerReviewController::canAllocate()` (admin or a teacher of the
  assignment's cohort); `#[NoAdminRequired]` with that per-object guard is unchanged.
- The service's `_rbac: false` is scoped to one assignment's submissions and reviews (D2).
- The section is a display gate for staff; pupils cannot allocate either way.

## NL Design System
Nextcloud components (`NcButton`, `NcNoteCard`, `NcLoadingIcon`), CSS variables only.

## File Structure
```
lib/Service/PeerReviewAllocationService.php
tests/Unit/PeerReview/PeerReviewAllocationServiceTest.php
src/components/sections/AssignmentPeerReviewAllocation.vue   (new)
src/utils/peerReviewAllocation.js                            (new)
tests/unit-js/peerReviewAllocation.test.mjs                  (new)
src/manifest.d/learning.json, src/registry.js, l10n/*
```

## Seed Data
No schema change.

## Trade-offs
The section cannot show how many reviews exist before allocating: a teacher in `instructors` cannot
read `PeerReview`. It reports the counts the endpoint returns instead.
