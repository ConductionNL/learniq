# Proposal: guardians read statuses and kinds of absence in their own language

## Why

Seen on the Dutch parent portal of De Wilgenboom (2026-10-02). On "Afwezigheidsmeldingen van mijn kind" the status cells read "approved" and "submitted", the values the schema stores. The absence form's "Soort afwezigheid" select offered "Illness" and "Bereavement". learniq already translates its section, column and form labels (PortalLabelTranslator), but a stored value had no label to translate.

## What changes

- portaliq lets an app declare `valueLabels` on a column and on a field config (portaliq change `contribution-value-labels`, the pull request this one depends on). A cell then shows the label, and an enum select shows the label while it submits the stored value.
- learniq declares the English labels once, in `PortalValueLabels`, for the values a guardian sees:
  - absence reports: `lifecycle` (submitted, approved, rejected) and the form's `reasonKind`
  - attendance: `status`
  - conference bookings and conference times: `lifecycle`
- PortalLabelTranslator translates the labels of every `valueLabels` map through learniq's catalogue. The stored values it is keyed by never move.
- Seven new catalogue keys with their Dutch: "Submitted" (en only, nl had it), "Scheduled", "On the waiting list", "Booking cancelled", "Proposed time", "Did not attend", "Conversation cancelled". The other labels reuse keys the app already ships.

## Order

Merge portaliq's `contribution-value-labels` first. Without it portaliq drops the `valueLabels` key and the portal looks exactly as it does today, so this change is safe to land in either order.

## Not changed

- The schemas and their enums. A unit test checks that every map's keys equal the schema enum, so a renamed value fails the test.
- The student and other audiences: only the parent manifest is translated today.
