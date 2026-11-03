# Data Transfer between League Databases

`bin/transfer-data.php` with `src/Persistence/LeagueDataTransfer.php` copies
the complete league data of one database into another. The exports
(`*.json.gz`) contain real league data and are excluded from the repository
(`.gitignore`).

## Filling the online demo with the local demo data

`bin/transfer-data.php` transfers the data of one database into another.
Both must be on the same migration level, so update the server to the
current project state first.

1. Export locally. This only reads; the demo database may be in use meanwhile:
   ```bash
   php bin/transfer-data.php export demo.json.gz
   ```
2. Copy the file to the server, e.g. `scp demo.json.gz server:/tmp/`.
3. On the server, first look at what would be replaced, then import:
   ```bash
   cd /var/www.bowlingliga        # installation directory of the instance
   sudo -u www-data php bin/transfer-data.php import /tmp/demo.json.gz          # preview only
   sudo -u www-data php bin/transfer-data.php import /tmp/demo.json.gz --yes    # replaces the data
   ```

The import replaces **all** league data of the server (seasons, teams,
players, results, texts, league name, score sheet photos) in one transaction. The score
sheet files travel in the export (`files`, hash → base64) and are written
into the target's `score_sheet_dir` before the records; afterwards the files
of the replaced data are deleted. The whole export is held in memory – with
many seasons of photos the PHP `memory_limit` of the command line must be
large enough. Run the import as the web server user (`sudo -u www-data`),
so that the score sheet files it writes stay readable for the website. The
server's admin accounts including 2FA as well as the legal notice and the
privacy policy stay untouched; the two legal texts are only replaced with
`--with-legal-texts`. The caches are emptied and recomputed on the next
request. Take a backup first (see [operations.md](operations.md#backup)) if
there is already real data on the server.

## Decision

| Date | Decision | Effect in the code |
|---|---|---|
| 2026-10-14 | **Fill the online demo from the local demo database:** export/import via the command line; the import replaces all league data but keeps the server's admin accounts and (unless explicitly requested) its legal notice/privacy policy. | `bin/transfer-data.php`, `LeagueDataTransfer`, [operations.md](operations.md) |

## Implementation note

- Addendum online demo (2026-10-14): `bin/transfer-data.php export|import`
  with `Persistence\LeagueDataTransfer`. Export as gzip JSON from a consistent
  snapshot (binary data base64); the import checks tables and columns against
  the target, replaces all league data with their ids in one transaction
  (foreign key checks off meanwhile) and empties the caches; never
  transferred: `admin_account`, `login_attempt`, `schema_migration`;
  `legal_notice`/`privacy_policy` only with `--with-legal-texts`; without
  `--yes` preview only. Tests: `LeagueDataTransferIntegrationTest` (4).
  Checked with the real demo export (487 rows, 0.2 MB) into a freshly
  migrated test database.
- Renamed on 2026-10-19 (formerly `bin/demo-data.php`, `DemoDataTransfer`,
  `docs/demo-daten.md`), because the script transfers existing data and does
  not generate demo data. Since then script, class, test and this file are
  part of the repository; only the exports stay local.
