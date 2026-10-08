---
kind: spec
depends_on: [example-portal-declares-its-site, site-guardian-portal-design, school-portals-use-the-new-blocks]
---

# Proposal: school-portals-match-their-boards

## Why

Portal proof run 1 (06 Oct) put each rendered portal page next to its design board. Several differences sit in learniq's own data: the portal declarations in `lib/Settings/portals/*.json` and the guardian's overview.

- The Contact column showed twice in the footer: once from `footer.contact`, once from a footer menu "Contact" (defect 7).
- The mbo and training heroes have a search box, and portaliq hides the heading when a search box is there (defect 6).
- The banner was a white closable notice, not the coloured "Let op" strip; the heroes had a small heading; the content page's table had no box and no heading above it.
- The home's right column (sign-in card, calendar) fell below the news, and the content page's side list sat at the bottom instead of the top right.
- Esdoornveen's "Kies je richting" had no heading and no cards; Wilgenboom's "Over onze school" and Vaartveld's "Ons onderwijs" had no heading over the columns.
- The academy's course days had no links, the "Verloopt er een certificaat in uw bedrijf?" heading was missing, and a markdown sentence split around its links.
- The guardian overview showed blocks the board does not have (figures, absence reports, grades, messages, three buttons), a calendar that was not limited to the month, and a booked conversation as a raw line ("29-10-2026 18:00-18:10, Meester Daan") (defect 11).

## What changes

- Declarations: no footer menu "Contact"; `variant: plain` on every school hero, and `headingVisible: true` with the "Veel gezocht" links on the mbo and training heroes; the banner as `kind: notice` with a bold lead and a link, edge to edge (`band: true`), not closable; on the content page the table `boxed` under its own `nlHeading` (caption for screen readers only) and the side list under an accent line (vo keeps its tinted box, as its board); the home's news 8 columns wide with the sign-in card and the calendar stacked beside it; the content page's side list at the top right with the main column 8 wide; `nlLinkColumns` for "Over onze school" and "Ons onderwijs"; an `nlHeading` "Kies je richting", an `nlLink` "Alle opleidingen" and the four lists as cards; course-day links and the certificate heading on the academy home.
- Guardian overview: greeting, what is still to do, the children, news, this month. The calendar is `range: month`. A booked conversation reads "Oudergesprek" with the teacher's name under it.

## What an instance that loaded a set before sees

Nothing changes there. The provisioner never writes over a page or menu that exists. To get the new layout, remove the portal's pages and footer menus and load the set again.

## Not in this change (portaliq, lane FIX-P)

- The news rows of the overview show the full body with raw markdown; portaliq's news block renders the body. It should show the audience, the date and the title.
- The "Zaken en taken" group in the account menu is portaliq's own.
- Rendering the children cards' status: learniq declares it as a lookup on the guardian's own reports (`status` on the cards block); portaliq has to read it.
- The overview keeps its child switcher (`records`): the calendar sources narrow to the open child.
- The page title shown twice and "Praktisch" not marked active: portaliq #1368 (a page that opens with a level-1 `nlHeading` gets no second title). The declarations keep that heading.
- The hero beside the course list; the photo next to the hero.
