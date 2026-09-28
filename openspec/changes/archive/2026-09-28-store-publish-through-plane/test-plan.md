# Test Plan: store-publish-through-plane

## Test Cases

### TC-1: A passing course goes through GenericStoreService::publish
- **spec_ref**: `openspec/changes/store-publish-through-plane/specs/course-management/spec.md#requirement-a-course-store-publish-travels-through-the-store-planes-write-path`
- **type**: functional
- **preconditions**: plane configured, publish supported, authorizer admits
- **steps**: `CourseStorePublisher::publish($package)`
- **expected result**: `publish()` called once with a descriptor naming `shared-course-package` and a payload whose slug starts `course-package-`; the plane's answer is returned unchanged
- **test command**: `vendor/bin/phpunit --filter CourseStorePublisherTest`

### TC-2: publishFields covers exactly what the registry object carries
- **spec_ref**: `openspec/changes/store-publish-through-plane/specs/course-management/spec.md#requirement-a-course-store-publish-travels-through-the-store-planes-write-path`
- **type**: security
- **preconditions**: a built registry object
- **steps**: compare `array_keys(build())` minus `slug` with `CourseStoreDescriptor::PUBLISH_FIELDS`
- **expected result**: equal sets
- **test command**: `vendor/bin/phpunit --filter CourseStoreDescriptorTest`

### TC-3: publishGroups come from the matrix
- **spec_ref**: `openspec/changes/store-publish-through-plane/specs/course-management/spec.md#requirement-a-course-store-publish-travels-through-the-store-planes-write-path`
- **type**: functional
- **preconditions**: `getAllowedGroups('course-package.share')` returns `["admin","team-leads"]`
- **steps**: `descriptor()`
- **expected result**: `publishGroups === ["admin","team-leads"]`, `isPublishable()` true
- **test command**: `vendor/bin/phpunit --filter CourseStoreDescriptorTest`

### TC-4: The plane refuses before anything is built
- **spec_ref**: `openspec/changes/store-publish-through-plane/specs/course-management/spec.md#requirement-the-plane-decides-who-may-publish-before-a-package-is-built`
- **type**: security
- **preconditions**: configured, supported, `mayPublish()` false
- **steps**: `StoreController::publish('course-1')`
- **expected result**: 403, outcome `forbidden`, `buildPackage` and `publish` never called
- **test command**: `vendor/bin/phpunit --filter StoreControllerTest`

### TC-5: Outcomes map to statuses
- **spec_ref**: `openspec/changes/store-publish-through-plane/specs/course-management/spec.md#requirement-the-plane-decides-who-may-publish-before-a-package-is-built`
- **type**: api
- **preconditions**: a passing course
- **steps**: publish with the plane answering each outcome
- **expected result**: ok 200, too_large 413, rate_limited 429, store_unreachable / store_rejected / store_invalid_response 502, not_publishable 500
- **test command**: `vendor/bin/phpunit --filter StoreControllerTest`

### TC-6: The authorizer fails closed
- **spec_ref**: `openspec/changes/store-publish-through-plane/specs/course-management/spec.md#requirement-the-plane-decides-who-may-publish-before-a-package-is-built`
- **type**: security
- **preconditions**: the container throws for the authorizer, or returns an object without `canPublish()`, or `canPublish()` throws
- **steps**: `mayPublish($user)`
- **expected result**: false in all three cases
- **test command**: `vendor/bin/phpunit --filter CourseStorePublisherTest`

### TC-7: An older OpenRegister
- **spec_ref**: `openspec/changes/store-publish-through-plane/specs/course-management/spec.md#requirement-publishing-degrades-cleanly-on-an-openregister-without-the-write-path`
- **type**: regression
- **preconditions**: `supportsPublish()` false
- **steps**: `descriptor()`, `publisher->publish()`, `StoreController::publish()`
- **expected result**: descriptor built without publish arguments; publish returns `publish_not_supported` without calling the plane; controller answers 501 and runs no gate
- **test command**: `vendor/bin/phpunit --filter 'CourseStoreDescriptorTest|CourseStorePublisherTest|StoreControllerTest'`

## Coverage Summary
- A course store publish travels through the store plane's write path: TC-1, TC-2, TC-3.
- The plane decides who may publish before a package is built: TC-4, TC-5, TC-6.
- Publishing degrades cleanly on an OpenRegister without the write path: TC-7.

## Out of Scope
A live publish against a second instance acting as the registry: the dev instance has no
registry configured, and a WSL restart earlier today left the browser service unreliable.
The plane's own transport rules are covered by openregister's 80 store tests.
