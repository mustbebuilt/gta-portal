# v0.26.0 — checked external review copies and ten-week tracking

Externally signed progress-review records, independent manager checks and actual-meeting-based ten-week tracking are implemented/tested locally. Pending copies do not reset dates. The latest active checked meeting sets the next reference at meeting + 70 days. Electronic signing, employer portal access, complete historical compliance and live deployment remain separate.

- Frozen Ready for signatures reviews export an explicitly unsigned native PDF with review/version/hash and separate signature/date lines for apprentice, employer representative and Learning & Progress Mentor.
- Assigned tutor/mentor or manager can record the actual externally signed PDF privately, with all three names/dates and controlled document/identity-check references. The upload remains pending.
- A different administrator/CEO checks the actual file, content, identities and signatures. Confirmation rechecks exact versions, current approved plan/participant mapping, snapshot hashes and actual file bytes. Duplicate meetings, stale sources and self-checks are rejected.
- Checked external records appear in the review worklist, dashboard report and newly captured management packs. Latest actual meeting + 70 days supplies the next tracked reference; late uploads, signature dates and old backfills cannot extend it.
- Reasoned manager withdrawal retains file/history/audit and recalculates from remaining checked records. Existing frozen reviews and captured management packs remain unchanged. A pending/checked source must be withdrawn before reopening its parent review.
- Downloads remain scoped/private and verify actual PDF bytes. No email, electronic signing, employer sharing, automatic review reminders or scheduler activation is added.

466 automated tests / 5,732 assertions; 93 actual loopback HTTP checks; 39-table restart persistence; 317 PHP/compiled Blade checks; 531 unchanged source PDFs. A fictional three-page unsigned review was rendered and its first/signature pages inspected.
