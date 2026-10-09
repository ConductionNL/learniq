---
kind: spec
depends_on: [example-sets-are-the-four-schools, school-portals-match-their-boards]
---

# Proposal: wilgenboom-story-data

## Why

Proof run 3 (9 October) compared Fatima Hulstkamp's pages on De Wilgenboom with their boards (MijnOverzicht, Detail). Some lines on those boards have no data behind them in the po set: Vera's card reads "Gym om 13.15 uur", and "Binnenkort voor Vera" reads "Schoolfotograaf, in de ochtend, voor het uitje".

## What changes

In `scripts/example-sets/po.py` and the `lib/Settings/profiles/po.json` it writes:

- A gym lesson for groep 7 on the story's day (Monday 5 October 2026, 13.15 to 14.00 uur) in the gym, on the course Bewegingsonderwijs. It is added after every other session, so no uuid moves.
- The photographer's line is the board's short one: "In de ochtend, voor het uitje."

## Not in this change

- Showing the lesson on the child's card ("Gym om 13.15 uur") and on the child page's strip: a block declaration in `ParentSitePages`/`ParentRecordPage` (lane FIX-L) and a card line in portaliq.
- The board lines that are about the guardian's own children ("Woensdag, Vera en Sami", "Vera 18.00 uur, bevestigd") come from the calendar block, not from the event's description.
