# Course management

## ADDED Requirements

### Requirement: A course store publish travels through the store plane's write path
Learniq MUST publish a shared course only through OpenRegister's `GenericStoreService::publish()`, with the `CourseStoreDescriptor` descriptor and the registry object `CourseStoreRegistryObject` builds. Learniq MUST NOT build a registry URL, read the registry token, or open an HTTP client for a publish. The descriptor MUST name the object properties that may leave the server (`publishFields`) and the groups that may send them (`publishGroups`), and `publishGroups` MUST be the groups learniq's own permission matrix holds for the action `course-package.share`. This supersedes the transport sentence of "Publishing sends a gated package to the registry" (lesson-sharing-via-store-plane): the sharing gate and the `CourseShareConsent` record with purpose `store` still run first, but the SSRF guard, the redirect refusal, the timeouts, the Bearer token and the 20 MiB cap are the plane's. FEATURES tier: should (sharing, D22).

#### Scenario: A passing course is published through the plane
- **GIVEN** a configured registry, a user the matrix and the plane both admit, and a course that passes the sharing gate
- **WHEN** the user publishes "Betoog schrijven, havo 4"
- **THEN** learniq calls `GenericStoreService::publish()` once with the course store descriptor and a payload whose `slug` starts with `course-package-`
- **AND** the response carries outcome `ok` and that slug

#### Scenario: Only listed properties may travel
- **GIVEN** the registry object for a shared course
- **WHEN** the descriptor is built
- **THEN** every property of that object is in `publishFields`, and `publishFields` names nothing the object does not carry

#### Scenario: The matrix is the source of the publish groups
- **GIVEN** the matrix holds `course-package.share: ["admin", "team-leads"]`
- **WHEN** the descriptor is built on an OpenRegister that can publish
- **THEN** its `publishGroups` are `["admin", "team-leads"]`

### Requirement: The plane decides who may publish before a package is built
`POST /api/store/publish` MUST keep `requireAction('course-package.share')` and MUST then ask OpenRegister's `StoreActionAuthorizer::canPublish()` with the course store descriptor. When the plane refuses, learniq MUST answer 403 with outcome `forbidden` and MUST NOT run the sharing gate, record consent or send anything. Every other plane outcome MUST map to a status: `ok` and `not_configured` 200, `too_large` 413, `rate_limited` 429, `store_unreachable`, `store_rejected` and `store_invalid_response` 502, `not_publishable` 500 (a learniq defect: the descriptor did not opt in).

#### Scenario: The plane refuses a user the matrix admitted
- **GIVEN** the matrix entry for `course-package.share` is an empty list, so the plane names nobody
- **WHEN** an administrator publishes
- **THEN** the response is 403 with outcome `forbidden`, and no gate, consent or request ran

#### Scenario: A rate-limited registry says wait
- **GIVEN** the registry answers the publish with 429
- **WHEN** the user publishes
- **THEN** the response is 429 with outcome `rate_limited`, and the publish screen says to try again in a few minutes

### Requirement: Publishing degrades cleanly on an OpenRegister without the write path
Learniq MUST probe the installed OpenRegister before it uses the publish path: `StoreDescriptor` must declare `publishFields`, `GenericStoreService` must have `publish()`, and `StoreActionAuthorizer` must have `canPublish()`. When any is missing, search and install MUST keep working, the descriptor MUST be built without the publish arguments, and `POST /api/store/publish` MUST answer 501 with outcome `publish_not_supported` without running the gate or sending a request.

#### Scenario: An older OpenRegister
- **GIVEN** an OpenRegister whose `StoreDescriptor` has no `publishFields`
- **WHEN** a user searches the store
- **THEN** the search answers as before
- **AND** WHEN the user publishes, the response is 501 with outcome `publish_not_supported` and no request left the server
