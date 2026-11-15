# Course management

## ADDED Requirements

### Requirement: Any teacher installs a shared course as a copy
Installing from the course store MUST require the ADR-023 action `course-store.install`, seeded `["admin", "instructors", "team-leads"]`, and MUST NOT require `course-package.import`, which stays the right to import Canvas and Moodle packages. The seed MUST name only groups that may create the objects an install writes (course, lesson, material). FEATURES tier: should (sharing, D27).

#### Scenario: A teacher installs a shared course
- **GIVEN** a user in the `instructors` group only, and a configured registry holding "Betoog schrijven, havo 4"
- **WHEN** the user installs it from the Store
- **THEN** the install passes the action check and the course is imported as the user's own copy

#### Scenario: The package import stays with administrators
- **GIVEN** the same teacher
- **WHEN** the teacher uploads a Moodle backup on the import page
- **THEN** the request is refused, because `course-package.import` still names only `admin`

### Requirement: Publishing to the store defaults to team leads
The ADR-023 action `course-package.share` MUST be seeded `["admin", "team-leads"]`, and the store's publish groups MUST be read from that row (store-publish-through-plane).

#### Scenario: A team lead publishes
- **GIVEN** a user in `team-leads` and a course that passes the sharing gate
- **WHEN** the user publishes it to the store
- **THEN** the matrix and the plane both admit the user

#### Scenario: A teacher cannot publish
- **GIVEN** a user in `instructors` only
- **WHEN** the user calls `POST /api/store/publish`
- **THEN** the response is 403 and nothing leaves the school

### Requirement: Existing installs get the new store defaults once
An upgrade MUST apply the store defaults to an existing matrix exactly once: it MUST add `course-store.install` with its seed groups when the matrix has no such row, and MUST replace `course-package.share` with its seed groups only when that row is exactly `["admin"]`. It MUST record that it ran and MUST NOT change either row on a later upgrade. A fresh install MUST get the defaults from the seed.

#### Scenario: An untouched instance upgrades
- **GIVEN** a matrix where `course-package.share` is `["admin"]` and `course-store.install` is absent
- **WHEN** the upgrade runs
- **THEN** `course-store.install` is `["admin", "instructors", "team-leads"]` and `course-package.share` is `["admin", "team-leads"]`

#### Scenario: An administrator's choice survives
- **GIVEN** the step ran once, and the administrator then set `course-package.share` back to `["admin"]`
- **WHEN** a later upgrade runs
- **THEN** `course-package.share` stays `["admin"]`

#### Scenario: A customised row is left alone
- **GIVEN** a matrix where `course-package.share` is `["admin", "coordinators"]`
- **WHEN** the upgrade runs for the first time
- **THEN** `course-package.share` stays `["admin", "coordinators"]`

### Requirement: The Store page shows each user the actions they may take
The app MUST provide initial state `storeAccess` as `{install, publish}` for the signed-in user: `install` is whether the matrix admits `course-store.install`; `publish` is whether the matrix admits `course-package.share`, the installed OpenRegister can publish, and the store plane admits the user. The app MUST pass `canInstall` and `canPublish` from that state to every `type: "store"` page, and the Store page MUST name `publishRoute: "CoursePackageExport"`. The export screen MUST show its publish button only when `storeAccess.publish` is true, and the export menu entry MUST admit `team-lead`. With a `CnStorePage` that predates these props, the page MUST keep showing Install to administrators only.

#### Scenario: A teacher opens the Store
- **GIVEN** a user in `instructors` only
- **WHEN** the user opens the Store page
- **THEN** `storeAccess` is `{install: true, publish: false}` and the page config carries `canInstall: true` and `canPublish: false`

#### Scenario: A team lead opens the export screen
- **GIVEN** a user in `team-leads` whose primary role is `team-lead`
- **WHEN** the user opens the menu
- **THEN** "Export course package" is listed, and the export screen shows "Publish to the course store"

### Requirement: An administrator connects the course registry in the admin settings
The Learniq admin settings page MUST offer a "Course store" section with the registry address, the register and the token. `GET /api/admin/store-registry` and `PUT /api/admin/store-registry` MUST be admin-only. The GET MUST return the address, the register and whether a token is set, and MUST NOT return the token. The PUT MUST accept an empty address (which disconnects the store) or an absolute `http` or `https` URL without user credentials, a register that is empty or a lowercase slug, and a token that is kept when omitted, replaced when given, and removed when `clearToken` is true. The token MUST be stored as a sensitive app config value under `registry_token`; the address and register under `registry_url` and `registry_register`, the keys the store plane reads.

#### Scenario: An administrator connects a registry
- **GIVEN** an administrator on the Learniq admin settings page
- **WHEN** they enter `https://store.example.nl`, register `learniq` and token `YOUR_TOKEN_HERE`, and save
- **THEN** the three keys are stored, the token as sensitive, and the section shows "A token is set" without the value

#### Scenario: A malformed address is refused
- **GIVEN** an administrator
- **WHEN** they save the address `ftp://store.example.nl` or `https://user:CHANGE_ME@store.example.nl`
- **THEN** the response is 400 and nothing is stored

#### Scenario: A non-administrator cannot read the connection
- **GIVEN** a teacher
- **WHEN** they call `GET /api/admin/store-registry`
- **THEN** Nextcloud refuses the request before the controller runs
