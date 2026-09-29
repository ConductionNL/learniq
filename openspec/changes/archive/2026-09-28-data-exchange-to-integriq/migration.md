# Migration: data-exchange-to-integriq

No database tables. Register changes: four schemas removed, three added, `AttendanceFlag` gains
`municipalityFeedback` and a transition, five `dataExchangeJobId` properties lose their `$ref`.
OpenRegister's import never deletes a schema, so the old rows stay readable by slug on an
existing install.

Data: `MigrateDataExchangeToIntegriq` (post-migration repair step, `appinfo/info.xml`) archives
every old row to `data-exchange-archive/retired-data-exchange.json` and, when integriq is there,
sends jobs and customised mapping profiles through integriq's events, once
(`learniq/exchange_migrated_to_integriq` app config). It deletes nothing.
