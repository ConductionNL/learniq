# Tasks: certificate-verification-and-key-rotation

## 1. The readable page on the link

- [ ] 1.1 A `#[PublicPage]` page route for `verificationUrl` that renders the LqVerifieren layout with Nextcloud's public template and calls `GET /api/credentials/{id}/verify`. A request that asks for JSON keeps getting JSON. Verify: `tests/Unit/Controller/CredentialVerifyPageControllerTest.php` for both answers; `npm run check:l10n`.
- [ ] 1.2 Add the certificate title and the holder's display name to the answer for a valid or expired certificate, never for an unknown id. A revoked certificate or a failed signature answers without them too. Verify: PHPUnit on `CredentialVerifyController::verify()`: valid and expired carry title and name; revoked, signature invalid and unknown carry neither.
- [ ] 1.3 The page lists the four checks (signature, not revoked, not expired, not changed) with a sentence each, and the school's phone number from its settings. An unknown id shows a not-found page with no title and no name. Verify: Playwright `tests/e2e/spec-coverage/certification-verify.spec.ts` against a seeded certificate, signed out.

## 2. Key rotation

- [x] 2.1 `tests/Unit/Service/KeyManagementServiceTest.php`: sign a credential, rotate, and verify it through `JwsProofVerifier`; rotate 33 times and assert the first key is gone. Verify: `composer test:unit -- --filter KeyManagementServiceTest`.

## 3. Tags

- [x] 3.1 Add `@spec openspec/specs/certification/spec.md#...` tags to `CredentialVerifyController::verify()` (replacing the retrofit tag), `KeyManagementService::generateTenantKeypair()` and `JwsProofVerifier`.

Build note (3.1): the tags point at this change's spec
(`openspec/changes/certificate-verification-and-key-rotation/specs/certification/spec.md#...`),
because the requirements are not in `openspec/specs/certification/spec.md` until
the change is archived; 4.1 repoints them with the archive. `verify()` lost its
retrofit tag; `generateTenantKeypair()` and `JwsProofVerifier::verify()` keep
their earlier tags beside the new one.

Build note (1.x): 1.2 waits on the open question in the proposal (the owner
confirms that the public answer may carry the title and the holder's name).
1.1 and 1.3 (the public page and its Playwright check) are not built yet.

## 4. Matrix

- [ ] 4.1 When the tasks are done, archive this change, repoint both rows to `openspec/specs/certification` and drop `built.plan` from `cred-public-verify-url`.
