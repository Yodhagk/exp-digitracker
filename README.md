# exp-digitracker
Creating the Project for the digital tracker with admin portal
Plan to Login form with php and access the Databases
/* Try to access the Users and login from the project */
Initial We have the login form We we can signup and Register to the site


Login Page
<img width="1888" height="776" alt="image" src="https://github.com/user-attachments/assets/00d64095-44b5-4df8-912d-3c2e9d7a7364" />

DashBoard :
<img width="1888" height="908" alt="image" src="https://github.com/user-attachments/assets/9e484d96-6f32-4618-97ce-e89b211b7d1f" />

Loans :

<img width="1917" height="547" alt="image" src="https://github.com/user-attachments/assets/a98c5dd8-dd76-4e5d-8ced-a82ec5e4b2fc" />

Upcoming :

<img width="1879" height="786" alt="image" src="https://github.com/user-attachments/assets/c4aeffac-2239-47b3-8745-e6369a3b0e7b" />

## Secrets & configuration

No credentials live in this repository. Everything sensitive is a **GitHub Actions
secret** (repo → Settings → Secrets and variables → Actions) that the deploy writes to
the server as `/etc/digitracker/digitracker.env` (`root:www-data`, mode `0640`, one
`KEY='value'` per line) via `scripts/write-secrets.sh`. That single file is read by
`config.php` (PHP, as `www-data`), by `scripts/startup.sh` / `healthcheck.sh` (as
root), and by the DuckDNS certbot hooks (unattended renewal, as root). A real
environment variable of the same name overrides the file if both exist.

| Secret | Required | Purpose |
|---|---|---|
| `DB_PASS` | **yes** | App DB user's password. The deploy **aborts before touching anything** if this is unset. |
| `DB_HOST`, `DB_NAME`, `DB_USER` | no | Default to `localhost` / `digitracker` / `digiuser`. |
| `GMAIL_CLIENT_ID`, `GMAIL_CLIENT_SECRET` | no | Enables Gmail sync on the Shopping page. |
| `GMAIL_REDIRECT_URI` | no | Only if the auto-derived `https://<Host>/shopping.php` isn't what you registered with Google. |
| `DUCKDNS_TOKEN`, `DUCKDNS_DOMAIN` | no | Lets certbot renew the HTTPS certificate unattended. |

The CI health check runs as `github-runner`, which can't read the `0640` file; it
falls back to a root-socket DB check via its `NOPASSWD` sudo for `mysql` and says so
in its output — that's expected, not a failure.

**Credentials that were previously committed to this public repo are still in its git
history** (`Digi@2026` for the DB user, the `vboxuser` SSH password, an old root MySQL
password). Moving them out of the tree does not un-leak them — rotate them. Safe order
for the DB password, to avoid an outage:

1. Add the GitHub secrets with the **current** password and let one deploy run
   (the app now reads the file instead of hardcoded values).
2. On the server: `sudo mysql -e "ALTER USER 'digiuser'@'localhost' IDENTIFIED BY '<new>'; FLUSH PRIVILEGES;"`
3. Immediately update the `DB_PASS` secret to the new value and re-run the deploy.
   (The app is down only between steps 2 and 3 — do them back to back.)

Also rotate the `vboxuser` login (`passwd vboxuser`) and, since it was pasted into a
chat transcript, regenerate the DuckDNS token from its dashboard.

## Database Backup & Restore

Backups are full `mysqldump` snapshots (schema + data), gzip-compressed, written to
`/var/backups/digitracker/`. `latest.sql.gz` always points at the most recent backup.

**Take a backup manually:**
```bash
sudo /var/app/script/backup-db.sh
```

**Automatic daily backups** — one-time setup on the server:
```bash
sudo cp scripts/digitracker-backup.service scripts/digitracker-backup.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now digitracker-backup.timer
```
Every deploy (`.github/workflows/deploy.yml`) also takes a backup before running migrations,
as a safety net.

### Troubleshooting: deploy jobs stuck "Waiting for a runner"

If a workflow run sits in `queued` with *"Waiting for a runner to pick up this job..."*,
the self-hosted runner is not connected. Check it on the server:

```bash
systemctl status 'actions.runner.*' --no-pager
```

Then read why it stopped:

```bash
sudo journalctl -u 'actions.runner.*' -n 30 --no-pager
```

**Most common cause — deleted registration.** If the log says:

> `Failed to create a session. The runner registration has been deleted from the server,
> please re-configure.`

then GitHub removed the registration because the machine was offline too long (this
happens automatically after roughly two weeks). The runner still connects and
authenticates, but can never open a session, so every job stays queued. Re-register it:

```bash
sudo bash scripts/setup-runner.sh
```

The script is safe to re-run: it stops and uninstalls the old service, installs the
current runner version, and passes `--replace` so it takes over the existing runner
name. You need a fresh registration token from
`Settings → Actions → Runners → New self-hosted runner` (tokens are short-lived).

Do **not** add a systemd `Restart=` directive to the runner service. When the runner
hits a terminal error it exits on purpose and logs `no retry needed`; restarting it
in a loop just hides the real problem behind a restart counter.

