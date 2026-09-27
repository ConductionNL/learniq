# Tasks: peer-review-projection-guard

## Implementation Tasks

### Task 1: Build the projection service
- **spec_ref**: `openspec/changes/peer-review-projection-guard/specs/assignments/spec.md#requirement-a-reviewer-reads-the-work-through-a-server-side-projection`
- **files**: `lib/Service/PeerReviewWorkProjection.php`, `tests/Unit/Service/PeerReviewWorkProjectionTest.php`
- **acceptance_criteria**:
  - GIVEN double-blind WHEN the reviewer resolves THEN authorIds is null and files are file-N.ext
  - GIVEN blind or open WHEN the reviewer resolves THEN authorIds lists the authors
  - GIVEN any other caller WHEN resolving THEN forbidden; an admin is allowed
  - The projection is a fixed whitelist without marking fields
- [x] Implement
- [x] Test

### Task 2: Add the controller and routes
- **spec_ref**: `openspec/changes/peer-review-projection-guard/specs/assignments/spec.md#requirement-a-reviewer-reads-the-work-through-a-server-side-projection`
- **files**: `lib/Controller/PeerReviewWorkController.php`, `appinfo/routes.php`, `tests/Unit/Controller/PeerReviewWorkControllerTest.php`
- **acceptance_criteria**:
  - GIVEN the reviewer WHEN GET work THEN 200 with the projection; 401, 403, 404 otherwise
  - GIVEN a file of the Submission WHEN downloaded THEN an attachment under the projected name; a foreign id is 404
- [x] Implement
- [x] Test

### Task 3: Read the projection in the marking view, update the register and spec wording
- **spec_ref**: `openspec/changes/peer-review-projection-guard/specs/assignments/spec.md#requirement-both-sides-of-peer-review-anonymity-are-server-enforced-projections`
- **files**: `src/views/PeerReviewMarkingView.vue`, `lib/Settings/learniq_register.json`, `src/manifest.d/learning.json`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the marking view WHEN it loads THEN it calls the projection and never the Submission
  - GIVEN the projection WHEN rendered THEN the work's files show with download links and authors only when named
  - Assignment.peerReviewAnonymity's description and versions updated; strings in English and Dutch
- [x] Implement
- [x] Test

## Quality checklist

- `vendor/bin/phpunit --filter PeerReviewWork`, phpcs, phpstan, psalm, phpmd on the new classes
- `npx eslint`, `npx prettier --check` on the view; `npm run check:schema-l10n`
- `openspec validate peer-review-projection-guard` passes
