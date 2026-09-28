---
kind: code
---

# Proposal: store-publish-through-plane

## Summary
Learniq's course store publishes through OpenRegister's store plane instead of its own HTTP
call. `CourseStorePublisher` hands the registry object to `GenericStoreService::publish()`
(openregister #4079, landed 84352bae). `CourseStoreDescriptor` names what may leave this
server (`publishFields`) and who may send it (`publishGroups`, read from learniq's own
permission matrix action `course-package.share`). `StoreController` asks the plane's
`StoreActionAuthorizer::canPublish()` before it builds a package. `CourseStoreUrlGuard`,
the objects-API URL and the `IClientService` call leave learniq, so hydra gate 62 has
nothing left to flag.

## Motivation
Decision D22 (Ruben, 27 September): lesson sharing is delivered by OpenRegister's store
plane. When learniq #1043 landed, the plane had no write path, so learniq posted to the
registry's objects API itself and gate 62 flagged it (r2 lane log, change 6: "gate-62 NEW
deliberate ... clean fix is GenericStoreService::publish() in OpenRegister"). OpenRegister
#4079 added that write path, with a section "How learniq adopts this" in
`openregister/openspec/changes/store-plane-publish/design.md`. This change is that
adoption.

Round 2 tracker follow-up: "learniq #1043 adopts GenericStoreService::publish".

## Affected Projects
- [x] Project: `learniq`: `lib/Service/CourseStore/CourseStoreDescriptor.php`,
  `CourseStorePublisher.php`, `CourseStoreUrlGuard.php` (removed),
  `lib/Controller/StoreController.php`, `src/views/ExportRequestView.vue` (outcome copy),
  `tests/Stubs/` (store plane stubs follow #4079), `psalm.xml`, `l10n/`, tests.

## Scope

### In Scope
- Publish through `GenericStoreService::publish(descriptor, payload)`; the plane owns the
  SSRF guard, the redirect refusal, the timeouts, the Bearer token, the 20 MiB cap and the
  check that the registry stored the slug it was sent.
- `CourseStoreDescriptor::PUBLISH_FIELDS`, the properties of a `shared-course-package`
  object that may travel; `publishGroups` from `ActionAuthService::getAllowedGroups('course-package.share')`,
  so the matrix an administrator edits stays the one source of truth.
- `StoreController::publish()` keeps `requireAction('course-package.share')` and adds the
  plane's `canPublish()` (403 when false). New outcomes map to statuses:
  `not_publishable` 500, `rate_limited` 429, `store_invalid_response` 502.
- Duck-typed against the installed OpenRegister: on a version without the publish path
  (no `publishFields` on `StoreDescriptor`, no `publish()` or no `canPublish()`), search
  and install keep working and publish answers `publish_not_supported` (501) without a
  request.
- The publish screen names the new outcomes in plain words.

### Out of Scope
- Who may install and who may publish by default (D27). That is the next change,
  `store-rights-for-teachers`, stacked on this one.
- The registry connection settings screen (also `store-rights-for-teachers`).
- Updating or withdrawing a published course: a new version is a new slug, as before.

## Approach
Follow the OpenRegister design's adoption section. Keep learniq's outcome strings so the
frontend contract does not move. Probe the installed OpenRegister with
`property_exists(StoreDescriptor::class, 'publishFields')`, `method_exists(... 'publish')`
and `method_exists(... 'canPublish')` before using any of them, because a named argument
the constructor does not know is a fatal error, not a false.

## New Dependencies
None. OpenRegister was already a hard dependency; this uses a newer method of it when
present.

## Impact
- `POST /api/store/publish`: same request, same success body. New statuses 403, 429, 500
  and 501 for the new refusals. The browser never sees the registry URL or token, as before.
- Gate 62 (store plane) stops flagging `CourseStorePublisher`.

## Cross-Project Dependencies
Consumes openregister #4079 (merged). No learniq change is needed in OpenRegister, and no
other app is affected.

## Risks

### Risk 1: An older OpenRegister on an instance
**Severity:** Medium. **Mitigation:** every new call is probed first; publish answers 501
`publish_not_supported` and nothing leaves. Search and install are untouched.

### Risk 2: A field with personal data in PUBLISH_FIELDS
**Severity:** Medium. **Mitigation:** the list names only the properties
`CourseStoreRegistryObject` already builds after the sharing gate stripped the package;
a unit test pins that every built key is in the list and nothing else.

### Risk 3: The matrix names no group for `course-package.share`
**Severity:** Low. **Mitigation:** `getAllowedGroups()` falls back to `["admin"]`, so an
administrator can still publish; an explicitly empty list refuses everyone, which the
plane logs.

## Rollback Strategy
Revert the PR. The old publisher and guard come back; no data changes either way.
