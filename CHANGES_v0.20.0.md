# GTA Laravel v0.20.0 - Recorded OTJ ledger and approved-plan comparison

Added a scoped ledger combining ended register records and occurred approved extra training, with explicit excluded/pending/cancelled/withdrawn sources and a cumulative recorded subtotal. Future, unconfirmed and withdrawn records do not add hours; reads create no duplicate ledger entries or credits. Corrections are read from the original sources.

The current applicable provider-approved occupational target is linked. Planned hours due are the sum of blocks ending before today; no daily interpolation is guessed within an open block. The loaded-record percentage and gap are explicitly provisional: historical completeness is not verified. Without an applicable current approval, the target/pace remain unknown. Attendance rates and Maths/English training/qualification results remain separate.

A protected PDF export is included. Review drafts capture a fixed current OTJ summary at save time; later source corrections do not silently rewrite earlier drafts. This is not a reconstructed historical ledger or funding/whole-programme completion decision.

These four batches were tested together at v0.22.0. See TEST_RESULTS.json for executed checks and limits; no intermediate standalone release is claimed.
