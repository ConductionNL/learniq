# Tasks: compliance-rules-and-reminders

## 1. Renewal cycle

- [ ] 1.1 `lib/Listener/CredentialIssuanceHandler.php::resolveExpiresAt()`: when the course has no `defaultExpiresAfterDays` and a `regulationSlug`, read the published `Regulation` with that slug and set `expiresAt` to completion plus `renewalCycleMonths` months. A course's own validity wins. Verify: PHPUnit on `CredentialIssuanceHandler`: course validity set, rule cycle only, neither.
- [ ] 1.2 `tests/Unit/Register/RegulationSettingsRegisterTest.php`: `Regulation` declares `slug`, `name`, `audienceScope`, `ragRedThreshold` (default 70), `ragAmberThreshold` (default 90), `renewalCycleMonths` (default 12) and the draft, published, archived lifecycle. Verify: `composer test:unit -- --filter RegulationSettingsRegisterTest`.

## 2. Tags

- [ ] 2.1 Add `@spec openspec/specs/compliance-rule-coverage/spec.md#...` tags to `RegulationCoverageService::ragStatus()`, `RegulationAssignmentService::assign()`, `ComplianceRollupService::byDepartment()` (replacing the `capabilities.json` tag) and `CredentialIssuanceHandler::resolveExpiresAt()`.
- [ ] 2.2 `tests/Unit/Register/MandatoryTrainingNotificationsTest.php`: `Enrolment.dueReminder` filters `mandatory: true` and `dueDate withinNext P3D` to `learnerId`; `Enrolment.overdue` filters `mandatory: true` and a passed `dueDate` to `managerId` and the `hr` group. Verify: `composer test:unit -- --filter MandatoryTrainingNotificationsTest`.

- [ ] 2.3 Both rules filter only on `mandatory` and `dueDate` today, so a completed or withdrawn enrolment still matches. Add the enrolment's open lifecycle states to both filters in `lib/Settings/learniq_register.json` and bump the schema version. Verify: the test in 2.2 asserts a completed enrolment matches neither rule.

## 3. Matrix

- [ ] 3.1 When the tasks are done, set `comp-renewal-cycle-months` back to `built` with the handler as evidence, archive this change and repoint the seven rows to `openspec/specs/compliance-rule-coverage`.
