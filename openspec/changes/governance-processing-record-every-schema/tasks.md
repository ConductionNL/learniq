# Tasks: keep the GDPR record of processing up to date by itself

## 1. Check first

- [ ] 1.1 Add `tests/validate-processing-coverage.js`: the personal-data field list (`learnerId`, `learnerRef`, `learnerUserId`, `learnerIds`, `learnerRefs`, `ncUserId`, `teacherIds`, `managerId`, `employeeId`, `parentIds`, `guardianRefs`, `givenName`, `familyName`, `birthDate`, `markedBy`, `submittedBy`, `email`, `phone`) and a failure per schema without a block. Wire it into `npm run check:register`. Verify: it fails on development with the 57 schemas listed (red before the fix).

## 2. Catalogue

- [ ] 2.1 Add the new draft activities of design D1 to the processing catalogue seed, each with purpose, legal basis, data categories and review interval. Verify: `npm run check:register`.

## 3. Declarations

- [ ] 3.1 Add `x-openregister-processing` to every schema the check lists, per the grouping in design D1. Write any schema whose purpose differs from its group, and why, into this file. Verify: the check passes.
- [ ] 3.2 Set `logReads: true` on care and support and on results schemas (design D2). Verify: a register test asserts it.
- [ ] 3.3 Bump the register version.

## 4. Close out

- [ ] 4.1 Live check: import the register, export the record of processing in OpenRegister, count the activities and their schemas.
- [ ] 4.2 Set row `gov-record-of-processing` to built and archive this change.
