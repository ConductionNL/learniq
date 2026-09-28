# Design: lesson-sharing-via-store-plane

## Architecture Overview
```
Store page (CnStorePage, type: store)
  GET  /api/store/items            ─┐
  POST /api/store/items/{slug}/install ├─ OCA\Learniq\Controller\StoreController
Export page (share mode)            │
  POST /api/store/publish          ─┘
        │
        ├─ search / resolve ──► OpenRegister GenericStoreService(CourseStoreDescriptor)   (the store plane)
        ├─ install ───────────► CourseStoreInstaller ─► CoursePackageImportService (learniq JSON, copy)
        └─ publish ───────────► CourseShareExportService::buildPackage(purpose: store)   (gate + consent, change 5)
                                  └► CourseStorePublisher ─► POST <registry>/…/objects/<register>/shared-course-package
```
Because learniq now ships `Controller\StoreController`, OpenRegister's `Bootstrap::aliasStoreController()` leaves learniq alone ("unlessLeafDefinesIt"), exactly as it does for openbuild. The three routes are added to `appinfo/routes.php`; before this change learniq declared no store route, so the page's request had nowhere to go.

## API Design

### `GET /api/store/items?q=&kind=`
**Response:** `{"outcome": "ok|not_configured|store_unreachable|store_invalid_response", "cards": [...], "kinds": ["course-package"], "builtIn": []}`; 401 without a session.

### `POST /api/store/items/{slug}/install`
**Response 200:** `{"success": true, "courseId": "<uuid>", "reportId": "<uuid>", "components": [{"name": "Betoog", "status": "installed"}]}`; 400 malformed slug; 403 (OCS) action refused; 404 unresolved.

### `POST /api/store/publish`
**Request:** `courseId`, `noPupilData`, `rightsCleared`.
**Response 200:** `{"outcome": "ok", "slug": "course-package-betoog-schrijven-havo-4-1a2b3c4d"}`, or `{"outcome": "not_configured"}`; 422 with `blockers`; 502 `store_unreachable` or `store_rejected`; 413 `too_large`.

## Nextcloud Integration
- Controllers: `StoreController` (`#[NoAdminRequired]`, session guard; install and publish also `ActionAuthService::requireAction`).
- Services: `CourseStoreDescriptor`, `CourseStoreRegistryObject` (pure), `CourseStorePublisher` (`IClientService`, `IAppConfig`), `CourseStoreUrlGuard` (wraps `SecurityService::assertSafeFetchUrl()` so tests can replace it), `CourseStoreInstaller` (temp file + `CoursePackageImportService`).
- Registry connection: learniq's `IAppConfig` keys `registry_url`, `registry_token` (store as sensitive), `registry_register`, the same keys the plane reads for `appId: learniq`.

## Decisions

### D1: Discovery through the plane, install and publish in learniq
The plane covers discovery (configure, search, resolve) and leaves install to the app (ADR-080 Decision 3, the openbuild pattern). A course import remaps every reference to new ids, which the engine's generic installer cannot do (it strips identity and writes components one by one). So install goes through `CoursePackageImportService`, which already imports learniq JSON.

### D2: The write path
The plane has no write path (`GenericStoreService` exposes `isConfigured`, `search`, `resolve`). Considered: (a) OpenRegister federation shares with write-through: they need a per-school share token and a shadow schema, not the store's registry settings, and the provider class says write-through lives elsewhere; (b) a new OpenRegister endpoint: another repository, outside this lane. Chosen, as the lane brief prescribes: POST to the registry's objects API with the configured token, applying the plane's own rules: `SecurityService::assertSafeFetchUrl()` first, `allow_redirects: false`, 10 second timeouts, the token only as a Bearer header and never in a response or a log line. When OpenRegister adds `GenericStoreService::publish()`, `CourseStorePublisher` becomes one call.

### D3: The registry object
Cards are normalised by casting each mapped property to a string (`GenericStoreService::normaliseCard()`), so every card field is a string on the registry object: `level` joins the levels, `goals` joins the goals. The arrays travel next to them (`levels`, `goalsCovered`) for filtering. `cardLine` (`subject · levels · licence`) maps to the card's `typeName`, the one extra line `CnStorePage` shows under the title. `publisher` maps to `author`, so the credit is on the card. The full share package travels as `package`.

