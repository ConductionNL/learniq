> **Round 5 (2026-09-28): built.** Still valid after D7: learning records stay in learniq (content formats).
> Differences from July: the app is learniq; the `xapi-statement` schema had NO authorization block at all
> (any signed-in user could create a statement with any `verified_actor_id` through the generic object API),
> so task 4.1 adds one (read: staff + own rows by `verified_actor_id`; create/update: hr, compliance-officers)
> and the LRS writes after stamping. The lesson player's cmi5 branch already existed (`src/utils/cmi5Launch.js`)
> and called `POST /api/lessons/{id}/cmi5-launch`, so the endpoint follows that contract, with a single-use
> fetch URL for the token. The SCORM 1.2 shim now posts to the LRS instead of the generic object API.
> The key is instance-wide (not per tenant); there is no admin screen for it yet, as for the credential key.

## 1. Key provisioning

- [x] 1.1 Add an admin key-generation step for the cmi5 RS256 launch key-pair, reusing the
      `KeyAdminController::generateKey()` pattern (`lib/Controller/KeyAdminController.php:102-103`) —
      generate, store the private key in `ICrypto` under `Cmi5LaunchTokenService::PRIVATE_KEY_NAME`, and
      expose a `keyStatus`-style read for the admin settings UI.
- [x] 1.2 Add a rotation throttle mirroring `KeyAdminController::checkRotationThrottle()`
      (`lib/Controller/KeyAdminController.php:234`).

## 2. Cmi5LaunchTokenService

- [x] 2.1 Implement `isEnabled()` (`lib/Service/Cmi5LaunchTokenService.php:105-111`) to check for the
      presence of the RS256 private key via `ICrypto`, replacing the hardcoded `return false`.
- [x] 2.2 Implement `mintLaunchToken()` (`lib/Service/Cmi5LaunchTokenService.php:142-151`) per the method's
      own docblock: decrypt the private key, build the JWT header/claims (`iss`, `sub`, `aud`, `iat`, `exp`,
      `jti`, `activityId`, `registration`), sign with `openssl_sign(..., OPENSSL_ALGO_SHA256)`, base64url
      encode+concat. Follow the JWS pattern already used by
      `CredentialVerifyController::verifyJwsSignature` (`lib/Controller/CredentialVerifyController.php:255`)
      for consistency.
- [x] 2.3 Remove the `@phpstan-ignore` suppressions on the now-used `$crypto` constructor property and the
      `PRIVATE_KEY_NAME` constant.
- [x] 2.4 Unit tests: `isEnabled()` false before key provisioning, true after; `mintLaunchToken()` produces a
      verifiable RS256 JWT with the documented claim set.

## 3. LRS ingest controller

- [x] 3.1 Create `lib/Controller/LrsController.php`: `POST /api/lrs/statements` accepts an xAPI 1.0.3
      envelope, authenticates via NC session or the cmi5 launch JWT (verify signature + `exp` using the
      same public-key-resolution style as `CredentialVerifyController::verifyJwsSignature`), stamps
      `verified_actor_id` from the authenticated identity (never `payload.actor.*`, per
      `lib/Lifecycle/XapiCompletionHandler.php:216-221`), and writes via
      `ObjectService::saveObject('XapiStatement', ...)`.
- [x] 3.2 `GET /api/lrs/statements` queries via `ObjectService::findAll`, scoped to the caller's own tenant
      (`tenant_id`) and, for non-admin callers, to their own `verified_actor_id` — follow the tenant-scoping
      pattern from the sibling `fix-cross-tenant-idor-planid-lookups` change.
- [x] 3.3 Register both routes in `appinfo/routes.php` with the correct auth attribute
      (`#[NoAdminRequired]`; the POST path additionally validates the launch JWT in-body for AU callers that
      have no NC session).
