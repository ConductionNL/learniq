# Tasks: every user can read the AI disclosure, and admins edit it

## 1. Read for everyone

- [x] 1.1 Drop the `visibleIf` role gate from the AI processing disclosure entry in `src/manifest.json`, so every signed-in user reaches `LearniqAiProcessingDisclosure.vue`. Verify: Playwright as a student finds the entry and the page. (Done in `src/manifest.d/dashboard.json`: the entry is a top-level footer entry beside Documentation with no `visibleIf`, out of both profiles' `removals` and out of the full profile's relocation into the role-gated GroupCompliance; `tests/validate-menu-role-gates.js` lists it as intentionally universal; `structureProfile.test.mjs` counts it. The Playwright check is left for a live instance.)

## 2. Admins edit the school's text

- [x] 2.1 Add an `AiDisclosureText` object (introduction, contact, a note per feature slug) to the register, with create and update restricted to `admin` and `compliance-officer`. Verify: PHPUnit or an API test that a student's write is refused. (`ai-disclosure-text` in `learniq_register.json`, read `authenticated`, create/update `compliance-officers`, admins by OpenRegister's admin rule; `CompliancePolicyRecordAccessTest` pins it with the other policy records.)
- [x] 2.2 `LearniqAiProcessingDisclosure.vue`: show the school's text beside the composed facts, and an edit mode for admin and compliance officer only. Verify: vitest for both roles. (The app's JS tests run on `node --test`: `tests/unit-js/aiDisclosureText.test.mjs` pins the role split and the read/save shapes in `src/utils/aiDisclosureText.js`. The existing policy editor is now edit-mode only too, since every role reaches the page.)

## 3. Specs and matrix

- [ ] 3.1 Set `gov-show-users-the-ai-disclosure` to `built` with the files above, and archive this change.
