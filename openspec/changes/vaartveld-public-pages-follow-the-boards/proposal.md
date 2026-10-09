---
kind: spec
depends_on: [school-portals-match-their-boards, portal-public-index]
---

# Proposal: vaartveld-public-pages-follow-the-boards

## Why

Portal proof run 3 (9 October) put Vaartveld College's public pages beside their boards (school-design `vaartveld/preview` Home, Zoeken, Artikel, Contentpagina). Portaliq has since merged the options these boards need (`site-home-follows-the-school-boards`, `site-breadcrumb-follows-the-school-boards`, `site-catalogue-follows-the-school-boards`, `site-article-page-follows-the-board`, `site-callouts-steps-and-tables-follow-the-boards`). What is left sits in the vo declaration, `lib/Settings/portals/vo.json`:

- Home: the hero says "havo en vwo" where the board says "vmbo-t, havo en vwo", and "Ons onderwijs" has no Vmbo-t column. The second hero action is a button, not a text link; "Kom kennismaken" has no chevron. The "Direct regelen" icons sit in tinted circles and three differ. The agenda's "Informatieavond" is a link.
- Zoeken: no filter column, a visible field label, an intro that differs, and one result for "toetsweek" where the board shows 18 (Nieuws 7, Toetsroosters en PTA 6, Brieven aan ouders 3, Schoolgids en regels 2).
- Artikel: no "Nieuws" pill, a breadcrumb "Nieuws > Nieuws", a full-width dark "Kom je ook?" band instead of a light card beside the article, no "Om alvast te lezen" card, a shorter body without "De vier profielen".
- Contentpagina: the "Ziek melden" button stands outside its callout, "18 jaar of ouder?" and "Is uw kind lang ziek?" are headings in the main column instead of cards beside it, and the table's first column is not bold.

## What changes

All in `lib/Settings/portals/vo.json`:

- `portal.breadcrumb: "page"`: a crumb names a page by its own title ("Nieuws en documenten").
- Home: the board's hero text; `actions` with `chevron` and `style: link`; `nlQuickTasks.iconStyle: plain` with the board's icons (heartPlus, documentGrade, pencil, bookLines, bookStack); agenda rows without links; "Ons onderwijs" in three columns, Vmbo-t first. The Onderwijs page names vmbo-t too.
- Zoeken: the board's intro; `nlCatalogue` with `kindFacet: "Soort"`, `labelHidden`, `pageSize: 6`, `facetDisplay: {Leerjaar: select}` and `types: [news, document]`.
- Six more public news items about the toetsweken of school year 2025-2026, all older than the home's six, so the home and its news list do not change.
- `publicIndex.documents`: the eleven school documents of the search board (five toetsroosters, the PTA 4 havo, three letters to parents, the toetsweek rules, the school guide) in portaliq's public index item shape, with the facets Soort, Afdeling and Leerjaar.
- Artikel: `kindLabel`, `sectionHref`, `sectionLabel` on `nlNewsArticle`; the right column holds the light "Kom je ook?" card (`tone: light`, `note`), the "Om alvast te lezen" card and "Meer nieuws". The Informatieavond body follows the board: capitalised facts, the paragraph about pupils of klas 4 and 5, "De vier profielen", the closing paragraph.
- Contentpagina: `action` "Ziek melden" inside the callout (the separate button goes); `rowHeaders` on the table; "18 jaar of ouder?" and "Is uw kind lang ziek?" as `nlAlert kind: plain` cards under the side list.

## Not in this change

- **The documents in the search results** need learniq's public index to read `publicIndex.documents` (shared `PortalPublicIndex`, lane FIX-L) and to move their dates with the load week. Until then the search shows the seven news items only.
- **`breadcrumb` on a fresh load** needs `breadcrumb` in `ExamplePortalProvisioner::FILLABLE` (lane FIX-L).
- "Toetsweek" finds 17 items, not 18: the PTA's board text does not contain the word, and this change does not alter board text to make a count fit.
- No document links: no file ships with the example set, so the cards have no chevron. "Om alvast te lezen" links to the Onderwijs page.
- Portaliq: "Is uw kind lang ziek?" draws its link as a button (`nlAlert.action`), the board as a text link; a photo place in an article; cards for "De vier profielen" (a plain list here); the facets "Afdeling" and "Periode" on news.
- An instance that loaded the set before keeps its pages and news: the provisioner never overwrites. Remove the portal's pages and load the set again.
