# Tasks: record an exemption from a mandatory training, with the reason

## 1. Register and guard

- [x] 1.1 Add the `RegulationExemption` schema, lifecycle and guard reference. Verify: `npm run check:register`, PHPUnit for the register shape.
- [x] 1.2 Add the guard (rationale, policy reference, requester differs from decider). Verify: PHPUnit for each refusal and the pass; a guard test that asserts the wiring from the register.

## 2. Roll up

- [x] 2.1 Teach `ComplianceRollupService` to remove exempt learners from obligations and count excused. Verify: PHPUnit for granted, expired and none.

## 3. UI

- [x] 3.1a Add the Exemptions index and detail pages, the officer menu and the line manager's "Request an exemption" menu, and an Excused column in the department compliance widget. The request is the index page's create form, no dialog file (design, Built). Verify: `npm run check:specs`, gate 53 and 60 unchanged.
- [ ] 3.1b Playwright flow: request, grant, see excused. Not written: Playwright runs in CI and nightly only, and a spec nobody has run is not evidence.

## 4. Close out

- [x] 4.1 Add strings to every shipped locale. Verify: `npm run test:l10n`.
- [ ] 4.2 Set row `comp-record-exemption` to built and archive the change. Verify: parity_verify --strict.

