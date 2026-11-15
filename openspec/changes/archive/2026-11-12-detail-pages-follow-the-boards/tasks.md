# Tasks: detail-pages-follow-the-boards

- [x] **T1**: `src/utils/boardDetail.js` expands `config.board` (tab strip, Historie tab and side card, no Follow, no card menus, no sidebar), written into the fragments by `scripts/build-board-detail.mjs`, checked by `npm run check:board-detail` in `check:specs`
- [x] **T2**: 22 detail pages declare `config.board` with their board's cards, pills, breadcrumb and meta line; no generic Data or Related card
- [x] **T3**: `nextStep` on BsaDecisionDetail and AttendanceFlagDetail
- [x] **T4**: Dutch strings for the new labels (en and nl)
- [x] **T5**: tests/unit-js/boardDetail.test.mjs
