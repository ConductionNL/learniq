---
kind: code
depends_on: [store-publish-through-plane]
---

# Proposal: store-rights-for-teachers

## Summary
Any teacher installs a shared course from the Store as their own copy, and publishing to
the Store is limited to the groups learniq's permission matrix names for
`course-package.share`, by default the team leads. A new matrix action
`course-store.install` carries the install right. Existing installs get the new defaults
once, through a repair step that never overrides an administrator's later choice. The
Store page tells the shared `CnStorePage` who sees Install and Publish (nextcloud-vue
#1268), the publish button on the export screen follows the same answer, and team leads
can reach that screen. An administrator connects the course registry on the Learniq admin
settings page instead of with `occ`.

## Motivation
Decision D27 (Ruben, 27 September): "Store rights: any teacher installs a shared course as
a copy; publishing is limited to a group named in learniq's permission matrix (default team
leads). Needs a nextcloud-vue prop on CnStorePage."

Measured on development at a84b6273:
- Install requires `course-package.import`, seeded `["admin"]`, and `CnStorePage` shows
  Install to administrators only. A teacher sees the Store menu (its `visibleIf` includes
  `instructor`) and cannot install anything.
- `course-package.share` is seeded `["admin"]`, so only administrators publish.
- The "Export course package" menu, where Publish lives, hides from `team-lead`.
- The registry connection is `occ config:app:set` only (r2 lane follow-up: "registry
  connection is occ-only").

## Affected Projects
- [x] Project: `learniq`: `lib/actions.seed.json`, `lib/Repair/ApplyStoreRightsDefaults.php`
  (new), `appinfo/info.xml`, `lib/Controller/StoreController.php`,
  `lib/Service/CourseStore/StoreAccessService.php` (new), `lib/Controller/PageController.php`,
  `lib/Controller/StoreRegistrySettingsController.php` (new), `appinfo/routes.php`,
  `src/views/settings/StoreRegistrySettingsSection.vue` (new), `src/views/settings/AdminRoot.vue`,
  `src/utils/storeAccess.js` (new), `src/main.js`, `src/manifest.json`,
  `src/manifest.d/learning.json`, `src/views/ExportRequestView.vue`, `l10n/`, docs, tests.

## Scope

### In Scope
- Matrix: `course-store.install` seeded `["admin", "instructors", "team-leads"]`;
  `course-package.share` seeded `["admin", "team-leads"]`. `StoreController::install()`
  requires `course-store.install`. The Canvas and Moodle package import keeps
  `course-package.import`, admin-only.
- Repair step, once per instance: adds `course-store.install` when the matrix lacks it,
  and moves `course-package.share` from exactly `["admin"]` (the untouched old default) to
  the new default. A marker keeps it from running again, so an administrator who later
  narrows either row keeps that choice.
- `StoreAccessService::forUser()` answers `{install, publish}` for the signed-in user;
  `PageController` provides it as initial state `storeAccess`.
- `src/main.js` writes `canInstall` and `canPublish` into every `type: "store"` page's
  config; the Store page config names `publishRoute: "CoursePackageExport"`.
- The export screen shows "Publish to the course store" only when `storeAccess.publish`.
  The export menu entry admits `team-lead`.
- Admin settings section "Course store": registry address, register, and a write-only
  token (the page shows whether one is set, never the value). Admin-only endpoints
  `GET` and `PUT /api/admin/store-registry`. `occ` keeps working on the same keys.

### Out of Scope
- Pinning the nextcloud-vue range. #1268 is not released; learniq writes the three keys
  now and an older `CnStorePage` ignores them, so the administrator default holds until a
  release carrying #1268 reaches learniq's lockfile.
- A connection test button on the settings section; the Store page already reports
  `not_configured` and `store_unreachable`.
- Who may publish per course or per department.

## Approach
Keep every decision server-side in the ADR-023 matrix: the endpoints enforce it, the
initial state reports it, and the page only renders it. The repair step reads the new
defaults from `actions.seed.json`, the single place they are written.

## New Dependencies
None. Uses nextcloud-vue #1268's props when a release carries them.

## Impact
- `POST /api/store/items/{slug}/install` checks `course-store.install` instead of
  `course-package.import`.
- New admin-only routes `GET` and `PUT /api/admin/store-registry`.
- New initial state key `storeAccess`.

## Cross-Project Dependencies
- nextcloud-vue #1268 (`CnStorePage` `canInstall`, `canPublish`, `publishRoute`): open,
  not released. Learniq works with and without it.
- Stacked on learniq `store-publish-through-plane` (#1128): `StoreAccessService` uses its
  `CourseStorePublisher::supportsPublish()` and `mayPublish()`.

## Risks

### Risk 1: Widening publish on an existing install
**Severity:** Medium. **Mitigation:** the repair step only touches `course-package.share`
when it still holds the old untouched default `["admin"]`, runs once, and D27 is the
product decision to widen it. An administrator narrows it back under Action authorization.

### Risk 2: A teacher installs a course that fails halfway
**Severity:** Low. **Mitigation:** the default install groups are exactly the groups that
may create courses, lessons and materials in learniq's register; coordinators are left
out because they cannot create lessons.

### Risk 3: Showing Install before the library supports it
**Severity:** Low. **Mitigation:** none needed; an older `CnStorePage` ignores the keys and
keeps showing Install to administrators only.

## Rollback Strategy
Revert the PR. The matrix rows the repair step wrote stay; an administrator can narrow them
under Action authorization. The registry keys are the same `occ` keys as before.
