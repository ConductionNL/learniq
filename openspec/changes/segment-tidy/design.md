# Design: segment-tidy

## Menu gates

BSA surfaces were `workspace.segment in [he, corporate]` ("corporate" because an install that never chose runs on the default segment `corporate`). The D26 gate is added next to it, so the pair reads "higher education, or nobody chose":

| surface | never chose | he | chose corporate |
|---|---|---|---|
| BSA menus, cards, report card | shown | shown | hidden |
| Subject choice menus | shown | shown (also vo, mbo) | hidden |

The segment gate stays on every place the entry renders (menu entry, landing card, Reports card), which `segmentMenuGates.test.mjs` checks ("a card carries the workspace gate of the menu entry on its route").

## Per-set removal steps

```
load-example-set ──► SeedProfileService::install(id)
                       └─ LoadedExampleSets::recordFromChoices(id, …)   app config example_sets_loaded

page load ──► PageController::index()
                └─ initialState loadedExampleSets = LoadedExampleSets::all()
          ──► main.js applyExampleSetRemovalSteps()
                └─ remove-example-set  ⇒  remove-example-set-<id> × N (none loaded: unchanged)

click ──► POST /api/setup/action/remove-example-set-<id>
            └─ SetupController::runAction ─ unknown id: 400
               └─ removeExampleSet(id) ─► SeedProfileService::remove(id)
                                            └─ LoadedExampleSets::forgetIfRemoved(id, answer)
```

Decisions:
- **One step per set, not one step with N buttons.** `CnSetupWizard::runAction()` posts `/api/setup/action/{step.action}` with no body and CnAppRoot forwards no step slots, so a run-action step can remove one fixed thing. A step per set keeps the shared wizard untouched.
- **Status reports every per-set step done, loaded or not.** An outstanding run-action step auto-runs on entry and keeps the wizard reopening. The steps come from page-load state, so a set removed mid-session keeps its step; reporting all of them done keeps that safe.
- **Labels are stored with the ids.** Reading them back from the descriptors on the default route would parse megabytes of JSON per page load.
- **The single step stays when nothing is recorded**, so a first-time admin can still remove the set they load in the same visit.
- **The service is reached through `SeedProfileService::loadedSets()`** from the controller: SetupController sits at the coupling limit (13), and SeedProfileService at the class complexity limit (50), so the list logic lives in its own class.

## Risks
- A set loaded before this change is not listed. Loading it again records it and adds no objects.
- Titles are translated at boot with the app's `t()`; the wizard's own translate then finds no key for the composed title and shows it as is.
