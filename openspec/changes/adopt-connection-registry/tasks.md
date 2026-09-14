# adopt-connection-registry tasks

## 1. Declare

- [x] 1.1 Write `lib/Settings/connections.json` with the eight connections.
- [x] 1.2 Add `id="section-data-exchange"` to `src/views/settings/DataExchangeSettingsSection.vue`.
- [x] 1.3 Guard the file in `tests/Unit/Settings/ConnectionsDeclarationTest.php`.

## 2. Page

- [x] 2.1 Add `src/manifest.d/connection-registry.json` with the page and its settings-gear menu entry.
- [x] 2.2 Add `src/utils/connectionRegistry.js` with the two formatters and the Add integration handler.
- [x] 2.3 Wire the formatters and the handler in `src/App.vue`, and register `PowerPlugOutline` in `src/icons.js`.
- [x] 2.4 Add the strings to `l10n/en` and `l10n/nl`.
- [x] 2.5 Cover it in `tests/unit-js/connectionRegistry.test.mjs`.

## 3. Reports

- [x] 3.1 Add `lib/Service/ConnectionReportService.php`.
- [x] 3.2 Record the wallet offer outcome in `WalletOfferDelegationService`.
- [x] 3.3 Add `lib/BackgroundJob/ConnectionReportJob.php` and register it in `appinfo/info.xml`.
- [x] 3.4 Report from `SettingsController::update()`.
- [x] 3.5 Add the integriq event stub to `tests/Stubs`, `tests/bootstrap.php` and `psalm.xml`.
- [x] 3.6 Cover it in `ConnectionReportServiceTest`, `ConnectionReportJobTest`, `WalletOfferDelegationServiceTest` and `SettingsControllerConnectionReportTest`.

## 4. End to end

- [x] 4.1 Write `tests/e2e/connection-registry.spec.ts`.
- [x] 4.2 Install integriq in the CI `additional-apps`.
- [ ] 4.3 Run the e2e spec against an instance with learniq and integriq, then archive this change.
