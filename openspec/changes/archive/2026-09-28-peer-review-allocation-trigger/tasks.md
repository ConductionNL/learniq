# Tasks: peer-review-allocation-trigger

## Implementation Tasks

### Task 1: Make the allocation service read and write
- **spec_ref**: `openspec/changes/peer-review-allocation-trigger/specs/assignments/spec.md#requirement-allocation-reads-and-writes-as-the-system-after-the-controllers-check`
- **files**: `lib/Service/PeerReviewAllocationService.php`, `tests/Unit/PeerReview/PeerReviewAllocationServiceTest.php`
- **acceptance_criteria**:
  - GIVEN five handed-in submissions WHEN allocating round-robin with two reviewers THEN ten reviews are saved with `_rbac: false`
  - GIVEN any findAll WHEN issued THEN register and schema sit under `filters`
  - GIVEN a draft WHEN allocating THEN it is neither reviewed nor a reviewer
- [x] Implement
- [x] Test

### Task 2: Add the peer review section
- **spec_ref**: `openspec/changes/peer-review-allocation-trigger/specs/assignments/spec.md#requirement-a-teacher-allocates-peer-reviewers-from-the-assignment-page`
- **files**: `src/utils/peerReviewAllocation.js`, `tests/unit-js/peerReviewAllocation.test.mjs`, `src/components/sections/AssignmentPeerReviewAllocation.vue`, `src/registry.js`, `src/manifest.d/learning.json`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN round-robin or random WHEN rendered for staff THEN the allocate button shows; GIVEN manual THEN it does not
  - GIVEN a student-only user or peer review off WHEN rendered THEN nothing shows
  - GIVEN an allocation result WHEN reported THEN created and processed counts show
- [x] Implement
- [x] Test

## Quality checklist

- `vendor/bin/phpunit --filter PeerReview`, `node --test tests/unit-js/`
- `npm run check:manifest`, catalogue strings in English and Dutch
- `openspec validate peer-review-allocation-trigger` passes