### D4: Slugs
`course-package-<kebab title, max 40>-<first 8 of sha1(package)>`: readable, distinct per content, and matching the engine's slug pattern.

### D5: Install keeps the credit
`LearniqJsonCourseImporter` passes the source course's metadata to `CoursePackageObjectWriter::createCourse()`, which accepts only valid values (level and licence from their enums, NL-LOM levels filtered, language as two letters, strings trimmed). The copy's code is new and it starts as a draft.

### D6: One registry for many schools
`SharedCoursePackage` lives in learniq's own register, so a school board's learniq instance, or Conduction's, can be the registry without new software. `registry_register` defaults to `learniq`.

### D7: Who does what
Search: any signed-in user. Install: `course-package.import` (admin by default); the shared page shows Install to Nextcloud admins only. Publish: `course-package.share` plus the gate.

### Declarative-vs-imperative decision
| Behaviour | Path | Rationale |
|---|---|---|
| Discovery | Engine (store plane) | Owned by OpenRegister. |
| Install | Imperative, existing import service | A tree import with reference remapping. |
| Publish | Imperative (external HTTP) | Outbound write to another instance. |
| Registry storage | Declarative schema | Plain object with an `authorization` block. |

## Security Considerations
- SSRF: the registry URL is admin-set; every publish URL passes `assertSafeFetchUrl()` (private, loopback, link-local, unresolvable and non-http(s) refused) and redirects are refused, so a public host cannot bounce the token to an internal address.
- Token: Bearer header only; responses and logs name the outcome, never the token or the upstream body.
- Import: the package comes off the network; it goes through the same importer as an uploaded file, with RBAC applied to every write, and never overwrites (new objects only).
- The consent record stays at the sending school; the package names only the chosen author (change 5).

## NL Design System
No new component: the Store page is `CnStorePage`; the publish button is an `NcButton` on the existing export page.

## File Structure
```
lib/Controller/StoreController.php
lib/Service/CourseStore/CourseStoreDescriptor.php
lib/Service/CourseStore/CourseStoreRegistryObject.php
lib/Service/CourseStore/CourseStorePublisher.php
lib/Service/CourseStore/CourseStoreUrlGuard.php
lib/Service/CourseStore/CourseStoreInstaller.php
lib/Service/CoursePackage/CoursePackageObjectWriter.php     createCourse(metadata)
lib/Service/CoursePackage/LearniqJsonCourseImporter.php     passes course metadata
appinfo/routes.php                                         3 routes
lib/Settings/learniq_register.json                         SharedCoursePackage, info.version
lib/Settings/learniq_mock_register.json                    demo rows
src/manifest.json                                          store block, Store page copy, menu audience
src/views/ExportRequestView.vue, src/utils/customPages.js  publish button
tests/Stubs/AppHost/Service/{GenericStoreService,StoreDescriptor}.php, tests/Stubs/Service/SecurityService.php
psalm.xml                                                  stub entries
tests/Unit/...                                             see tasks
docs/user-guide/user/02-create-course.md, docs/user-guide/admin/03-admin-settings.md
```

## Seed Data

### Schema: `shared-course-package`
| Field | Object 1 |
|-------|----------|
| id | `00000000-0000-0000-0000-0000000f0201` |
| slug | `course-package-voorbeeld-betoog-schrijven-havo-4-00000001` |
| kind | `course-package` |
| title | `Voorbeeld: Betoog schrijven, havo 4` |
| subject | `Nederlandse taal` |
| level | `havo` |
| goals | `Een betoog opbouwen; Bronnen vermelden` |
| language | `nl` |
| license | `CC-BY-SA-4.0` |
| author | `Voorbeeldschool, sectie Nederlands` |
| cardLine | `Nederlandse taal · havo · CC-BY-SA-4.0` |
| version | `1` |
| lessonCount | `2` |
| package | a minimal learniq JSON package with one course and two lessons |
| tenant_id | `00000000-0000-0000-0000-000000000001` |

Three generated demo rows in the demo register.

## Trade-offs
Keeping install admin-only (through the shared page) is slower for teachers than a one-click copy, but it keeps a third-party package from landing in a school without an administrator's decision, which matches the engine's default install posture.
