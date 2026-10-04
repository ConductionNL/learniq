# Tasks: an-invited-trainer-may-activate-a-placement

- [ ] **T1**: Ruben reads the proposal and decides whether an invitation may carry a POK signature
- [ ] **T2**: `signPraktijkovereenkomst` becomes an `endpoint-forward` at `minTrust: low`, posting to learniq's own endpoint
  - PHPUnit on the provider's normalised output
- [ ] **T3**: the endpoint verifies the assertion, takes the signer from its claim, and writes `signerId`, `signerRole`, `signedAt`, `method` and `assuranceLevel` server side
  - PHPUnit over the real verifier, and the real payload against the shipped `PokSignature` fragment
- [ ] **T4**: `bpv_pok_min_assurance` (default `basic`) is read on every write; below it the signature is refused and the refusal names the level required
- [ ] **T5**: e2e in `trainer-flows.spec.ts`: step e turns from "her signature is refused" into "her signature is stored and says `basic`", and a school floor of `substantial` refuses it again
- [ ] **T6**: the placement's lifecycle is unchanged; assert that an activated placement still needs every signature it needed before
