---
kind: code
---

# Records for the detail pages the primary school set had nothing for

## Why

Seven learniq boards draw a detail page whose object the primary school example set (po) did not contain: an aanmelding (LqCoAanmelding), a certificate and its public verification (LqLlCertificaat, LqVerifieren), an assessment item's analysis (LqItemanalyse), a peer review (LqPeerreview) and a lesson delivered as cmi5 (LqLlLesCmi5). On the demo instance every credential was soft-deleted and none of the others existed, so the boards had no id to open.

## What changes

`scripts/example-sets/po.py` appends eight buckets after every existing one, so no earlier uuid moves, and adds one record each: the programme and the open admissions round for groep 1 in 2026-2027 with one aanmelding waiting for its intake, a groep 8 pupil's verkeersdiploma, a rekenen item in its item bank, a peer review between two groep 7 pupils on the first homework, and the e-learning lesson on fractions in the rekenen course, delivered as cmi5. `po.json` is regenerated.

## Not covered

LqCfPrivacyverzoek reads OpenRegister's shared data-subject-requests register, which a learniq profile may not write (the contract allows only learniq's own register).
