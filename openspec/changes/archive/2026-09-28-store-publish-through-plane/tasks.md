# Tasks: store-publish-through-plane

Feature tier: should (sharing, D22).

## Implementation Tasks

### Task 1: Store plane stubs follow openregister #4079
- **spec_ref**: `openspec/changes/store-publish-through-plane/specs/course-management/spec.md#requirement-a-course-store-publish-travels-through-the-store-planes-write-path`
- **files**: `tests/Stubs/AppHost/Service/GenericStoreService.php`, `tests/Stubs/AppHost/Service/StoreDescriptor.php`, `tests/Stubs/AppHost/Store/StoreActionAuthorizer.php` (new), `tests/Stubs/Service/SecurityService.php` (removed), `psalm.xml`
- **acceptance_criteria**:
  - GIVEN the stubs WHEN psalm and phpstan load them THEN `publish()`, `publishFields`, `publishGroups`, `isPublishable()` and `canPublish()` resolve with the real signatures
- [x] Implement
- [x] Test

### Task 2: CourseStoreDescriptor names what may travel and who may send it
- **spec_ref**: `openspec/changes/store-publish-through-plane/specs/course-management/spec.md#requirement-a-course-store-publish-travels-through-the-store-planes-write-path`
- **files**: `lib/Service/CourseStore/CourseStoreDescriptor.php`, `tests/Unit/Service/CourseStore/CourseStoreDescriptorTest.php` (new), callers of `new CourseStoreDescriptor()` in tests
- **acceptance_criteria**:
  - GIVEN a built registry object WHEN compared with PUBLISH_FIELDS THEN the sets are equal (TC-2)
  - GIVEN the matrix groups WHEN descriptor() runs THEN publishGroups equal them (TC-3)
  - GIVEN supportsPublish() false WHEN descriptor() runs THEN no publish argument is passed (TC-7)
- [x] Implement
- [x] Test

### Task 3: CourseStorePublisher publishes through the plane
- **spec_ref**: `openspec/changes/store-publish-through-plane/specs/course-management/spec.md#requirement-the-plane-decides-who-may-publish-before-a-package-is-built`
- **files**: `lib/Service/CourseStore/CourseStorePublisher.php`, `lib/Service/CourseStore/CourseStoreUrlGuard.php` (removed), `tests/Unit/Service/CourseStore/CourseStorePublisherTest.php`, `tests/Unit/Service/CourseStore/CourseStoreUrlGuardTest.php` (removed)
- **acceptance_criteria**:
  - GIVEN supported and configured WHEN publish() THEN the plane's publish() is called once and its answer returned (TC-1)
  - GIVEN any authorizer failure WHEN mayPublish() THEN false (TC-6)
  - GIVEN unsupported WHEN publish() THEN publish_not_supported without a plane call (TC-7)
- [x] Implement
- [x] Test

### Task 4: StoreController asks the plane and maps every outcome
- **spec_ref**: `openspec/changes/store-publish-through-plane/specs/course-management/spec.md#requirement-the-plane-decides-who-may-publish-before-a-package-is-built`
- **files**: `lib/Controller/StoreController.php`, `tests/Unit/Controller/StoreControllerTest.php`
- **acceptance_criteria**:
  - GIVEN mayPublish() false WHEN publish THEN 403 forbidden and no gate (TC-4)
  - GIVEN each plane outcome WHEN publish THEN its status (TC-5)
  - GIVEN unsupported WHEN publish THEN 501 and no gate (TC-7)
- [x] Implement
- [x] Test

### Task 5: Publish screen copy, catalogue and docs
- **spec_ref**: `openspec/changes/store-publish-through-plane/specs/course-management/spec.md#requirement-the-plane-decides-who-may-publish-before-a-package-is-built`
- **files**: `src/views/ExportRequestView.vue`, `l10n/nl.json` (+ `npm run l10n:build`), `docs/`
- **acceptance_criteria**:
  - GIVEN outcome forbidden, rate_limited or publish_not_supported WHEN the screen shows it THEN a plain sentence in English and Dutch names the remedy
- [x] Implement
- [x] Test

## Quality checklist

- PHPUnit for every changed class (`--filter` while building, the full suite once in `check:strict`).
- No new endpoint, so no new Newman collection; the publish route's new statuses are unit-tested.
- Browser screenshots (ADR-010): not taken; the publish screen only gains sentences, and no registry is configured on the dev instance.
- Dutch and English strings for every new sentence.
- `openspec validate store-publish-through-plane` passes.
