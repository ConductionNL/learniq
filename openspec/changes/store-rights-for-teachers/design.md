# Design: store-rights-for-teachers

## Architecture Overview

```
actions.seed.json ──(fresh install: GenericInitializeActions)──► matrix (IAppConfig learniq.actions)
        │                                                             ▲
        └──(upgrade, once: ApplyStoreRightsDefaults)──────────────────┘
                                                                      │
StoreController::install()  ── requireAction('course-store.install') ─┤
StoreController::publish()  ── requireAction('course-package.share') ─┤ + plane canPublish (store-publish-through-plane)
StoreAccessService::forUser() ── can(...) both, + publisher probes ───┘
        │
PageController::index() ── initial state `storeAccess` {install, publish}
        │
main.js applyStoreAccess() ── page.config.canInstall / canPublish on type "store" pages
        │                     (publishRoute "CoursePackageExport" is static in manifest.json)
CnStorePage (nextcloud-vue #1268) renders Install / Publish; an older one ignores the keys
ExportRequestView ── publish button only when storeAccess.publish
```

## Decisions

### D1: A new action for installing from the store
`course-package.import` also guards the Canvas and Moodle package upload
(`CoursePackageImportController`), a heavier operation that parses foreign archives.
Widening it to teachers would widen that too. `course-store.install` is a separate row the
administrator sees under Action authorization. Seed `["admin", "instructors", "team-leads"]`:
exactly the groups the register lets create course, lesson and material objects, which
an install writes as the installing user. Coordinators are left out because the Lesson
schema does not let them create lessons.

### D2: A once-only repair step, with the defaults read from the seed
`GenericInitializeActions` seeds only an empty matrix, so on an existing instance a new
row is absent (and `getAllowedGroups()` falls back to `["admin"]`) and a changed default
never arrives. `ApplyStoreRightsDefaults` runs in `<post-migration>` after
`InitializeActions`: when the marker `store_rights_defaults_applied` is unset it adds
`course-store.install` if absent, replaces `course-package.share` only when it is exactly
`["admin"]`, writes the matrix, and sets the marker. Both values come from
`lib/actions.seed.json`, the file `ActionMatrixController` already reads, so the defaults
are written once. An empty matrix (a failed seed) is left to `InitializeActions`.

Considered: a Nextcloud migration class. Rejected: learniq's version bumps come from the
release workflow, and every other data fix-up in learniq is a repair step with an
idempotency check; the marker gives the same once-only guarantee.

### D3: One service answers what the user may do in the store
`StoreAccessService::forUser(IUser)` returns `{install, publish}`: install is
`ActionAuthService::can(user, 'course-store.install')`; publish is
`can(user, 'course-package.share')` AND `CourseStorePublisher::supportsPublish()` AND
`mayPublish(user)`. `PageController` provides it as initial state. The controller
endpoints keep their own checks; the state only decides what to render.

### D4: The page receives per-user booleans from boot, not from the manifest
The manifest sentinel vocabulary has no per-user token (`@runtime` is deprecated), and the
manifest runtime (`runtime.user`) feeds `visibleIf`, not page props. `src/utils/storeAccess.js`
`applyStoreAccess(manifest, access)` writes `canInstall` and `canPublish` into the config
of every `type: "store"` page; `main.js` calls it right after `buildManifest()`. The
destination is static, so `publishRoute: "CoursePackageExport"` sits in `manifest.json`.

### D5: No nextcloud-vue pin until a release carries #1268
nextcloud-vue #1268 is open and unreleased; learniq's range is `^2.57.1` with 2.57.1
locked. `CnPageRenderer` passes every config key as a prop, and a `CnStorePage` without
the props lets them fall through as harmless DOM attributes, so Install stays
administrator-only until the lockfile moves to a release with #1268. No range change here.

### D6: Admin-only settings endpoints, write-only token
`StoreRegistrySettingsController` uses `#[AuthorizedAdminSetting(settings: AdminSettings::class)]`,
the pattern `ActionMatrixController` uses. The token is stored with
`setValueString(..., sensitive: true)`, never returned (only `tokenSet`), kept when the
request omits it, and removed when `clearToken` is true. The address must be empty or an
absolute http(s) URL without user info (the token belongs in the Bearer header, not the
URL); the register must be empty or match `^[a-z0-9][a-z0-9_-]*$`. The SSRF guard stays
where it is enforced, in the store plane at request time.

### Mixed-spec rationale
The config part is four lines: two seed values in `lib/actions.seed.json`, one
`publishRoute` key and one `team-lead` entry in the manifests. Each is meaningless without
the code that reads it, so they ship together.

### Declarative-vs-imperative decision
Not applicable: no lifecycle, aggregation, calculation, notification, relation or widget.
Authorization stays in the ADR-023 matrix, which is configuration.

## API Design

### `GET /api/admin/store-registry` (admin)
**Response:**
```json
{ "url": "https://store.example.nl", "register": "learniq", "tokenSet": true }
```

### `PUT /api/admin/store-registry` (admin)
**Request:**
```json
{ "url": "https://store.example.nl", "register": "learniq", "token": "YOUR_TOKEN_HERE", "clearToken": false }
```
**Response:** the GET shape. 400 `{"error": "..."}` for a malformed address or register.

## Nextcloud Integration
- Controllers: `StoreController` (action name), `PageController` (initial state),
  `StoreRegistrySettingsController` (new, `AuthorizedAdminSetting`).
- Services: `StoreAccessService` (new: `ActionAuthService`, `CourseStorePublisher`).
- Repair: `ApplyStoreRightsDefaults` (`IRepairStep`: `ActionAuthService`, `IAppConfig`).
- OCP: `IAppConfig` (sensitive value), `IInitialState`, `IRepairStep`.

## Security Considerations
- Wider rights by decision (D27): teachers install, team leads publish. Every endpoint keeps
  its server-side `requireAction()`; the UI change only hides buttons.
- The registry token never leaves the server: write-only in the API, sensitive in app config.
- The settings endpoints are admin-only through `AuthorizedAdminSetting`; they are not
  `NoAdminRequired`, so gate 7 does not apply.
- The repair step never narrows or widens a row an administrator changed.

## NL Design System
`NcSettingsSection`, `NcTextField`, `NcPasswordField`, `NcButton`, `NcCheckboxRadioSwitch`
and `NcNoteCard` only; no custom colours.

## File Structure
```
lib/actions.seed.json                                    course-store.install, course-package.share
lib/Repair/ApplyStoreRightsDefaults.php                   new
appinfo/info.xml                                          register the repair step
lib/Controller/StoreController.php                        ACTION_INSTALL = course-store.install
lib/Service/CourseStore/StoreAccessService.php            new
lib/Controller/PageController.php                         initial state storeAccess
lib/Controller/StoreRegistrySettingsController.php        new
appinfo/routes.php                                        two admin routes
src/utils/storeAccess.js                                  new
src/main.js                                               applyStoreAccess()
src/manifest.json                                         Store config publishRoute
src/manifest.d/learning.json                              export menu admits team-lead
src/views/ExportRequestView.vue                           publish button follows storeAccess
src/views/settings/StoreRegistrySettingsSection.vue       new
src/views/settings/AdminRoot.vue                          mount the section
```

## Seed Data
No schema or register objects change. The matrix seed (`lib/actions.seed.json`) gains one
row and changes one.
