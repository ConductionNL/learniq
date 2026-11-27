# Tasks: board-dialog-inputs-and-row-names

- [x] **T1**: transition inputs, `withdrawReason`, `evenings`, object names in `lib/Settings/learniq_register.json`
  - PHPUnit `BoardDialogInputsTest`
- [x] **T2**: evenings on the vo mentor round in `scripts/example-sets/vo.py`, regenerated
- [x] **T3**: live: revoke and withdraw from the board dialogs store their reason
  - Live 2026-11-25 on a sandbox instance (Nextcloud 34.0.6, PostgreSQL 16, OpenRegister main; po set), in a browser as admin: Credentials > Hanna Beekdal's certificate > Revoke certificate, reason Fraud with the explanation "Fraude vastgesteld: examen ongeldig": the Credential is `revoked` with `revocationReason` "Fraud: Fraude vastgesteld: examen ongeldig". Maud Berkenberg's enrolment > More > Withdraw (the dialog) with "Verhuisd naar een andere school": the Enrolment is `withdrawn` with that `withdrawReason`. Seen on the way: the enrolment's More menu lists "Withdraw" twice, the plain lifecycle transition (no reason asked) next to the dialog.
