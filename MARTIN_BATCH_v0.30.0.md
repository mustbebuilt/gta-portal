# Martin / development AI — v0.30.0 database integration

Native database compatibility is now checked against MariaDB 11.8.6 through PDO MySQL as well as SQLite. A real fresh-install index-length blocker is corrected, with a data-preserving forward normalisation for older SQLite checkpoints. Native audit-failure, persistence and initial-administrator concurrency tests are included. Actual Cloud/Oracle MySQL/schema acceptance remains separate.

## Concrete installation correction

The earlier signed-review migration allowed Laravel to generate an index name longer than the native server limit. Fresh source now declares `review_parent_withdrawn_idx` explicitly. Apply the new forward index-normalisation migration after earlier migrations; an older SQLite checkpoint’s index is renamed without any review/file/snapshot/actor/history changes. Fresh native installations already have the short name and the normaliser does nothing. No source versions, records, private files or identifiers are rewritten.

The name correction is forward-only. Its down method keeps the valid short index rather than reintroducing a name invalid on MySQL. The earlier signed-review create migration is deliberately corrected for new installs; existing migration history is not replayed.

If an earlier native installation ALREADY FAILED partway through that migration, stop and inspect its table/migration state before retrying. Native DDL can have committed some tables even though the migration did not finish. This pack does not automatically delete, reset or assume equivalence for an unknown partial/populated schema. Reconcile it in a backed-up isolated staging environment against the actual source. Never use the test runner’s migrate:fresh routine against a populated portal.

## What was exercised

The same current application suite runs on in-memory SQLite and MariaDB 11.8.6/InnoDB through Laravel’s PDO MySQL connection. Real native SIGNAL errors test transaction rollback; controlled duplicate/replay/source guards, imports, fixed packs, reviews, support, marking, careers and account flows remain exercised. Native assertion counts include extra migration-success assertions, so counts differ even with the same test cases.

The native fixture uses a case-insensitive database collation. For the deliberately ambiguous legacy-email recovery test only, the fresh fixture column uses a binary collation so that retained case-only duplicates can exist. No application email uniqueness policy is weakened. The schema is fresh for the next test.

Native DDL commits implicitly. The native test path recreates ONLY its own private test schema for each database-backed test instead of pretending every test sits inside a rollback transaction. AuditFault creates real SQLite/MariaDB failure triggers, not mocked application exceptions. The ordinary default test path remains testing/in-memory SQLite and refuses other databases before connecting/resetting.

## Reproduce the isolated engine checks locally

Use development dependencies and a local MariaDB installation with mariadbd, mariadb and mariadb-install-db. This run used Debian MariaDB 11.8.6 and PHP 8.4.27 with matching PDO MySQL/mysqlnd. Native runtime packages were checksum-verified against the existing Debian snapshot package metadata; they are test infrastructure and are not bundled as Cloud application dependencies.

```bash
python3 tests/mysql_engine.py --php /path/to/php --server-root /usr --output /tmp/gta-native-suite.json
```

The runner INITIALISES ITS OWN private temporary datadir/daemon, picks a loopback TCP listener, creates a random private test database/user, and writes a private matching marker/token. MariaDB’s supported socket activation passes only that already-bound loopback descriptor. It does not use an existing server or connect to a URL supplied by the user. The isolated reset guard validates host/port/database/user/token/marker before schema resets. Normal shutdown removes the fixture. Do not set its internal GTA_ENGINE_* values by hand or copy them into a Cloud environment.

For the actual HTTP version, install the test-only connector in a separate Python environment using tests/requirements-engine.txt (PyMySQL 1.1.2), then:

```bash
python3 tests/mysql_engine.py --php /path/to/php --server-root /usr --http-only --output /tmp/gta-native-http.json
```

If the connector lives in an isolated target directory, add --python-deps /path/to/directory. The HTTP fixture creates another owned schema and sends actual PHP/Laravel loopback requests. Its independent SQL checks compare full module rows after PHP restart and verify private-file hashes. JSON/DATE result normalisation belongs only to the test DB-API bridge; application queries and database types remain real.

Both modes also use a separate native schema and two PHP processes released from a common barrier to attempt the initial administrator simultaneously. Exactly one account, one initial creation audit and one consumed marker must remain. Other concurrency paths and target load are not certified by that one race.

## Cloud acceptance remains necessary

MariaDB 11.8.6 is not Oracle MySQL and is not Martin’s actual managed Cloud database. Confirm actual engine/version/collation/SQL mode/TLS, PHP extensions, repository/schema/identity mapping, permissions and private resources. Run read-only gta:cloud-check --database in authorised staging and apply only reviewed compatible migrations. Validate actual records, deployment/redeployment, storage, backup/restore and browser behaviour there. The source still permits only explicit correctly configured staging; production remains blocked.

No real learner data, live database, real credentials, external messages, actual Cloud connection or deployment was used. Composer/locked dependencies and all 531 source PDFs remain unchanged from v0.29.0. Earlier Cloud/S3 evidence remains historical scope; exact current evidence is in TEST_RESULTS.json.

Final executed v0.30.0 evidence: SQLite: 518 tests / 6,173 assertions; MariaDB 11.8.6: 518 tests / 6,670 assertions; 96 actual HTTP checks on each engine; 41-table restart persistence on each; native two-process first-admin race passed; 7 clean no-dev build checks; 339 PHP/compiled Blade checks; 531 unchanged PDF hashes. Exact managed Cloud/Oracle MySQL remains unverified.
