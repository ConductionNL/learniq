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
