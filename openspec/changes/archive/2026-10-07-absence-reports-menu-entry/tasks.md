# Tasks: the absence reports get a menu entry

- [x] 1.1 `AbsenceReportsMenu` under People and `AbsenceReportsComplianceMenu` relocated into Compliance, gated on role and `workspace.chosenSegment`. Verify: `node --test tests/unit-js/absenceReportsMenu.test.mjs` (red before: 3 of 4 failed, no entry routed to ExcuseRequests; green after) and `npm run check:specs` (validate-menu-role-gates OK).
- [x] 1.2 Label "Absence reports" with NL "Verzuimmeldingen" in l10n, listed in `ai-translated.json`. Verify: `npm run check:l10n`.
- [x] 1.3 Live on the primary school throwaway (2026-10-02): `po-leerkracht-09`, `po-ib-01` and `po-directeur-01` each have one "Absence reports" entry linking to `/index.php/apps/learniq/attendance/excuses`. No compliance officer account exists there; that entry is pinned by the unit test only.
