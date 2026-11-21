---
kind: code
---

# Proposal: platform-properties

## Summary

Three capability rows describe what learniq is rather than a page in it: it runs on your own servers, its source is open to anyone, and it works with no AI at all. All three are built, but no requirement says them. The only spec the first two cite, `app-metadata`, holds one requirement about the licence in `info.xml`. This change writes the three properties down as a `platform-properties` spec and adds the checks that keep them true.

## The rows this covers

Source: learniq `openspec/parity/capabilities.json`.

| row | capability | built |
|---|---|---|
| `gov-self-host-everything` | Run the whole thing on your own servers, in your own country. | `appinfo/info.xml`: a Nextcloud app with no hosted component |
| `gov-source-without-a-contract` | Get the source code without signing anything. | `LICENSE`, `<licence>EUPL-1.2</licence>`, public repository |
| `gov-works-without-any-ai` | Run the whole product with no AI at all. | `lib/Controller/AiProcessingDisclosureController.php` answers without hermiq; the AI checks in `AssessmentPublishGuard` only run when hermiq is there |

## What changes

1. A new capability `platform-properties` with one requirement per property (REQ-PLAT-001 to REQ-PLAT-003).
2. `@spec` tags on the files that carry each property.
3. A unit test that reads `appinfo/info.xml`, `LICENSE` and `composer.json` for the licence, and asserts that no file under `lib/` calls a Conduction-hosted address.
4. A unit test that runs the AI-dependent paths with hermiq absent and asserts they answer instead of failing.

## Screens

None. These are properties of the app; see `screens.md`.

## Out of scope

- Hosting, support contracts and pricing, which are not software behaviour.
- The AI locality policy itself, which `ai-locality-guarantee` owns.
