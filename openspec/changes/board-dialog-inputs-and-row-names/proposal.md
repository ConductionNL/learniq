---
kind: code
---

# The register keeps what the board dialogs send

## Why

The board dialogs (lane LQB-C) send a revocation reason, a reason for withdrawing and read the evenings of a conference round. A transition stores only the inputs it declares, so without the declarations the reasons are dropped. The board lists name each row in the row menu ("Acties voor <name>").

## What changes

- Credential `revoke` declares `revocationReason` as a required input.
- Enrolment gains `withdrawReason`; `withdraw` declares it as an optional input (a learner's own sign-off runs the same transition without one).
- ConferenceRound gains `evenings`: a list of `{date, from, until}`. The vo mentor round carries the evenings its mentor is available on.
- Application and ExchangePartnerApproval carry an object name from their own fields.
- Register 0.47.2; en and nl strings for the new titles.
