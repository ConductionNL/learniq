# Design: store-publish-through-plane

## Architecture Overview

Before: `StoreController::publish()` → sharing gate + consent → `CourseStorePublisher`, which
read `registry_url` and `registry_token` from learniq's app config, built
`<url>/index.php/apps/openregister/api/objects/<register>/shared-course-package`, ran it through
`CourseStoreUrlGuard` (a seam over `SecurityService::assertSafeFetchUrl()`) and POSTed with
`IClientService`. Gate 62 flagged exactly that.

After:

```
StoreController::publish()
  ├─ requireAction('course-package.share')          learniq matrix (ADR-023)
  ├─ publisher->isConfigured()                        plane: registry_url set?  no → 200 not_configured
  ├─ publisher->supportsPublish()                     probe OpenRegister        no → 501 publish_not_supported
  ├─ publisher->mayPublish(user)                      plane: canPublish()       no → 403 forbidden
  ├─ shareService->buildPackage(..., 'store')         sharing gate + consent    blocked → 422 blockers
  └─ publisher->publish(package)
        └─ GenericStoreService::publish(descriptor, registryObject->build(package))
              guard, no redirects, timeouts, Bearer, 20 MiB, stored-slug check
```

`CourseStoreDescriptor` gains `ActionAuthService` and passes
`publishFields: PUBLISH_FIELDS` and `publishGroups: getAllowedGroups('course-package.share')`
when the installed OpenRegister supports them.

## Decisions

### D1: Probe, then call; never pass a named argument the constructor may not know

`new StoreDescriptor(publishFields: ...)` on an OpenRegister before #4079 is an `Error`
("Unknown named parameter"), not a false. `CourseStoreDescriptor::supportsPublish()` checks
`property_exists(StoreDescriptor::class, 'publishFields')` (promoted constructor properties
are declared properties) and `method_exists(StoreDescriptor::class, 'isPublishable')`, and
`descriptor()` builds its arguments as an array and spreads them, adding the two publish keys
only when supported. `CourseStorePublisher::supportsPublish()` adds
`method_exists($storeService, 'publish')`.

### D2: The authorizer is resolved lazily and fails closed

`StoreActionAuthorizer` lives in `OCA\OpenRegister\AppHost\Store`, and an older OpenRegister
may not have the class at all. A constructor type-hint would make the DI container fail to
build `StoreController`, which would take search and install down with it. The publisher
therefore resolves it from the container inside `mayPublish()`. A class that is absent,
unconstructible, lacks `canPublish()` or throws means false (logged), never true. The
result is a boolean, never null, so no caller can read "unavailable" as "skipped"
(the unsafe-auth-resolver shape).

### D3: learniq keeps its own outcome strings

The plane's outcome strings are the ones learniq already returned (`ok`, `not_configured`,
`too_large`, `store_unreachable`, `store_rejected`), so `ExportRequestView` keeps working.
The publisher's constants point at the plane's constants where the plane has them, and add
`forbidden` and `publish_not_supported` for the two refusals learniq itself makes.

### D4: `publishGroups` read at descriptor build time

The descriptor is built per request, so a matrix edit takes effect on the next request
with no cache to clear. `getAllowedGroups()` reads one app config value.

### Declarative-vs-imperative decision

Not applicable: no lifecycle, aggregation, calculation, notification, relation or widget.
The publish is an external integration (ADR-031 exception), and it already lived in a
service; this change moves the transport into OpenRegister.

## API Design

### `POST /api/store/publish`
**Request** (unchanged):
```json
{ "courseId": "<course-uuid>", "noPupilData": true, "rightsCleared": true }
```
**Response** (unchanged on success):
```json
{ "outcome": "ok", "slug": "course-package-betoog-schrijven-1a2b3c4d" }
```
New refusals: 403 `{"outcome":"forbidden"}`, 429 `rate_limited`, 500 `not_publishable`,
501 `publish_not_supported`, 502 `store_invalid_response`.

## Nextcloud Integration
- Controllers: `StoreController` (publish path only).
- Services: `CourseStorePublisher` (`GenericStoreService`, `CourseStoreDescriptor`,
  `CourseStoreRegistryObject`, `Psr\Container\ContainerInterface`, `LoggerInterface`),
  `CourseStoreDescriptor` (`ActionAuthService`).
- OpenRegister: `GenericStoreService::publish()`, `StoreDescriptor` (`publishFields`,
  `publishGroups`), `StoreActionAuthorizer::canPublish()`.
- Removed: `CourseStoreUrlGuard`, the `SecurityService` test stub and its psalm entry.

## Security Considerations
- The SSRF guard, redirect refusal and token handling move into the one place that already
  owned them for discovery. Learniq no longer reads the token at all.
- Two authorization checks stay in series: learniq's matrix, then the plane's group match on
  the same groups. They agree except when the matrix names nobody, where the plane refuses
  everyone (fail closed) and learniq answers 403 before building a package.
- `PUBLISH_FIELDS` is an allowlist of properties that exist after the sharing gate stripped
  the package. A unit test pins it against what `CourseStoreRegistryObject` builds.

## NL Design System
Only copy changes in `ExportRequestView.vue`; no components or tokens change.

## File Structure
```
lib/Controller/StoreController.php                     publish(): probe, canPublish, status map
lib/Service/CourseStore/CourseStoreDescriptor.php      PUBLISH_FIELDS, ActionAuthService, supportsPublish()
lib/Service/CourseStore/CourseStorePublisher.php       plane-backed publish, mayPublish(), supportsPublish()
lib/Service/CourseStore/CourseStoreUrlGuard.php        removed
src/views/ExportRequestView.vue                        copy for forbidden, rate_limited, publish_not_supported
tests/Stubs/AppHost/Service/GenericStoreService.php    publish() + outcomes (as #4079)
tests/Stubs/AppHost/Service/StoreDescriptor.php        publishFields, publishGroups, isPublishable()
tests/Stubs/AppHost/Store/StoreActionAuthorizer.php    new stub
tests/Stubs/Service/SecurityService.php                removed
```

## Seed Data
None. No schema, register or object changes.
