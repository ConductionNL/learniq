# Tasks: vaartveld-public-pages-follow-the-boards

- [x] **T1**: `portal.breadcrumb: page`; home hero text and actions, icons, agenda rows, Vmbo-t column, Onderwijs summary
  - PHPUnit `VaartveldPublicPagesTest::testTheHomeFollowsTheBoard`
- [x] **T2**: Zoeken: intro, catalogue props, six toetsweek news items, `publicIndex.documents`
  - PHPUnit `VaartveldPublicPagesTest::testTheSearchPageFollowsTheBoard`
- [x] **T3**: Artikel: pill, section crumb, light card, reading list, body in the board's order
  - PHPUnit `VaartveldPublicPagesTest::testTheArticlePageFollowsTheBoard`
- [x] **T4**: Contentpagina: action in the callout, row headers, the two side cards
  - PHPUnit `VaartveldPublicPagesTest::testTheContentPageFollowsTheBoard`, `ExamplePortalDeclarationsTest::testTheDeclarationsFollowTheBoards`
- [x] **T5**: e2e board texts in `tests/e2e/portal-design/vaartveld.spec.ts`
- [ ] **T6**: FIX-L: `PortalPublicIndex` reads `publicIndex.documents` (with the load week's offset); `breadcrumb` in `ExamplePortalProvisioner::FILLABLE`
- [ ] **T7**: live: the four public pages on the proof instance after a fresh site load
