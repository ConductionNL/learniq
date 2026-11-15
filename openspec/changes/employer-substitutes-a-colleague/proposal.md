---
kind: spec
depends_on: [employer-portal-audience, participant-portal]
---

# Proposal: employer-substitutes-a-colleague

## Why

The academy boards (8 October 2026) let an employer send a colleague in place of a participant who cannot come:

- [warmtepompacademie/Detail](https://identity.conduction.nl/screens/board?id=warmtepompacademie/Detail): on the booking's participant rows the link "Collega in zijn plaats".
- [warmtepompacademie/MobielDetail](https://identity.conduction.nl/screens/board?id=warmtepompacademie/MobielDetail): "Kunt u toch niet? Bel de planning voor woensdag 12.00 uur. Uw werkgever mag een collega in uw plaats sturen."

`employer-portal-audience` left it out ("a substitute is wave 3"), and the portal plan lists "Substitute a colleague" as missing (section 3.4). Lane T2 names it.

## What changes

- An employer action `substituteParticipant` on a participant row of her own booking: choose another person of her organisation (or add one), until the course day's substitution deadline (`cohort.substituteUntil`, default the working day before at 12.00). The booking keeps its place; the old participant's enrolment is withdrawn with reason "vervangen door collega", the new one's created, and the exam registration line (if any) moves with missing fields named.
- The participant whose place is taken is told on his portal; the new participant gets his invitation as any new participant does.
- After the deadline the action is not offered, and the row says "Vervangen kan tot {moment}. Bel de planning."

## Not in this change

- A participant swapping himself: the boards give that to the employer.
