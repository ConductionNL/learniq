# Tasks: example-sets-are-the-four-schools

- [x] **T1**: po: Basisschool De Wilgenboom in Zuiddrecht; Sami in groep 4; the Hulstkamp story layer (`add_story`)
  - `python3 scripts/example-sets/po.py --check`; PHPUnit `PrimarySchoolExampleSetTest`, `ExampleSetDescriptorContractTest`
- [x] **T2**: vo: Vaartveld College; Noor Bakker's story layer
  - `python3 scripts/example-sets/vo.py --check`; PHPUnit `SecondarySchoolExampleSetTest`
- [x] **T3**: mbo: Esdoornveen; Milan de Groot's story layer
  - `python3 scripts/example-sets/mbo.py --check`; PHPUnit `VocationalCollegeExampleSetTest`
- [x] **T4**: training: Warmtepompacademie; Jansen Installatietechniek's story layer
  - `python3 scripts/example-sets/training.py --check`; PHPUnit `TrainingExampleSetTest`
- [x] **T5**: tests and e2e that named an old school, town or Sami's old group follow the rename
