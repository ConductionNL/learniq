---
kind: code
depends_on: [lesson-sharing-consent-gate]
---

# Proposal: lesson-sharing-via-store-plane

## Summary
Learniq's Store page becomes the place where schools find and install courses other schools share, and where a teacher publishes a course that passed the sharing gate. Discovery runs through OpenRegister's store plane: learniq declares a `StoreDescriptor` for shared course packages and asks `GenericStoreService` to search and resolve, so the SSRF guard, the redirect refusal and the registry token stay in the engine. Install imports the package through the existing `CoursePackageImportService` as a new, independent copy that keeps the licence and author. Publishing sends the consent-gated package to the registry. The store plane has no write path, so learniq posts to the registry's objects API with the configured token, guarded the same way the plane guards its reads. Sharing is free: there is no price, payment or order anywhere in this change.

## Motivation
Decision D22 (Ruben, 2026-09-27): "Lesson sharing is delivered by OpenRegister's store plane (`openspec/specs/apphost-store-plane`, `lib/AppHost/Store`), not by opencatalogi or an Edurep adapter." Assumption A10: sharing is free and a shared package carries a licence, NL-LOM metadata and a "may leave the school" check; the two preceding changes of this lane deliver those (#1026, #1029).

Recon E, section 1c: the Store page exists (`src/manifest.json`, `type: "store"`) but "has no concept of `Course`/`Lesson` objects at all", and no cross-school publish-and-discover flow exists. Measured while writing this proposal: learniq's `appinfo/routes.php` declares no `/api/store/items` route, so the page's own request goes nowhere today. The federated configuration-set store the manifest declares has never been reachable from learniq.

Competitor evidence (recon E section 2): Wikiwijs lets a teacher find an arrangement and make "their own editable copy" of it; Moodle closed moodle.net in 2019 and MoodleNet in 2026, which is why this change keeps the registry an ordinary OpenRegister instance a school board or Conduction can run, not a new service.

## Affected Projects
- [x] Project: `learniq`: `lib/Controller/StoreController.php` (new: search, install, publish), `lib/Service/CourseStore/` (new: descriptor, registry object builder, publisher, installer, URL guard), `lib/Service/CoursePackage/CoursePackageObjectWriter.php` and `LearniqJsonCourseImporter.php` (carry course metadata into the copy), `appinfo/routes.php` (three routes), `lib/Settings/learniq_register.json` (new `SharedCoursePackage` schema for an instance that acts as the registry), `src/manifest.json` (store block and page), `src/views/ExportRequestView.vue` (publish button), `tests/Stubs/` (store plane stubs), `l10n/`, docs, tests.

## Scope

### In Scope
- A `StoreDescriptor` for schema `shared-course-package` in register `learniq` of the registry, with card fields title, subject, level, goals covered, language, licence and author (plus slug, description, version, and a one-line summary the shared store page shows under the title).
- `StoreController` (learniq's own, which the engine's alias then leaves alone, the openbuild pattern): `GET /api/store/items` searches through `GenericStoreService`; `POST /api/store/items/{slug}/install` resolves through the plane and imports through `CoursePackageImportService`; `POST /api/store/publish` publishes a course.
- Install creates a copy: new objects, a new course code, the licence, author, subject, levels, language, level and description carried over so attribution survives. Behind the existing action `course-package.import`.
- Publish: the sharing gate and a `CourseShareConsent` with purpose `store` first (change 5), then an SSRF-guarded, redirect-refusing POST with the registry token as a Bearer header. Behind the action `course-package.share`. Packages over 20 MB are refused.
- `SharedCoursePackage` schema in learniq's register, so any learniq instance (a school board's, or Conduction's) can serve as the registry.
- Store page copy and menu: teachers, coordinators and team leads see it too.

### Out of Scope
- Payments, prices or licences for sale.
- The federated configuration-set store the manifest declared (`openregister.configset`, `openregister.flows`): it never had a route in learniq; configuration sets stay installable through OpenRegister's own configuration import.
- A write path in OpenRegister's store plane. Named as the follow-up that lets `CourseStorePublisher` collapse into one call.
- Moderation tooling on the registry beyond OpenRegister's object screens and the `administration-managers` update grant.
- An admin settings screen for the registry URL and token (set with `occ config:app:set`, documented).

## Approach
Discovery and resolve through the plane, as openbuild does; install and publish in learniq because a course tree import and a gated publish are learniq's own operations (ADR-080 Decision 3). Details and the write-path choice in design.md.

## New Dependencies
None. Uses OpenRegister's `GenericStoreService`, `StoreDescriptor` and `SecurityService`, all already on the instance.

## Impact
- New routes `/api/store/items`, `/api/store/items/{slug}/install`, `/api/store/publish`.
- The Store page lists shared courses instead of an unreachable configuration-set list.
- New schema; `info.version` bump.

## Cross-Project Dependencies
- Reads OpenRegister's store plane (no change there). A registry instance runs learniq (or at least its register) and gives the publishing school a token for an account in `instructors`, `team-leads`, `coordinators` or `administration-managers`.

## Risks

### Risk 1: Hydra gate 62 flags the publisher
**Severity:** Medium. **Mitigation:** gate 62 fails any file that builds an OpenRegister objects-API URL and holds an HTTP client, to stop apps re-implementing store discovery. The publisher writes, it does not discover, and the plane offers no write path. The finding is reported in the PR, not hidden; the follow-up is `GenericStoreService::publish()` in OpenRegister.

### Risk 2: Install is admin-only in the shared store page
**Severity:** Medium. **Mitigation:** `CnStorePage` shows the Install button to Nextcloud admins only. Teachers can browse and publish; an admin installs. Broadening needs a prop in `@conduction/nextcloud-vue`, named as a follow-up.

### Risk 3: Moderation duty
**Severity:** Medium. **Mitigation:** every published package carries a consent record at the sending school and a licence and author; the registry's `administration-managers` can correct and an admin can delete. The recon's MoodleNet precedent is the reason the registry stays an ordinary instance.

### Risk 4: Stacked on two open PRs
**Severity:** Low. **Mitigation:** cut from `feat/lesson-sharing-consent-gate`, which sits on `feat/course-content-metadata`; lands after #1026 and #1029.

## Rollback Strategy
Revert the commit. The routes go away, the Store page returns to its unreachable state, and the schema stays declared but unused.

## Open Questions
None.
