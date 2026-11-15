# Proposal: the parent's figure cards count in singular and plural

## Why

Seen on the Dutch parent portal of De Wilgenboom (2026-10-03). The attendance figure cards on a child's record page (learniq #1615) read "1 dagen": a card had one unit, the plural, whatever the figure.

## What changes

- portaliq lets a `kpi` card's `unit`, and a detail's `label`, be `{one, other}` (portaliq change `kpi-unit-singular-and-plural`, branch `fix/kpi-unit-plural`). The portal shows `one` beside exactly 1 and `other` beside every other figure.
- learniq declares both forms on the three attendance cards: `{one: "day", other: "days"}` for absent and unexcused absence, `{one: "time", other: "times"}` for late arrivals, and `{one: "minute in total", other: "minutes in total"}` for the late minutes.
- PortalLabelTranslator translates both forms of a `unit` or `label` map through learniq's catalogue. Only the `one` and `other` keys move.
- Three new catalogue keys with their Dutch: "day" (dag), "time" (keer), "minute in total" (minuut in totaal). The plurals were already in the catalogue.

## Order

Merge portaliq's `kpi-unit-singular-and-plural` first. Before it, portaliq's normaliser keeps a unit only when it is a string, so the cards show the figure without a unit until it lands.

## Not changed

- The fields the cards read, their order and the highlight.
- Every other parent label: a string stays a string.