- [ ] 3.4 (partly: `LrsControllerTest` covers stamping, scoping and refusal with the real ingest service over a mocked ObjectService; an integration run against OpenRegister and its audit trail was not possible in this lane, no instance) Integration test: POST a `cmi5.completed` statement authenticated as a launched AU → assert it is
      queryable via GET, `verified_actor_id` is the authenticated learner (not the payload's claimed actor),
      and the OR audit-trail entry `xapi.statement.received` exists (the schema's `appendOnly` lifecycle
      fired).
  - r5-live, 2026-09-29, shared dev instance, still open. Works live: generating the launch key, a learner launch (200 with endpoint, fetchUrl, actor, registration), and redeeming the fetch code (200, auth-token). Blocked by two findings, each now with its own lane:
  - (1) OpenRegister: `POST /api/lrs/statements` with a valid token answers 500 `SCHEMA_APPEND_ONLY ... update operations are not permitted`. OR treats any save with a uuid on an append-only schema as an update, and the ingest passes the xAPI statement id. 0 statements stored.
  - (2) `Authorization: Basic <token>`, as cmi5 AUs send it, is refused with 401 by Nextcloud's auth layer before LrsController runs; `Bearer <token>` reaches it.
  - (2) fixed by #1444 and proven live on 71a2c414: `Basic <auth-token>` gets past authentication (500 from storage, finding 1); a tampered auth-token gets LrsController's own 401. (1) waits on OpenRegister #4173 reaching the instance's openregister checkout; on 51c8b1ef the statement POST still answers 500 `The statements could not be stored`.
- [x] 3.5 Security test: POST a statement with `payload.actor.account.name` set to a different learner's UUID
      → assert `verified_actor_id` is still the authenticated caller's own identity, not the payload claim.

## 4. Relax the xapi-statement authorization stopgap

- [x] 4.1 In `lib/Settings/scholiq_register.json`, change `XapiStatement.x-openregister-authorization.create`
      from `["admin"]` to the grant appropriate for the new ingest controller's write path (app-internal
      write, or `["user"]` now that `verified_actor_id` is server-stamped) and remove the `#TBD` stopgap
      `_comment` (line 1312-1317), replacing it with a comment pointing at this change.
- [x] 4.2 Confirm no other caller relies on the admin-only restriction (grep for direct
      `XapiStatement`/`xapi-statement` object creation outside tests).

## 5. Launch endpoint wiring

- [x] 5.1 Add a launch-token endpoint (new controller method, or extend the Lesson controller if a
      `lessonId` launch route already exists) that checks `Cmi5LaunchTokenService::isEnabled()` and returns
      HTTP 503 with a human-readable body when false (per the service's documented contract at
      `lib/Service/Cmi5LaunchTokenService.php:91-96`); otherwise calls `mintLaunchToken()` and returns the AU
      launch URL + token.
- [x] 5.2 Wire the frontend Lesson player (if a `cmi5` content-type branch exists in
      `src/views/**/LessonPlayer*`) to call the new launch endpoint before opening the AU iframe/window; if
      no such Vue surface exists yet, note it as a separate, explicitly out-of-scope follow-up (frontend
      course-player work) rather than silently expanding this change's scope.

## 6. Docs + specs + traceability

- [x] 6.1 Update `openspec/WEDGE-PLAN.md:39` to reflect the actual split (cmi5/xAPI ingest built by this
      change; SCORM shim / Common Cartridge importer still not built) instead of a blanket "built".
- [x] 6.2 Add `@spec openspec/changes/cmi5-xapi-lrs-ingest/tasks.md#task-N` docblock tags to
      `LrsController`, the updated `Cmi5LaunchTokenService` methods, and the launch endpoint.
- [x] 6.3 Run `composer check:strict` on all touched/new PHP files and fix any pre-existing warnings
      encountered in them (per CLAUDE.md).
- [x] 6.4 Run `openspec validate cmi5-xapi-lrs-ingest --strict` and resolve any errors.

## 7. Basic auth reachability

- [x] 7.1 Hand out the auth-token as the base64 of the launch JWT (`Cmi5LaunchTokenService::authToken()`), so a
      cmi5 AU's `Authorization: Basic <auth-token>` is not read by Nextcloud as a `user:password` login and
      rejected before `LrsController` runs. Decision and live proof in `design.md`.
- [x] 7.2 Verify the credential with `Cmi5LaunchTokenService::verifyAuthToken()`, which accepts the auth-token
      and, for Bearer callers, the bare JWT.
