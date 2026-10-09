---
kind: spec
depends_on: [portal-label-translation, school-portals-match-their-boards]
---

# Proposal: portal-fields-read-in-words

## Why

Proof run 3 found raw values and English labels on every school's pages. Some examples:
- reason kinds `illness` and `medical-appointment`;
- statuses `submitted`, `approved` and "Lifecycle: submitted";
- English labels such as "Submitted at", "Booking mode: direct", "Weight Override 1", "Attendance Status" and "Minutes Attended".

Portaliq shows a field without a label by its English schema title, and a value without value labels as it is stored. Each collection declared a few columns and left the other fields bare. The attendance lists also read "330 minutes present" on a late arrival: that is the day minus the minutes late, which is correct data but wrong for the reader.

## What changes

- **`PortalFieldWords`** runs on every audience's manifest before the label translator.
  - It gives every projected field a label: the declared one, else the column's, else a curated label (`LABELS`), else the schema title.
  - It gives every field with a fixed set of values value labels: the declared ones, else the column's, else curated ones (`VALUE_LABELS`), else the schema's `x-enum-labels`.
  - It never overrides a declared label, and it skips references the portal never shows as a value.
- Every label and value word it produces has a Dutch entry. 42 new strings.
- **Attendance**, for the guardian and the pupil: the minutes late instead of the minutes present, and the date without seconds.
- **The guardian's conference rounds:** "Book before" shows the date without seconds.
- A lesson change of kind `other` reads "Gewijzigd".

## Not in this change

- Raw timestamps in a detail panel or a receipt are portaliq's rendering. A column can say `render: date`, a detail field cannot.
