# Proposal: example-sets-are-the-four-schools

## Why

Four school portals were designed (2026-10-05): Basisschool De Wilgenboom (po), Vaartveld College (vo), Esdoornveen (mbo) and the Warmtepompacademie (training). Each board shows named people, dates and numbers. learniq's example sets carried other schools: vo was "Voorbeeldcollege Esdoornveen", mbo was "Voorbeeldcollege Vaartveld" and training was "Het Kompas". None of the people on the boards existed, apart from the Hulstkamp family in the po set. A fresh install could therefore never show the designed portals: the names on the boards and the installable data disagreed.

## What changes

- **Each set is the school it was designed for.** po becomes Basisschool De Wilgenboom, vo becomes Vaartveld College, mbo becomes Esdoornveen, training becomes the Warmtepompacademie. Every one sits in the fictional town of Zuiddrecht.
- **Each set carries its design's story**, pinned to Monday 5 October 2026 (week 41), with absolute dates:
  - po: Fatima Hulstkamp with Vera (groep 7, Meester Daan) and Sami (now groep 4, juf Esra); Sami reported ill today and marked at 8.12; Vera ill on 1 October and at the dentist on 24 September; the figures of 2026-2027; Vera's parent-evening time on Thursday 29 October 18.00 confirmed; groep 4's round open until Friday 16 October; the October calendar (schoolfotograaf and kinderboerderij on 7 October, studiedag on 9 October, boekenmarkt on 14 October, ouderavond on 29 October); groep 7's homework this week; Vera's June report with the grades on the board.
  - vo: Noor Bakker in H4b with her father Erik and mentor Sanne Kramer, Monday's lessons with the two changes, the grades that give the averages on the board, homework and tests this week, the mentor-talk round of 13 and 15 October.
  - mbo: Milan de Groot, Mechatronica niveau 4, at Bakker Techniek BV with Petra Bakker and Ruud Hermans; 96 hours approved, 16 waiting, 8 returned of 480; six werkprocessen; the October and November dates.
  - training: Linda Jansen of Jansen Installatietechniek BV with Tom Verbeek, Youssef El Amrani (birth date missing) and Sanne Kok on "F-gassen: herhaling en examen" on 8 October; certificates expiring 30 November 2026.
- **Nothing that is stored moves.** The story is added after every other object, so no existing uuid or slug changes. Object slugs are `<set>-<schema>-<n>`, never a school name. Register slugs, ncUserIds and the set ids stay the same. Only display values change (school name, town, a few values of existing objects listed in the design).

## What an instance that already loaded an old set sees

OpenRegister's seed import matches every object by its fixed uuid. Reloading a set on such an instance adds the story objects and updates the changed display values of the matched objects in place. Removing a set (`occ learniq:example-set:remove <set>`) still removes exactly its objects. The portal of an old vo or mbo set keeps its old slug and title; see `example-portal-declares-its-site` for what the portal step does there.

## Not in this change

- Staff display names live in Nextcloud accounts, not in the register. The accounts are created by the example-set load in `example-portal-declares-its-site`.
- News, messages, documents and invoices have no learniq schema; news is portaliq content, written by the portal step.
- "Gezien door de leerkracht" on an absence report has no field in the register (a staff action that does not exist yet). The teacher's mark at 8.12 is the nearest true thing.
