# Design: cmi5-xapi-lrs-ingest

## Context

The proposal and tasks describe the LRS, the launch token and the fetch URL. This file records one decision taken after the change was built: how a real cmi5 assignable unit (AU) gets its statements past Nextcloud to `LrsController`.

## Decision: the auth-token is the base64 of the launch JWT

### The problem, found live on 2026-09-29

cmi5 §8.2.3 makes the AU send the auth-token from the fetch URL verbatim as `Authorization: Basic <auth-token>`. Until this decision the auth-token was the bare RS256 launch JWT. Every such request was answered `401 {"message":""}` before learniq ran, while the same token sent as `Bearer` reached the controller.

Where Nextcloud rejects it (server at `stable35`, read only):

1. `lib/OC.php:1337-1345` (`handleAuthHeaders`) base64-decodes any `Basic` header and, when the result splits on `:` into two parts, sets `PHP_AUTH_USER` and `PHP_AUTH_PW`. mod_php does the same on its own.
2. A JWT header decodes to `{"alg":"RS256","typ":"JWT"}`, so the split gives user `{"alg"` and a non-empty password.
3. `lib/OC.php:1320` (`handleLogin`) calls `OC\User\Session::tryBasicAuthLogin()`, which tries that pair as a login and, when it fails, throws `LoginException` at `lib/private/User/Session.php:575` ("If credentials were provided, they need to be valid, otherwise we do boom").
4. `index.php:57-68` catches the exception and answers `401` with `{"message": ""}` for any non-HTML client.

