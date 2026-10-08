# Tasks: portal-board-checks-run-on-a-real-instance

- [x] **T1**: `ADMIN_CREDENTIALS` with `send: 'always'` in `boards.ts`, used by `ensurePortalAccount`, the wilgenboom invite and the warmtepompacademie invite
- [x] **T2**: `expectTexts` filters on `{ visible: true }`
- [x] **T3**: `signInAs` matches "Inloggen met je schoolaccount", "Inloggen met uw account" and "Inloggen als deelnemer"
- [x] **T4**: `/mijn/learniq/studentBpvPlacements`, `/mijn/learniq/studentHourWeeks`, `/mijn/learniq/employerBookings`
- [x] **T5**: `startBroker()` honours `PORTAL_DESIGN_EXTERNAL_STUB=1` (wilgenboom DigiD, warmtepompacademie eHerkenning)
- [ ] **T6**: live: the suite on the proof instance (:8092) with the external stub
