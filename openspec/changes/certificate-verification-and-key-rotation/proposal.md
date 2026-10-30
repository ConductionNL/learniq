---
kind: code
---

# Proposal: certificate-verification-and-key-rotation

## Summary

Two certificate rows are built but no requirement says them. `certification` only says that a Europass file can be checked on the verification route, and `nextcloud-app` only says that an administrator can press "rotate". Neither says that anyone can check any certificate from its link, or that rotating the key leaves earlier certificates checkable. This change writes both down.

The board LqVerifieren draws a public page on that link: it says in words whether the certificate is real and still valid, and what was checked. learniq answers the link with JSON only today. A third requirement follows the board, with tasks.

## The rows this covers

Source: learniq `openspec/parity/capabilities.json`.

| row | capability | built |
|---|---|---|
| `cred-public-verify-url` | Let anyone verify a certificate from a link, without logging in. | `lib/Controller/CredentialVerifyController.php::verify()`, `GET /api/credentials/{id}/verify`, the `verificationUrl` set by `CredentialSigningService`; the readable page on LqVerifieren is not built |
| `cred-rotate-signing-key` | Rotate the signing key without invalidating older certificates. | `lib/Service/KeyManagementService.php` archives the previous public key; `JwsProofVerifier` finds the key by the `kid` in the proof |

## What changes

1. ADDED requirements on `certification`: the public check on the link, key rotation that keeps earlier certificates checkable, and the readable page the board draws.
2. Tasks for the page, the tests and the `@spec` tags.

## Screens

LqVerifieren draws the public check, LqAppInstellingen the rotate action: see `screens.md`.

## Open question

The board shows the holder's name and the certificate title on the public page. The JSON answer leaves both out on purpose, because the route is public and could be used to learn who holds what. Task 1.2 follows the board; the reviewer confirms it with Ruben before it is built.
