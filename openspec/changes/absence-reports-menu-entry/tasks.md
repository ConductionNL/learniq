# Tasks: the absence reports get a menu entry

- [x] 1.1 `AbsenceReportsMenu` under People and `AbsenceReportsComplianceMenu` relocated into Compliance, gated on role and `workspace.chosenSegment`. Verify: `node --test tests/unit-js/absenceReportsMenu.test.mjs` (red before: 3 of 4 failed, no entry routed to ExcuseRequests; green after) and `npm run check:specs` (validate-menu-role-gates OK).
- [x] 1.2 Label "Absence reports" with NL "Verzuimmeldingen" in l10n, listed in `ai-translated.json`. Verify: `npm run check:l10n`.
- [ ] 1.3 Live: the entry as `po-leerkracht-09`, `po-ib-01` and `po-directeur-01` on the primary school throwaway.
