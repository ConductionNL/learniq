# Tasks: record an exemption from a mandatory training, with the reason

## 1. Register and guard

- [ ] 1.1 Add the `RegulationExemption` schema, lifecycle and guard reference. Verify: `npm run check:register`, PHPUnit for the register shape.
- [ ] 1.2 Add the guard (rationale, policy reference, requester differs from decider). Verify: PHPUnit for each refusal and the pass; a guard test that asserts the wiring from the register.

## 2. Roll up

- [ ] 2.1 Teach `ComplianceRollupService` to remove exempt learners from obligations and count excused. Verify: PHPUnit for granted, expired and none.

## 3. UI

- [ ] 3.1 Add the Exemptions page and the manager request dialog. Verify: Playwright flow request, grant, see excused.

## 4. Close out

- [ ] 4.1 Add strings to every shipped locale. Verify: `npm run test:l10n`.
- [ ] 4.2 Set row `comp-record-exemption` to built and archive the change. Verify: parity_verify --strict.

