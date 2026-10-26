# Bookkeeping read API fixtures

Contract files for the read API (`Agent`, `agent.read.*`), copied unchanged
from the in-other-books app, where they were written first: its shop driver
was built against them before the API existed (in-other-books brief §12 P-5,
§17 C). The app's copy is canonical: `in-other-books/tests/Fixtures/shop/`.

Each file is a sequence of pulls; each pull a set of requests and the exact
response the API must give for the rows those responses describe.
`ReadApiContractTest` seeds those rows and checks every response, field by
field and in key order. The app's `ShopDriverFixturesTest` replays the same
responses into its ledger.

Change a fixture in both repositories in the same change, or the two sides
stop testing the same contract.
