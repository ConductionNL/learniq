---
kind: build
depends_on: []
---

# Proposal: detail-pages-follow-the-boards

## Why

The zuiddrecht school boards draw learniq's detail pages one way: a header with status pills and a breadcrumb, a tab strip that ends in Historie, the record as purpose-built cards (label above value), a side column with what the record links to, and Historie again as the last side card. Live showed the generic page instead: a Gegevens card with an inline "X bewerken" button per field, a Gerelateerd card, a Follow pill, and the history in the sidebar. All 26 measured detail boards were NO-MATCH on this pattern (rollup G2 in R20/LQ-BOARD-GAPS.md), and the two decision boards also draw a "Wat nu?" card (G7).

## What changes

- **One pattern, declared once.** A detail page may carry `config.board` (`tabs`, `history`). At boot `src/utils/boardDetail.js` expands it into the nextcloud-vue detail config: one tab strip with the record history as the last tab, an audit-trail side card titled Historie after the page's own side cards, no Follow control, no per-card Actions menus and no object sidebar.
- **22 detail pages use it**, each with the cards its board draws, built from nextcloud-vue widgets (`facts`, `record-list` with `objectRows`, the existing `object-list` and `integration` widgets), header pills (`statusPill`, `typePill`), a breadcrumb and a meta line. The generic `data` and `related` widgets are gone from these pages.
- **"Wat nu?" on the decision pages**: the BSA decision and the attendance flag carry `nextStep` stages on their lifecycle (CnNextStepCard).
- Dutch strings for every new label.

## Not in this change

- Data the boards show that the records do not hold yet (the subject request's copy table, contributions per learner from shillinq, invigilation figures, the BSA evidence table): lane E.
- Actions that write (record the municipality response, send a reminder, activate an agreement, take a report into handling): lanes C and E.
- The course outline editor (chapters with lessons, move and add inline): an nc-vue widget.
- RegulationDetailPage, CourseBuilder and PortfolioReviewView: custom views, lane D.