- [x] 7.3 Unit tests: `Cmi5LaunchTokenServiceTest::testAuthTokenIsNotReadAsANextcloudLoginAndVerifies` and
      `::testInvalidAuthTokensAreRefused`; `Cmi5LaunchControllerTest::testLaunchAndSingleUseFetch` asserts the
      fetch URL hands out the wrapped token.

## 8. xAPI State and Agent Profile

- [x] 8.1 `LrsDocumentController`: xAPI 1.0.3 State resource (GET, PUT, POST as a JSON merge, DELETE on
      `/api/lrs/activities/state`, keyed by `activityId`, `agent`, optional `registration` and `stateId`; GET and
      DELETE without `stateId` list or delete the stateIds) and Agent Profile resource (the same verbs on
      `/api/lrs/agents/profile`, keyed by `agent` and `profileId`).
- [x] 8.2 Authenticate exactly as statements do (`XapiCallerResolver`, shared with `LrsController`); the `agent`
      parameter must name the authenticated learner (403 otherwise) and the stored key uses the identity from
      the credential. Key parameters are read from the query string only.
- [x] 8.3 Concurrency: ETag (quoted SHA-1 of the bytes) on GET and on every write; `If-Match` and
      `If-None-Match` honoured on writes and deletes (412); a PUT over an existing agent profile without
      either header is 409. Every response carries `X-Experience-API-Version: 1.0.3`.
- [x] 8.4 The launch writes `LMS.LaunchData` (cmi5 section 10.2.1) before it hands out a fetch code, and answers
      503 without one when the write fails.
- [x] 8.5 Register: add the `xapi-document` schema (fragment in `design.md`).
      - Evidence: `XapiDocument` is in `lib/Settings/learniq_register.json` and listed in the learniq register's
        `schemas`, with its 26 strings in en/nl (the nl AI-written, in `l10n/ai-translated.json`).
        `tests/Support/XapiDocumentsInMemory.php` no longer calls `declarePending()`.
        The LRS, launch and store tests pass against the real fragment (43 tests), and check:schema-l10n is 0.
- [x] 8.6 Unit tests: `LrsDocumentControllerTest` (every verb on both resources, refusals, concurrency),
      `XapiDocumentStoreTest`, `XapiCallerResolverTest`, and `Cmi5LaunchControllerTest::testLaunchWritesLaunchData`,
      `::testLaunchDataDefaults`, `::testNoLaunchWithoutLaunchData`.
- [ ] 8.7 Live check on the shared instance: launch, fetch-code redeem, read `LMS.LaunchData` with
      `Authorization: Basic <auth-token>`, PUT and GET an own state document, ETag and If-Match.
  - Round 1, 2026-09-29, served checkout 51c8b1ef (xapi-document 0.1.0 imported), throwaway cmi5 lesson:
    - Works: launch 200 (LMS.LaunchData written), fetch 200 (base64 auth-token); `GET ...state?stateId=LMS.LaunchData`
      with Basic 200 with `Content-Type: application/json`, an ETag and `X-Experience-API-Version: 1.0.3`; PUT of an own
      state 204 with ETag `"025053693d40cee617c43cdc7718f2b1da59b94a"`, which is the SHA-1 of the body sent; stateId list
      `["LMS.LaunchData","bookmark"]`; `If-Match: "stale"` 412; `If-None-Match: *` on an existing document 412; an agent
      naming another learner 403; no credential 401.
    - Finding A: every GET body read `Array`. OpenRegister decodes a string property that holds JSON into an object on
      save (the stored `contents` was `{"page": 3}` as an object), so the document came back as a PHP array cast to a
      string, both documents had the same ETag (the SHA-1 of `Array`), `If-Match` with the PUT's own ETag was refused
      412, and POST merge answered 400. Fixed: `XapiDocumentCodec` always stores base64, and reads an old text row
      back as JSON.
    - Finding B: DELETE answered 500 `Object not found in magic table`. The store read with multitenancy off but deleted
      with it on, and a document written without a user session is not found that way. Fixed: `deleteObject` runs
      with `_multitenancy: false`, like the reads.
    - Both findings are now copied into `tests/Support/XapiDocumentsInMemory.php` (a save decodes JSON strings; a
      delete with multitenancy on throws), and 10 tests fail without the fix.
    - Round 2, the same checks on a served checkout with this fix, ticks this box.