This runs for every app route before routing, `#[PublicPage]` or not. The only path the server exempts is `/apps/oauth2/api/v1/token` (`lib/OC.php:1188`), hard-coded for the oauth2 app. There is no attribute or registration an app can use to opt out. As a side effect, every AU statement counted as a failed login (`Session::handleLoginFailed()` registers a brute-force attempt for the caller's IP and dispatches `LoginFailed`), which on a school network behind one address would throttle everyone.

### Designs weighed

| design | cmi5 conformant | security | works on Nextcloud 34/35 | verdict |
|---|---|---|---|---|
| (a) Issue the auth-token as real Nextcloud credentials: a technical user plus a short-lived app password per launch, mapped back to the launch in the controller, revoked on expiry. | yes | adds a Nextcloud account and stored app passwords whose scope is the whole Nextcloud API, not the LRS; needs a revocation job | yes | rejected: a real trade-off with no gain over the option below |
| (b) An endpoint where Nextcloud skips Basic handling. | yes | n/a | no such mechanism for apps; the oauth2 path is hard-coded, OCS runs the same `handleLogin`, `remote.php` endpoints are not app routes | not available |
| (c) Make the auth-token something Nextcloud does not read as `user:password`: base64 of the JWT. | yes, the auth-token is opaque to the AU | unchanged: the JWT is still the credential, signed, one hour, one learner and one lesson; base64 is only an envelope | yes, proven live | **chosen** |

### How (c) works

- `Cmi5LaunchTokenService::authToken()` returns `base64_encode($jwt)`. The fetch URL hands that out as `auth-token`.
- Nextcloud decodes the Basic header to the JWT itself. A JWT's alphabet is base64url plus `.`, which never contains `:`, so neither mod_php nor `handleAuthHeaders` sets `PHP_AUTH_USER`, `tryBasicAuthLogin` returns false, no login is attempted and no brute-force attempt is registered. The request continues anonymously to the public `lrs#postStatements` route.
- `Cmi5LaunchTokenService::verifyAuthToken()` accepts that auth-token, and still accepts a bare JWT (a credential with exactly two dots, which standard base64 never has) so `Bearer` callers keep working. Anything else is base64-decoded strictly and verified as a JWT; a failure is a 401 from the controller.

### Live proof, 2026-09-29, against `http://localhost:8080`

A real launch and fetch-code redeem gave a live JWT. The same JWT, three ways, to `POST /apps/learniq/api/lrs/statements`:

| credential | answer | meaning |
|---|---|---|
| `Basic <bare JWT>` | `401 {"message":""}` | Nextcloud's login layer, the bug |
| `Basic <base64 of the JWT>` | `401 {"error":"Not authenticated"}` | `LrsController`'s own body: the request reached the controller (the served code predates the unwrap) |
| `Bearer <bare JWT>` | `500 {"error":"The statements could not be stored"}` | controller, past authentication, into storage (the separate OpenRegister storage bug) |

The new `verifyAuthToken()` verifies the exact wrapped bytes sent above against the instance's public key (sub `admin`). `tests/Unit/Service/Cmi5LaunchTokenServiceTest.php::testAuthTokenIsNotReadAsANextcloudLoginAndVerifies` pins the split Nextcloud applies: the bare JWT splits into two parts, the auth-token into one.

### Not in scope

- Other xAPI resources an AU calls (the State and Agent Profile APIs, `GET statements` with a token). They share the same credential and will pass Nextcloud the same way once routed.
- The OpenRegister bug that makes statement storage fail, owned by another lane.

## Decision: State and Agent Profile documents live in a new `xapi-document` schema

### Why a new schema

A cmi5 AU cannot start without the State resource: it reads `LMS.LaunchData`, which the LMS must write before the launch (cmi5 section 10.2.1), and it reads the learner's `cmi5LearnerPreferences` agent profile. Neither fits an existing learniq schema: `xapi-statement` is append-only statement data with required `actor`, `verb` and `object`, and app config per key is not a place for learner data. So both resources share one new schema, `xapi-document`, with a `kind` of `state` or `agent-profile`.

### How a document is keyed

- The key is kind, tenant, `verified_actor_id`, `activityId`, `registration` and `stateId` or `profileId`. The learner comes from the credential (`XapiCallerResolver`, shared with the statement POST), and the `agent` parameter must name that learner or the request is refused with 403.
- The object id is a UUID derived from the SHA-256 of the full key, so reading or writing one document is a single lookup by id. Listing and bulk delete query the indexed fields and then check every key field in PHP, so an empty registration never matches a set one.
- Key parameters are read from the query string only. Nextcloud decodes a JSON body into the request parameters, and a `stateId` or `registration` member inside a state document must never change which document is addressed.
- A body that is not valid UTF-8 is stored base64 with `contentEncoding: base64` and returned as the original bytes.

### Concurrency

Every document carries an ETag: the quoted SHA-1 of its bytes, as xAPI 1.0.3 prescribes. `If-Match` and `If-None-Match` are honoured on every write and delete (412 on a failed precondition). A PUT over an existing agent profile without either header is refused with 409, per xAPI Communication 3.1. A state PUT without headers overwrites, which is what cmi5 AUs do.

### LMS.LaunchData

The launch writes the document before it hands out a fetch code. If the write fails, the launch answers 503 `cmi5_launch_data_failed` and hands out nothing, because an AU that cannot read its launch data must abort anyway.

| member | value |
|---|---|
| `contextTemplate` | `contextActivities.grouping`: the AU activity IRI (learniq has no course structure import, so there is no publisher id to use); `extensions`: a fresh cmi5 `sessionid` per launch |
| `launchMode` | `Normal` |
| `moveOn` | the lesson's `moveOn` when it is a cmi5 value, else `NotApplicable`, the course structure default |
| `masteryScore` | the lesson's `masteryScore` when it is a number from 0 to 1, else absent |
| `launchParameters` | the lesson's `launchParameters` when set, else absent |
| `returnURL` | the lesson page, `/apps/learniq/courses/{courseId}/lessons/{lessonId}` |

The lesson schema has no `moveOn`, `masteryScore` or `launchParameters` today, so every launch gets the defaults until a cmi5 package importer or a lesson field supplies them.

### Register change needed

Add this schema to `components.schemas` and `xapi-document` to the `learniq` register's `schemas` list. The service writes with `_rbac: false` after the controller has authorized the caller, as the statement ingest does. The `authorization` block keeps the generic object API closed to learners' writes. `tenant_id` has no `uuid` format on purpose: `tenantFor()` falls back to the instance id when a learner has no `tenant_id` preference, and that is not a UUID.

```json
{
  "XapiDocument": {
    "slug": "xapi-document",
    "icon": "FileDocumentOutline",
    "version": "0.1.0",
    "title": "XapiDocument",
    "description": "xAPI 1.0.3 State or Agent Profile document held by the learniq LRS for one learner. Written only by LrsDocumentController and the cmi5 launch (LMS.LaunchData), which key it by the authenticated learner in verified_actor_id.",
    "type": "object",
    "x-openregister": {
      "active": true,
      "hardDelete": true,
      "searchable": false
    },
    "required": ["kind", "documentId", "contents", "contentType", "etag", "updated", "verified_actor_id", "tenant_id"],
    "authorization": {
      "read": [
        "instructors",
        "hr",
        "compliance-officers",
        "team-leads",
        { "group": "authenticated", "match": { "verified_actor_id": "$userId" } }
      ],
      "create": ["hr", "compliance-officers"],
      "update": ["hr", "compliance-officers"],
      "delete": ["hr", "compliance-officers"]
    },
    "properties": {
      "kind": {
        "type": "string",
        "enum": ["state", "agent-profile"],
        "description": "Which xAPI document resource this document belongs to",
        "x-openregister-index": true,
        "title": "Document kind"
      },
      "documentId": {
        "type": "string",
        "maxLength": 512,
        "description": "The xAPI stateId or profileId",
        "x-openregister-index": true,
        "title": "Document ID"
      },
      "activityId": {
        "type": "string",
        "description": "Activity IRI the state belongs to; empty for an agent profile",
        "x-openregister-index": true,
        "title": "Activity ID"
      },
      "registration": {
        "type": "string",
        "pattern": "^$|^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$",
        "description": "Registration UUID the state belongs to, lowercase; empty when the state has none",
        "title": "Registration"
      },
      "agent": {
        "type": "object",
        "description": "The xAPI Agent as the caller sent it; always the authenticated learner",
        "title": "xAPI Agent"
      },
      "contents": {
        "type": "string",
        "description": "The document body; base64 when contentEncoding is base64",
        "title": "Contents"
      },
      "contentEncoding": {
        "type": "string",
        "enum": ["utf-8", "base64"],
        "default": "utf-8",
        "description": "How contents is stored: as text, or base64 for a binary body",
        "title": "Content encoding"
      },
      "contentType": {
        "type": "string",
        "description": "The document's content type as the caller sent it",
        "title": "Content type"
      },
      "etag": {
        "type": "string",
        "description": "Quoted SHA-1 of the document bytes, the xAPI ETag",
        "title": "ETag"
      },
      "updated": {
        "type": "string",
        "format": "date-time",
        "description": "When the document was last written",
        "title": "Updated"
      },
      "verified_actor_id": {
        "type": "string",
        "description": "Server-trusted learner the document belongs to, taken from the credential and never from the agent parameter",
        "x-openregister-index": true,
        "title": "Verified actor ID"
      },
      "lessonId": {
        "type": "string",
        "nullable": true,
        "format": "uuid",
        "$ref": "Lesson",
        "description": "UUID of the lesson whose launch wrote the document, when a launch token was used",
        "title": "Lesson ID"
      },
      "tenant_id": {
        "type": "string",
        "description": "Tenant of the learner (multi-tenant isolation)",
        "title": "Tenant ID"
      }
    },
    "x-openregister-seed": [],
    "appendOnly": false
  }
}
```

Until it ships, the tests declare the schema through `RegisterFaithfulStore::declarePending()` (remove that call from `tests/Support/XapiDocumentsInMemory.php` when the schema lands; it throws once the slug is in the register), and a live launch answers 503 `cmi5_launch_data_failed`.
