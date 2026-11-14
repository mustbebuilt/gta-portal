# GTA Laravel v0.23.0 - Reviewed qualification-result imports

Added manager-only CSV preparation, readable exact-row previews and independent application to existing Maths/English qualification aims. Blank templates and searchable current learner/qualification IDs, versions, subject and level are available in the portal; operators do not need database tools to prepare the file.

A batch is limited to 200 rows/1 MB, exact headers and UTF-8. It records the uploaded byte hash, controlled source reference, normalised rows, exact preview hash and preparer. Uploading changes no qualification. A different administrator/CEO must verify actual result evidence and apply the current preview within 24 hours.

Only unchanged Working towards/Awaiting confirmation aims are eligible for bulk updates. Incoming status is explicit Achieved or Awaiting confirmation. Achieved needs the actual result/date/reference; Functional Skills retains the existing Achieved-without-grade-bands rule. Awaiting rows must have blank result/date. Existing achievements/exemptions need individual amendment. No inferred grade mapping, new aim creation, topic completion, price change or custom-start/target change is performed.

Application rechecks every version/source under locks and writes history/audit atomically. Duplicates, stale sources, self-application, expired previews, missing confirmations and audit failure leave the entire batch unapplied. Source binding uses canonical object-key ordering so database JSON key normalisation does not create false differences; list order and actual values remain significant. Actual MySQL/MariaDB testing remains pending.

These three batches were tested together at v0.25.0. Executed consolidated evidence and limitations are in TEST_RESULTS.json; no independently tested intermediate release is implied.
