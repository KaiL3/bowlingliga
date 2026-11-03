# Operation on a Debian Server

Installation and update are done by two scripts:
[deploy/install.sh](../deploy/install.sh) and
[deploy/update.sh](../deploy/update.sh). They are tested on Debian 12
(PHP 8.2) and Debian 13 (PHP 8.4) with nginx, PHP-FPM, MariaDB and
Let's Encrypt.

## Requirements

The scripts set up exactly this stack and nothing else:

- **Debian 12 or 13** with root access (`sudo`). Other distributions are not
  supported by the scripts; a manual installation follows the same steps.
- **nginx** as web server (installed if missing). Apache is not supported.
- **MariaDB on the same server** (installed if missing; a running server is
  shared with other applications). The scripts work as MariaDB root via the
  Unix socket (`sudo mariadb` without password) or `/root/.my.cnf`.
- **PHP ≥ 8.1** with the extensions fpm, cli, mysql, mbstring, gd, xml, zip and
  sodium (installed for the PHP version already present, otherwise the
  distribution's).
- A **domain** whose DNS record points to the server, and ports 80/443 (or
  your own HTTPS ports) reachable – otherwise no certificate can be issued.

## Instances

Every installation is an **instance** with a name (default `bowlingliga`).
The name is used for the database and its user, the directories, the PHP-FPM
pool and the nginx site, so several leagues can run on one server, each with
`INSTANCE=<name>`. Without `INSTANCE` the scripts take the instance they
installed before (recognised by the marker `(deploy/install.sh)` in its nginx
site), otherwise `bowlingliga`. The paths below use the default name;
replace it with yours.

## First installation

1. Copy the project folder to the server, e.g. upload it as a ZIP and
   unpack it, or via `scp -r`. The `vendor/` folder is not needed; the script
   installs the dependencies itself. Alternatively upload only
   `deploy/update.sh` and start it with `REPO=…` (see Update).
2. Start in the project folder:
   ```bash
   sudo bash deploy/install.sh
   ```
3. The script asks for
   - the domain (e.g. `league.example.org`),
   - an existing certificate with key and HTTPS ports, or (leave the path
     empty) an e-mail address for Let's Encrypt,
   - the default language of the website (one of the catalogs in `lang/`,
     default `de`): for visitors whose browser asks for none of the site's
     languages and for the printed score sheets of the first season; later
     seasons take over the previous one's, changeable under "Saison",
   - username and password of the first admin account (at least 8
     characters).
4. Then log in at `https://<domain>/admin` and set up the two-factor login
   under **Mein Konto** (my account).

What the script does:

| Step | Result |
|---|---|
| Packages | nginx, MariaDB, PHP-FPM with the required extensions, Composer, certbot. Packages that are already installed are not upgraded; a running MariaDB server (e.g. with databases of other applications) is shared. |
| Code | Copy to `/var/www.bowlingliga`, owned by `root`, the web server may only read |
| Configuration | `config/database.php` with a random database password, TOTP key, score sheet directory, default `language` and `base_url` (the address in the QR codes of the printed score sheets, derived from domain and HTTPS port). An existing configuration gets missing keys added (an older one gets `'language' => 'de'`, its behaviour so far). |
| Score sheets | Directory `/var/lib/bowlingliga/score-sheets` for the uploaded original score sheets, owned by the web server (`750`/`640`); owner and permissions are reset on every run. |
| Database | Database and user named like the instance, all migrations. Other databases stay untouched; if the database or user already exists on a fresh installation, the script aborts. |
| PHP | Own FPM pool (`/etc/php/<version>/fpm/pool.d/bowlingliga.conf`, socket `/run/php/bowlingliga.sock`): uploads up to 10 MB, sessions of 30 minutes in `/var/lib/bowlingliga/sessions`, no error output in the browser. Other PHP applications keep their settings. |
| nginx | Site `bowlingliga` with its own log files (`/var/log/nginx/bowlingliga.access.log`, `.error.log`), only `public/` is reachable; login throttled to 10 attempts per minute and IP (`/etc/nginx/conf.d/bowlingliga-rate-limit.conf`). The security headers (CSP, HSTS etc.) are set by the application itself. |
| HTTPS | Either an **existing certificate**: the script asks for `fullchain.pem`, the key (default `<domain>.key` next to it) and the ports (e.g. `443 444`). The site listens on them directly, also next to other sites on the same port (distinguished by the domain). Renewal (e.g. acme-tiny) is outside the script; afterwards `systemctl reload nginx`. Or, if the certificate path stays empty, **Let's Encrypt** via certbot with a redirect from HTTP and automatic renewal. |

Options: `NO_HTTPS=1` without certificate (e.g. behind a proxy that handles
HTTPS), `TARGET=/srv/league` another installation directory,
`INSTANCE=<name>` another instance, `PHP_VERSION=8.5` a specific PHP version
(by default the one already installed). Example:
`sudo INSTANCE=thursday TARGET=/srv/thursday bash deploy/install.sh`.

## Update

Updates come directly from the Git repository:

```bash
sudo bash /var/www.bowlingliga/deploy/update.sh            # latest state of main
sudo bash /var/www.bowlingliga/deploy/update.sh f8980f5    # specific state, e.g. to go back
```

**Repository:** `REPO=<address>` on the first run; afterwards the address is
remembered (`/etc/bowlingliga/install.conf`, otherwise taken from the working
copy). A public repository is fetched via HTTPS without any key:
`sudo REPO=https://github.com/<owner>/<repository>.git bash update.sh` –
for the official repository `REPO=https://github.com/KaiL3/bowlingliga.git`.

For a **private repository** use its SSH address (`git@github.com:…`). On the
first run `update.sh` then creates a **deploy key**
(`/root/.ssh/bowlingliga_deploy`) and shows its public part. Add it on GitHub
under *Repository → Settings → Deploy keys → Add deploy key*, **without**
"Allow write access", then start the script again. The key only grants read
access to this one repository.

`update.sh` fetches the state into the working copy
`/var/lib/bowlingliga/source` (a pure mirror, local changes there are lost),
shows the new commits, backs up the database to
`/var/backups/bowlingliga/pre-update-<date>.sql.gz` (the last ten are kept;
`NO_BACKUP=1` skips the backup) and then starts `deploy/install.sh` from the
new state. `BRANCH=<name>` fetches another branch.

`update.sh` does not need a project next to it: on a fresh server it is
enough to upload just this file and run it with `REPO=…` – it clones the
repository, and `install.sh` asks for the details of the first installation.
Afterwards always call the copy in the web directory, which is updated along
with everything else, conveniently via a link:
`sudo ln -s /var/www.bowlingliga/deploy/update.sh /usr/local/sbin/bowlingliga-update`.

Without Git it still works as for the first installation: upload the
project folder and run `sudo bash deploy/install.sh`.

During an update `install.sh` asks nothing. The nginx site is rewritten, an
existing certificate is reattached afterwards (`certbot install`). Domain,
e-mail, certificate, target directory and repository are stored in
`/etc/bowlingliga/install.conf`. It copies the new code, installs the
dependencies and applies new migrations. Configuration, data and the HTTPS
certificate are kept.

## Important files

| File | Content |
|---|---|
| `/var/www.bowlingliga/config/database.php` | Database access, **TOTP key**, `score_sheet_dir`, `base_url` and the default `language` (changed here, takes effect immediately). The key must never change, otherwise the two-factor logins that have been set up stop working. Take it along when moving servers. |
| `/var/lib/bowlingliga/score-sheets/` | Uploaded original score sheets as immutable files, named after their SHA-256 hash (`objects/3f/a9c1…`). Raw data: belongs in every backup together with the database. Never edit or rename files there by hand. |
| `/etc/nginx/sites-available/bowlingliga` | nginx site (certbot adds the HTTPS part) |
| `/etc/bowlingliga/install.conf` | Settings for updates |
| `/var/log/nginx/bowlingliga.access.log` | Requests to this instance (rotated by logrotate like all nginx logs) |
| `/var/log/nginx/bowlingliga.error.log` | Errors from nginx and PHP of this instance |

## Backup

The data lives in two places that belong together:

| What | Where | Changes |
|---|---|---|
| Database (all league data, accounts, CMS texts, score sheet metadata with checksum) | MariaDB database `bowlingliga` | daily |
| Original score sheets (photos/PDFs) | `/var/lib/bowlingliga/score-sheets/` | only new files are added; a file never changes, it disappears only when its fixture is deleted |

In addition `config/database.php` (TOTP key) must be kept once in a safe
place. A daily backup of database and score sheet directory is enough.

### Recommended: Borg

[Borg](https://borgbackup.readthedocs.io/) stores every file and every
recurring part of the database dump only once. Thirty daily states then cost
hardly more than one, because the score sheets never change. Compression,
encryption and a copy on another machine come with it. Package:
`sudo apt install borgbackup`.

Set up once (the repository may also be on another server via
`ssh://user@host/./bowlingliga`):

```bash
sudo mkdir -p /var/backups/bowlingliga
sudo sh -c 'umask 077; openssl rand -base64 32 > /root/.bowlingliga-borg-passphrase'
sudo BORG_PASSCOMMAND='cat /root/.bowlingliga-borg-passphrase' \
    borg init --encryption=repokey-blake2 /var/backups/bowlingliga/borg
sudo borg key export /var/backups/bowlingliga/borg /root/bowlingliga-borg.key
```

Keep the passphrase and the exported key **outside the server** (e.g. in the
password manager); without both the backup cannot be read.

Daily backup as cron job `/etc/cron.daily/bowlingliga-backup` (executable,
`chmod 755`):

```sh
#!/bin/sh
set -eu
export BORG_REPO=/var/backups/bowlingliga/borg
export BORG_PASSCOMMAND='cat /root/.bowlingliga-borg-passphrase'
DUMP=/var/backups/bowlingliga/database.sql

# Uncompressed dump: Borg deduplicates it against the previous days and
# compresses it itself (a gzip file would be completely different every day).
mariadb-dump --single-transaction --skip-dump-date bowlingliga > "$DUMP"

# First the dump, then the directory: score sheets uploaded in between are
# at most additionally included.
borg create --compression zstd,6 ::'bowlingliga-{now:%Y-%m-%d}' \
    "$DUMP" /var/lib/bowlingliga/score-sheets /var/www.bowlingliga/config/database.php
borg prune --glob-archives 'bowlingliga-*' --keep-daily 30 --keep-monthly 12
borg compact
rm -f "$DUMP"

# Reports missing or damaged score sheet files (exit code 1, cron sends a mail).
php /var/www.bowlingliga/bin/check-score-sheets.php > /dev/null
```

The archive prefix `bowlingliga-` lets the league share a Borg repository
with other applications; `prune` then only touches the
league's archives.

Check the backup now and then: `sudo BORG_PASSCOMMAND='cat /root/.bowlingliga-borg-passphrase' borg list /var/backups/bowlingliga/borg`.

### Restore

```bash
export BORG_REPO=/var/backups/bowlingliga/borg
export BORG_PASSCOMMAND='cat /root/.bowlingliga-borg-passphrase'
borg list                                     # choose an archive
cd / && sudo -E borg extract ::bowlingliga-2026-10-19 \
    var/backups/bowlingliga/database.sql var/lib/bowlingliga/score-sheets
sudo mariadb bowlingliga < /var/backups/bowlingliga/database.sql
sudo chown -R www-data:www-data /var/lib/bowlingliga/score-sheets
sudo php /var/www.bowlingliga/bin/check-score-sheets.php
```

Restore database and directory **from the same archive**. Files that no
record references any more after an older restore are removed by
`check-score-sheets.php --clean`.

### Without Borg

The same with on-board tools – the dump compressed per day, the score sheet
directory mirrored (`rsync` only copies new files, because existing ones never
change):

```sh
#!/bin/sh
set -eu
mkdir -p /var/backups/bowlingliga
mariadb-dump --single-transaction bowlingliga | gzip > /var/backups/bowlingliga/$(date +%F).sql.gz
find /var/backups/bowlingliga -name '*.sql.gz' -mtime +30 -delete
rsync -a /var/lib/bowlingliga/score-sheets/ /var/backups/bowlingliga/score-sheets/
```

Without `--delete`, the mirror keeps files of deleted fixtures as well, so
every dump of the last 30 days can be restored completely. The mirror only
protects against mistakes, not against a lost server – copy
`/var/backups/bowlingliga` to another machine regularly.

### Before updates

`update.sh` additionally saves a database dump before every update
(`pre-update-<date>.sql.gz`, see above). An update does not change the score
sheet files.

## Emergency help

- **Another admin account via the command line:**
  `sudo php /var/www.bowlingliga/bin/create-admin.php <name>`
- **Phone with the authenticator app lost:** reset the two-factor login of
  the account and set it up again after logging in:
  ```bash
  sudo mariadb bowlingliga -e "UPDATE admin_account SET totp_secret_encrypted = NULL WHERE username = '<name>'"
  ```
- **Account locked after failed attempts:** the lock ends by itself after 15
  minutes.
- **HTTPS broken (e.g. certificate expired):** browsers that visited the site
  before refuse it without a "continue anyway" while their HSTS entry is
  valid (`TransportSecurity::HSTS_MAX_AGE`, currently 1 day). Renew the
  certificate (`sudo certbot renew`, then `sudo systemctl reload nginx`); do
  not switch the site to plain HTTP.
- **Migrations by hand:** `sudo php /var/www.bowlingliga/bin/migrate.php`
- **Check the score sheet files:**
  `sudo -u www-data php /var/www.bowlingliga/bin/check-score-sheets.php` reports
  missing and damaged files; `--clean` additionally deletes files no record
  needs any more. A page `/score-sheet/{id}` that answers "Die Datei dieses
  Spielzettels fehlt" means the file must be restored from the backup.
- **Empty the database for a fresh start (admin accounts are kept):**
  `sudo php /var/www.bowlingliga/bin/reset-database.php` shows what would be
  deleted, `--yes` deletes it.
- **Demo league for trying it out (empty database only):**
  `sudo -u www-data php /var/www.bowlingliga/bin/seed-demo.php` creates a
  fictional season with six teams, plans it and confirms the results of the
  matchdays in the past, through the same use cases as the admin area. News
  and rules are written in the default language. It refuses to run if seasons
  exist; empty the database first with `reset-database.php --yes`.
