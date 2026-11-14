# GTA Laravel v0.10.0 - Heavy Vehicle and Paint tasks

Two local development batches completed: Heavy Vehicle Level 3 and Vehicle Damage Paint Level 3.

- Added 50 programme-specific portal tasks for each programme across Blocks 1-10: 20 practical tasks, 10 source reflection records, 10 paper-result records and 10 instances of the existing shared workplace job card.
- Imported 60 source PDFs unchanged under separate storage filenames. The original 41 Autocare PDFs and GTA artwork remain unchanged. Task versions and original filenames are preserved in the schemas and source receipts.
- Connected current assignment access, draft/save/reopen, own-work submission, locked versions, evidence, referral/resubmission, whole-task marking, deadlines, sequence releases and native PDF exports.
- Added the Heavy Vehicle integrated vehicle/trailer inspection checklist, including printed item labels and validation against contradictory Done/N/A or inspection-status selections. Inspection status never authorises vehicle release.
- Removed the 50,000-character task answer cap. Task answers use multiline fields; generated recorded-work PDFs flow across pages. Browser print now mirrors the full textarea text (browser acceptance pending).
- Made allocation, block sequencing and saved-history transfer checks programme-specific. In-use programme codes/versions cannot be relabelled through the directory editor.
- Kept Autocare's ADAS amendments and Block 9 planning guidance scoped to Autocare tasks. Other programmes show their task sources and a plan/KSB-source-pending page rather than Autocare's planning/RPL calculations.
- Added source receipts, the reproducible development importer and 19 new feature cases. Updated the previous unsupported-programme test to use Panel because Heavy Vehicle is now supported; equivalent rejection remains tested.

Validation: 327 tests / 3,414 assertions on SQLite; 52 actual loopback HTTP/persistence checks; 252 PHP/compiled Blade syntax checks; 1 JavaScript syntax check; 101 source PDF hashes; 2 unchanged artwork hashes. Each 57,618-character answer saved intact and exported with all 1,200 repeated lines plus the end marker across a 25-page PDF. See TEST_RESULTS.json for exact scope and limits.

No migrations or dependency changes. Existing allocated task snapshots are not rewritten. Formal programme plans/KSB mapping, individual dates/hours, signatures, Blocks 11-12, Panel, live account/mail/storage/Cloud work remain separate.
