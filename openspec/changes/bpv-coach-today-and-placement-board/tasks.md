# Tasks: bpv-coach-today-and-placement-board

- [x] **T1**: register: `bpv-placement.phase`, `phaseDueAt`, `lateLabel` (calculation); the phase derivation on every fact change; manual move log
  - As built: `lib/Bpv/PlacementPhase.php` derives the phase, `phaseDueAt` and `lateLabel` ("2 weken te laat" from a week on, "te laat sinds 1 oktober" before); `BpvPlacementPhaseStamp` writes them on every placement save, and `BpvVisitPhaseRollup` saves the placement again when one of its visits changes. A manual move is `phaseOverride: {phase}`: the server adds who and when, appends it to `phaseMoves`, and drops it at the next fact change. `lateLabel` is written on save, not a live calculation: a placement nobody touches keeps its label until its next save (a daily refresh is not built). A POK-active placement before its period starts counts as running.
- [x] **T2**: register: `bpv-visit` with lifecycle; link from `bpv-visit-report`
- [ ] **T3**: propose a visit date; the praktijkopleider accepts or answers in her portal (trainer audience action)
- [ ] **T4**: the placement board per phase (library board view, card with late line)
- [ ] **T5**: the BPV-begeleider view on Today: first-today rules, visit week strip, hours waiting per company with "Herinneren", students per phase
- [ ] **T6**: mbo example set: MT2A placements in every phase, Daan without a company, Jesse's visit without a date
