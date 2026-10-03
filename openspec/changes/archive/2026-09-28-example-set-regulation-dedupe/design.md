# Design: example-set-regulation-dedupe

```
SeedProfileService::install(training)
  data = descriptor(training)
  SharedCodeFilter::withoutCodesHeldElsewhere(data)
     existing = ObjectService::findAll(register learniq, schema regulation)  code => uuid
     keep row  unless existing[row.slug] is set and differs from row.uuid
  ConfigurationService::importFromApp(learniq.profile.training, filtered)
```

Decisions:
- **Skip by code, not a shared uuid.** Each set keeps its own definition when loaded alone. The brief allowed either option.
- **Keep a row that exists under its own uuid**, so a new version of a set still updates its own rows on reload.
- **Fail open on the read.** Without the read, the import behaves exactly as before.
- **Its own class.** SeedProfileService sits at class complexity 48 of 50, so the logic lives outside it and costs `install()` one call.

## Risks
- A regulation the first set made and then removed is gone for the second set's references until it is restored from the trash, or until the second set is loaded again.
