---
kind: spec
depends_on: [example-portal-declares-its-site]
---

# Proposal: demo-dates-follow-the-load-week

## Why

Every example set is drawn around one Monday: 5 October 2026, the boards' Monday. Proof run 2 (08 Oct, a Thursday) showed what that costs. "Je rooster vandaag" was empty, because the lessons are on Monday 5 October. "Deze maand" dropped the 7 October items. A certificate that "expires in 8 weeks" drifts every week. Ruben decided (08 Oct): a load moves the dates to the week it runs in.

## What changes

- **`DemoDates`** computes the offset: the whole weeks from 5 October 2026 to the Monday of the load's week, in Europe/Amsterdam. It moves every date in a value by that offset:
  - ISO dates;
  - ISO date-times, keeping the wall-clock time across a change of summer time;
  - ISO weeks (`2026-W41`);
  - Dutch day-month phrases ("maandag 5 oktober 2026", "3, 4 en 10 november", "13 en 15 okt");
  - day-month-year numbers ("29-10-2026").
  Because the move is whole weeks, every weekday name and every time stays true.
- **`occ learniq:example-set:load`** (and the wizard, which uses the same service) hands OpenRegister the set at this week's offset. This covers sessions and lessons, homework, absences, bookings, course days, certificates, hour weeks and placement steps. The portal's pages and news get the same offset: list dates, news dates and dates in the text.
- **`ExampleSetDates`** remembers the offset each set carries. OpenRegister's seed import skips an object that exists. So a load in another week first moves the set's existing objects, and the declared pages and news of its portal, by the difference. A load in the same week moves nothing and writes nothing. A set loaded before this change counts as offset 0, its dates being the boards' own. Removing a set forgets its offset.
- `occ learniq:example-set:portal` writes the site at the offset the set carries.

## Not in this change

- Month names without a day ("Oudergesprekken groep 7, oktober 2026") do not move.
- Objects that people made on the instance (a booking, a report) keep their dates. Only the set's own objects move.