### Gmail OAuth over HTTPS (DuckDNS + Let's Encrypt)

The Shopping page's Gmail sync needs `GMAIL_CLIENT_ID`/`GMAIL_CLIENT_SECRET` from a
Google Cloud OAuth client (Google Cloud console → APIs & Services). Two things Google
enforces that a private-IP LAN box doesn't satisfy on its own:

- **No bare IP as a redirect URI** — it must be `localhost` or a hostname ending in a
  public suffix (`.com`, `.org`, ...). Fix: a free [DuckDNS](https://www.duckdns.org)
  subdomain (e.g. `digitracker.duckdns.org`) pointed at this box's LAN IP, resolved
  locally via each client's hosts file (or the router's local DNS) — Google only
  validates the string shape, it never needs to actually reach the address.
- **HTTPS is mandatory for sensitive scopes** (`gmail.readonly` is one) — plain
  `http://` redirect URIs are rejected outright. Get a real cert via Let's Encrypt
  using a DNS-01 challenge against DuckDNS, which needs no port-forwarding since
  nothing has to reach this box from the internet:

  ```bash
  sudo apt install certbot
  sudo a2enmod ssl headers

  export DUCKDNS_TOKEN=<your token from duckdns.org>
  export DUCKDNS_DOMAIN=digitracker        # subdomain only, no .duckdns.org suffix

  # Use the copies the deploy syncs to /var/app/script/ (mode 755, survive reboots) —
  # certbot records these paths and re-runs them on every renewal, so they must not
  # point into a /tmp clone.
  sudo -E certbot certonly --manual --preferred-challenges dns \
    --manual-auth-hook    /var/app/script/duckdns-auth-hook.sh \
    --manual-cleanup-hook /var/app/script/duckdns-cleanup-hook.sh \
    -d digitracker.duckdns.org
  ```

  Then enable the HTTPS vhost — kept as a *separate* file
  (`scripts/vhost-ssl.conf`) from the port-80 one, because Apache refuses to start
  if a loaded vhost points at a cert file that doesn't exist yet, so it must only be
  installed after certbot succeeds:

  ```bash
  sudo cp scripts/vhost-ssl.conf /etc/apache2/sites-available/digitracker-ssl.conf
  sudo a2ensite digitracker-ssl
  sudo apache2ctl configtest && sudo systemctl reload apache2
  ```

  If the certificate was first issued with the hooks in a temporary location (e.g. a
  `/tmp` clone), certbot recorded *those* paths and renewal will fail once they're
  gone. Point the renewal config at the stable copies, then prove it works:
  ```bash
  sudo sed -i 's#/tmp/digi/scripts/#/var/app/script/#g' /etc/letsencrypt/renewal/digitracker.duckdns.org.conf
  sudo certbot renew --dry-run
  ```
  Apache's `AH00558: Could not reliably determine the server's fully qualified domain
  name` notice on every `apache2ctl` call is cosmetic; silence it with a global
  `ServerName`: `echo "ServerName digitracker.duckdns.org" | sudo tee /etc/apache2/conf-available/servername.conf && sudo a2enconf servername && sudo systemctl reload apache2`.

  **Renewal**: certbot's `certbot.timer` re-runs the recorded hook commands
  unattended, as root, with no shell exports. The hooks handle this themselves: when
  `DUCKDNS_TOKEN` isn't in the environment they read it from
  `/etc/digitracker/digitracker.env` — so set `DUCKDNS_TOKEN` (and optionally
  `DUCKDNS_DOMAIN`) as GitHub Actions secrets too, and the deploy keeps that file
  current. Nothing else to configure.

In Google Cloud, set the redirect URI to `https://digitracker.duckdns.org/shopping.php`
and access the app via that hostname going forward — `GMAIL_REDIRECT_URI` derives
from the request's `Host` header when the env var itself isn't set (see `config.php`),
so as long as you consistently browse to that hostname you may not need to set
`GMAIL_REDIRECT_URI` explicitly at all, only `GMAIL_CLIENT_ID`/`GMAIL_CLIENT_SECRET`.

### Migrating to a new machine

1. Copy the latest backup from the old machine:
   ```bash
   scp old-host:/var/backups/digitracker/latest.sql.gz .
   ```
2. On the new machine, create the database + app user (idempotent, safe to re-run).
   `setup.sql` carries a `__DB_PASS__` placeholder rather than the real password —
   substitute the same value you set as the `DB_PASS` GitHub secret:
   ```bash
   DB_PASS='<the app DB password>' sh -c 'sed "s/__DB_PASS__/$DB_PASS/" setup.sql | sudo mysql'
   ```
3. Load the real data from the backup:
   ```bash
   scp latest.sql.gz new-host:/tmp/
   sudo bash scripts/restore-db.sh /tmp/latest.sql.gz --force
   ```
   (`restore-db.sh` refuses to overwrite a database that already has users unless `--force`
   is passed — that guard only matters when restoring onto a machine that already has data.)
4. Deploy the app files as usual (`scripts/deploy-app.sh`, or push to `main` to trigger CI),
   then enable the backup timer (above) on the new box.

