# Tasks: every user can read the AI disclosure, and admins edit it

## 1. Read for everyone

- [ ] 1.1 Drop the `visibleIf` role gate from the AI processing disclosure entry in `src/manifest.json`, so every signed-in user reaches `LearniqAiProcessingDisclosure.vue`. Verify: Playwright as a student finds the entry and the page.

## 2. Admins edit the school's text

- [ ] 2.1 Add an `AiDisclosureText` object (introduction, contact, a note per feature slug) to the register, with create and update restricted to `admin` and `compliance-officer`. Verify: PHPUnit or an API test that a student's write is refused.
- [ ] 2.2 `LearniqAiProcessingDisclosure.vue`: show the school's text beside the composed facts, and an edit mode for admin and compliance officer only. Verify: vitest for both roles.

## 3. Specs and matrix

- [ ] 3.1 Set `gov-show-users-the-ai-disclosure` to `built` with the files above, and archive this change.
