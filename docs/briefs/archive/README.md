# Archive — briefs whose work has landed

Moved here on **2026-09-10**, verified against the code and the changelog.
Nothing in this folder is cited from `src/`, `CLAUDE.md` or `TODO.md`; the two
references in `docs/periphery.md` were repointed in the same pass.

| Doc | Verified as |
| --- | --- |
| `audit-actor-attribution-brief.md` | Its own header: SHIPPED 2026-06-05, v0.33.0 (P1–P3) + the consumer's `SetAuditActor`. |
| `checkout-resilience-brief.md` | SHIPPED — P1–P3 in v0.32.0, consumer P4 on in-other-worlds `main`. P5 was explicitly DEFERRED as defence-in-depth, not left undone. |
| `package-tightening-brief.md` | RELEASED 2026-06-11 as v0.36.0, all three phases. |
| `price-format-consistency-brief.md` | RELEASED 2026-06-11 as v0.37.0. |
| `purchasing-domain-brief.md` | DONE — the header's two remaining items are both closed: `src/Purchasing/` is in the released package (it is in in-other-worlds' `vendor/`), and the consumer is wired (`app/Models/Product.php` implements `HasPurchases`). |
| `refund-domain-brief.md` | MERGED — `RefundOrder` and `RecordRefund` are on `main` in `src/Commerce/Order/Actions/`. |
| `vat-gross-inclusive-brief.md` | SHIPPED (v0.30.0) — gross-inclusive, per-bracket tax with `orders.tax_summary`; the round-2 G4/G5 addendum is fixed too (`LargestRemainderAllocator`). |

## What stayed in `docs/briefs/`

- `complexity-cheap-effective-brief.md` — Release 1 shipped as v0.71.1 (PRs #24/#25/#26/#27); **Release 2 is next** (§4), with two open questions in §6.
