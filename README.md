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

  sudo -E certbot certonly --manual --preferred-challenges dns \
    --manual-auth-hook    "$(pwd)/scripts/duckdns-auth-hook.sh" \
    --manual-cleanup-hook "$(pwd)/scripts/duckdns-cleanup-hook.sh" \
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

  **Renewal**: certbot's default `certbot.timer` re-runs using the same recorded
  hook commands, so leave `scripts/duckdns-auth-hook.sh`/`duckdns-cleanup-hook.sh` in
  place — but `DUCKDNS_TOKEN`/`DUCKDNS_DOMAIN` were only set in your shell, not saved
  anywhere the timer's non-interactive renewal can see them. Persist them for
  renewal via a renewal hook environment file, e.g. add to
  `/etc/letsencrypt/renewal-hooks/pre/duckdns-env.sh`:
  ```bash
  #!/bin/sh
  export DUCKDNS_TOKEN=<your token>
  export DUCKDNS_DOMAIN=digitracker
  ```
  (`chmod +x` it — certbot sources everything in `renewal-hooks/pre/` before renewing.)

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
2. On the new machine, create the database + app user (idempotent, safe to re-run):
   ```bash
   sudo mysql < setup.sql
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

