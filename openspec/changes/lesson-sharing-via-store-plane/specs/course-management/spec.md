# Course management

## ADDED Requirements

### Requirement: The Store page lists shared courses through the store plane
Learniq MUST declare a `StoreDescriptor` for schema `shared-course-package` (default register `learniq`) and MUST search and resolve shared courses only through OpenRegister's `GenericStoreService`. `GET /api/store/items` MUST answer `{outcome, cards, kinds, builtIn}`; each card MUST carry `slug`, `title`, `description`, `subject`, `level`, `goals`, `language`, `license`, `author`, `version`, `typeName` and `kind`, and nothing else. With no registry configured it MUST answer `not_configured` without a network call.

#### Scenario: No registry configured
- **GIVEN** `registry_url` is empty for learniq
- **WHEN** a signed-in user opens the Store page
- **THEN** the response outcome is `not_configured`, the card list is empty, and no request left the server

#### Scenario: A teacher finds a shared course
- **GIVEN** a configured registry holding a shared package "Betoog schrijven, havo 4" licensed CC BY-SA 4.0
- **WHEN** the teacher searches "betoog"
- **THEN** a card shows the title, `Nederlandse taal · havo · CC-BY-SA-4.0` under it, and the author

#### Scenario: An anonymous request is refused
- **GIVEN** no session
- **WHEN** `GET /api/store/items` is called
- **THEN** the response is 401

### Requirement: Installing a shared course creates an independent copy that keeps the credit
`POST /api/store/items/{slug}/install` MUST require the `course-package.import` action, MUST refuse a malformed slug with 400 and an unresolvable one with 404, and MUST import the resolved package through `CoursePackageImportService` as learniq JSON. The import MUST create new objects and MUST carry the course `license`, `author`, `subject`, `educationalLevels`, `language`, `level` and `description` onto the new course when their values are valid. The response MUST list each imported resource with status `installed`, `degraded` or `refused`.

#### Scenario: A shared course is installed as a copy
- **GIVEN** a resolvable shared package whose course is licensed CC BY-SA 4.0 by "Sectie Nederlands, OSG De Vaart"
- **WHEN** an administrator installs it
- **THEN** a new draft course exists with that licence and author, a new course code, and its own lessons

#### Scenario: A package without a package body is refused
- **GIVEN** a registry object with no `package`
- **WHEN** it is installed
- **THEN** the response reports failure and nothing is written

### Requirement: Publishing sends a gated package to the registry
`POST /api/store/publish` MUST require the `course-package.share` action and MUST run the sharing gate with the two confirmations; a refusal MUST be 422 with `blockers`. On a pass it MUST record a `CourseShareConsent` with purpose `store`, then POST the registry object to `<registry_url>/index.php/apps/openregister/api/objects/<register>/shared-course-package` with the registry token as a Bearer header only, after `SecurityService::assertSafeFetchUrl()`, with redirects refused and 10 second timeouts. A package over 20 MB MUST be refused without a request.

#### Scenario: Publishing without a registry
- **GIVEN** the gate passes and `registry_url` is empty
- **WHEN** the teacher publishes
- **THEN** the outcome is `not_configured` and no request is made

#### Scenario: A private registry address is refused
- **GIVEN** `registry_url` is `http://192.168.1.10`
- **WHEN** the teacher publishes
- **THEN** the outcome is `store_unreachable` and no request is made

#### Scenario: A published course is findable
- **GIVEN** the gate passes and the registry accepts the POST
- **WHEN** the teacher publishes "Betoog schrijven, havo 4"
- **THEN** the response carries outcome `ok` and a slug starting with `course-package-`

### Requirement: Any learniq instance can act as the registry
The register MUST declare `SharedCoursePackage` (slug `shared-course-package`) holding the card fields as strings, `levels` and `goalsCovered` as arrays, `kind` `course-package`, `sharedAt` and the `package` object. Read MUST be `authenticated`; create MUST be `instructors`, `team-leads`, `coordinators` and `administration-managers`; update MUST be `administration-managers`.

#### Scenario: A school board runs the registry
- **GIVEN** a learniq instance whose admin created a service account in `instructors` and handed its token to member schools
- **WHEN** a member school publishes
- **THEN** the package is stored as a `SharedCoursePackage` on the board's instance and every member school's store lists it
