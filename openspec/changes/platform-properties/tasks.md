# Tasks: platform-properties

## 1. Tags

- [ ] 1.1 Add an XML comment `<!-- @spec openspec/specs/platform-properties/spec.md#REQ-PLAT-001, #REQ-PLAT-002 -->` above `<licence>` in `appinfo/info.xml`.
- [ ] 1.2 Add `@spec openspec/specs/platform-properties/spec.md#REQ-PLAT-003` to the class docblocks of `lib/Controller/AiProcessingDisclosureController.php`, `lib/Lifecycle/AssessmentPublishGuard.php` and `lib/Service/AiLocalityClassifier.php`.

## 2. Checks that keep the properties true

- [ ] 2.1 `tests/Unit/Platform/AppInfoPropertiesTest.php`: reads `appinfo/info.xml`, `LICENSE` and `composer.json`; asserts all three name EUPL-1.2 (REQ-PLAT-002). Verify: `composer test:unit -- --filter AppInfoPropertiesTest`.
- [ ] 2.2 Same test: asserts no PHP file under `lib/` holds a `conduction.nl` or `conduction.cloud` URL outside a docblock (REQ-PLAT-001).
- [ ] 2.3 `tests/Unit/Platform/RunsWithoutAiTest.php`: with `IAppManager::isInstalled('hermiq')` false, `AiProcessingDisclosureController::index()` answers 200 with an empty feature list, and `AssessmentPublishGuard::check()` lets a manually proctored assessment publish (REQ-PLAT-003). Verify: `composer test:unit -- --filter RunsWithoutAiTest`.

## 3. Matrix

- [ ] 3.1 When the tasks are done, archive this change and repoint the three rows in `openspec/parity/capabilities.json` from `openspec/changes/platform-properties/specs/platform-properties` to `openspec/specs/platform-properties`.
