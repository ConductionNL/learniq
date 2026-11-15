# Tasks: rod-bsn-and-school-advice

Spec: `openspec/changes/rod-bsn-and-school-advice/specs/data-exchange/spec.md` (`DE`). Tier: must (statutory).

### Task 1: Register
- **spec_ref**: DE "A learner's personal number is encrypted and readable only by administration and compliance", DE "A school advice goes to ROD with DUO's AanleverenAdviesVO field set"
- **files**: `lib/Settings/learniq_register.json`, `l10n/*.json`
- **acceptance_criteria**:
  - LearnerProfile `personalNumber` flagged encrypted with property authorization for the two groups and `audit: true`; `personalNumberType` enum with the same authorization
  - School `onderwijsaanbiedercode`, SchoolAdvies `vestigingId`; info.version and three schema versions bumped
- [x] Implement
- [x] Test

### Task 2: Resolver and disclosure
- **spec_ref**: DE "The ROD learner record carries the personal number where DUO expects a BSN", DE "The personal number leaves learniq only in a ROD message and is never logged"
- **files**: `lib/Service/RodPersonalNumberResolver.php`, `lib/Service/ExchangeDisclosure.php`
- **acceptance_criteria**:
  - elfproef and adapted elfproef checks; system read with tenant forced; no value in any log call
  - NEVER holds `personalNumber` and `personalNumberType`; required fields per mapping
- [x] Implement
- [x] Test

### Task 3: Composition and gate
- **spec_ref**: DE "A school advice goes to ROD with DUO's AanleverenAdviesVO field set"
- **files**: `lib/Service/DataExchangePayloadBuilder.php`, `lib/Service/ExchangeGateService.php`, `lib/Listener/SchoolAdviesSendToRodHandler.php`
- **acceptance_criteria**:
  - ROD learner records carry the pair next to `eckId`; school advice records carry exactly DUO's set
  - the handler names `learniq-bron-rod-export-schooladvies`; no `disclosure-undefined` for it
- [x] Implement
- [x] Test

### Task 4: Leak tests
- **spec_ref**: DE "The personal number leaves learniq only in a ROD message and is never logged"
- **files**: `tests/Unit/Service/RodPersonalNumberLeakTest.php`
- **acceptance_criteria**:
  - the number is absent from every captured log message and context, from pass-through, OSO, UWLR and SWV records
- [x] Implement
- [x] Test

## Verification
- `openspec validate rod-bsn-and-school-advice`
- diff-scoped phpcs, phpstan, phpunit on touched classes; one `composer check:strict`; `npm run lint`; `npm run check:schema-l10n`; hydra gates
- i18n: Dutch values for every new catalogue key
- Docs: no user-facing screen changes; the fields render in existing schema-driven forms, so no new screenshots
