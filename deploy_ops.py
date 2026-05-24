"""
DigiTracker - Ops Infrastructure Deployment
Deploys: startup/shutdown/healthcheck scripts, systemd service,
         logrotate, improved Apache vhost, PHP logging config,
         updated app files with logger.
Target: 192.168.1.138  user: vboxuser
"""
import sys, paramiko, os, time
sys.stdout.reconfigure(encoding='utf-8', errors='replace')

HOST     = "192.168.1.138"
PORT     = 22
USER     = "vboxuser"
PASSWORD = "Delta@786"
PROJECT  = os.path.dirname(os.path.abspath(__file__))
REMOTE   = "/var/www/html/digitracker"
SCRIPT_DIR = "/var/app/script"
LOG_DIR    = "/var/app/logs"


def run(client, cmd, sudo=False, desc=""):
    if sudo:
        escaped = cmd.replace('"', '\\"')
        full = 'echo "%s" | sudo -S bash -c "%s"' % (PASSWORD, escaped)
    else:
        full = cmd
    if desc:
        print(">>> " + desc)
    _, stdout, stderr = client.exec_command(full, get_pty=True, timeout=120)
    out  = stdout.read().decode("utf-8", "replace").strip()
    err  = stderr.read().decode("utf-8", "replace").strip()
    code = stdout.channel.recv_exit_status()
    if out:
        print(out[-1200:])
    if err and code != 0:
        print("[ERR] " + err[-400:])
    return out, code


def upload_via_tmp(client, local_path, remote_path, mode="644", owner="root:root"):
    """Upload a file via /tmp to bypass www-data ownership restrictions."""
    fname   = os.path.basename(local_path)
    tmp     = "/tmp/_digi_" + fname
    sftp    = client.open_sftp()
    sftp.put(local_path, tmp)
    sftp.close()
    run(client,
        "cp %s %s && chown %s %s && chmod %s %s" % (tmp, remote_path, owner, remote_path, mode, remote_path),
        sudo=True)
    run(client, "rm -f " + tmp)
    print("  deployed: %s → %s [%s %s]" % (fname, remote_path, mode, owner))


def main():
    print("=" * 60)
    print("DigiTracker Ops Deployment")
    print("Target : %s@%s" % (USER, HOST))
    print("=" * 60)

    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(HOST, PORT, USER, PASSWORD, timeout=15)
    print("[CONNECTED] %s\n" % HOST)

    # ── Step 1: Create directories ─────────────────────────
    print("[STEP 1] Create /var/app directory structure")
    run(client, "mkdir -p %s %s" % (SCRIPT_DIR, LOG_DIR), sudo=True,
        desc="Create /var/app/script and /var/app/logs")
    run(client, "chown -R www-data:www-data %s && chmod -R 775 %s" % (LOG_DIR, LOG_DIR),
        sudo=True, desc="Set log dir ownership")
    run(client, "chmod 755 %s" % SCRIPT_DIR, sudo=True, desc="Set script dir permissions")

    # ── Step 2: Deploy shell scripts ───────────────────────
    print("\n[STEP 2] Deploy startup / shutdown / healthcheck scripts")
    scripts = [
        ("scripts/startup.sh",     SCRIPT_DIR + "/startup.sh",     "750", "root:root"),
        ("scripts/shutdown.sh",    SCRIPT_DIR + "/shutdown.sh",    "750", "root:root"),
        ("scripts/healthcheck.sh", SCRIPT_DIR + "/healthcheck.sh", "755", "root:root"),
    ]
    for local_rel, remote_path, mode, owner in scripts:
        local_abs = os.path.join(PROJECT, local_rel)
        upload_via_tmp(client, local_abs, remote_path, mode, owner)

    # Strip Windows line endings from scripts
    run(client, "sed -i 's/\\r//' %s/startup.sh %s/shutdown.sh %s/healthcheck.sh" % (
        SCRIPT_DIR, SCRIPT_DIR, SCRIPT_DIR), sudo=True, desc="Strip Windows CRLF from scripts")

    # ── Step 3: Deploy systemd service ─────────────────────
    print("\n[STEP 3] Install systemd service")
    svc_local = os.path.join(PROJECT, "scripts", "digitracker.service")
    upload_via_tmp(client, svc_local,
                   "/etc/systemd/system/digitracker.service", "644", "root:root")
    run(client, "sed -i 's/\\r//' /etc/systemd/system/digitracker.service",
        sudo=True, desc="Strip CRLF from service file")
    run(client, "systemctl daemon-reload", sudo=True, desc="Reload systemd daemon")
    run(client, "systemctl enable digitracker.service", sudo=True, desc="Enable digitracker service at boot")
    run(client, "systemctl status digitracker.service --no-pager -l 2>&1 | head -6",
        sudo=True, desc="Service status (pre-start)")

    # ── Step 4: Logrotate ──────────────────────────────────
    print("\n[STEP 4] Deploy log rotation config")
    lr_local = os.path.join(PROJECT, "scripts", "logrotate.conf")
    upload_via_tmp(client, lr_local,
                   "/etc/logrotate.d/digitracker", "644", "root:root")
    run(client, "sed -i 's/\\r//' /etc/logrotate.d/digitracker", sudo=True, desc="Strip CRLF")
    run(client, "logrotate --debug /etc/logrotate.d/digitracker 2>&1 | head -10",
        sudo=True, desc="Test logrotate config (dry run)")

    # ── Step 5: Apache vhost (improved logging) ────────────
    print("\n[STEP 5] Update Apache vhost with improved logging")
    vh_local = os.path.join(PROJECT, "scripts", "vhost.conf")
    upload_via_tmp(client, vh_local,
                   "/etc/apache2/sites-available/digitracker.conf", "644", "root:root")
    run(client, "sed -i 's/\\r//' /etc/apache2/sites-available/digitracker.conf",
        sudo=True, desc="Strip CRLF from vhost")
    # Enable headers mod for security headers in vhost
    run(client, "a2enmod headers", sudo=True, desc="Enable mod_headers")
    run(client, "apache2ctl configtest 2>&1", sudo=True, desc="Test Apache config")

    # ── Step 6: PHP ini (logging to /var/app/logs) ─────────
    print("\n[STEP 6] Update PHP ini for structured error logging")
    php_ini = (
        "upload_max_filesize = 10M\\n"
        "post_max_size = 12M\\n"
        "max_execution_time = 60\\n"
        "log_errors = On\\n"
        "display_errors = Off\\n"
        "error_log = /var/app/logs/php_errors.log\\n"
        "error_reporting = E_ALL\\n"
    )
    run(client,
        "printf '%s' '%s' > /etc/php/8.3/apache2/conf.d/99-digitracker.ini" % ("%b", php_ini),
        sudo=True, desc="Write PHP ini")

    # ── Step 6b: Run DB migrations ─────────────────────────────
    print("\n[STEP 6b] Run database migrations")
    for mig_file, desc_text in [
        ("migrate_v3.sql", "v3: expenses.payment_mode + card_last4"),
        ("migrate_v4.sql", "v4: loans.tenure_months + expenses.loan_ref_id/auto_generated"),
    ]:
        mig_local = os.path.join(PROJECT, mig_file)
        sftp = client.open_sftp()
        sftp.put(mig_local, "/tmp/" + mig_file)
        sftp.close()
        run(client, "mysql digitracker < /tmp/%s 2>&1 || true" % mig_file,
            sudo=True, desc="Run %s (%s)" % (mig_file, desc_text))
        run(client, "rm -f /tmp/" + mig_file)
    run(client, "mysql digitracker -e \"DESCRIBE expenses\" 2>&1 | grep -E 'payment_mode|loan_ref|auto_gen'",
        sudo=True, desc="Verify new columns exist")

    # ── Step 7: Deploy updated app files ──────────────────
    print("\n[STEP 7] Deploy updated PHP app files")
    app_files = [
        ("includes/logger.php",  REMOTE + "/includes/logger.php",  "644", "www-data:www-data"),
        ("includes/auth.php",    REMOTE + "/includes/auth.php",    "644", "www-data:www-data"),
        ("includes/header.php",  REMOTE + "/includes/header.php",  "644", "www-data:www-data"),
        ("expenses.php",         REMOTE + "/expenses.php",         "644", "www-data:www-data"),
        ("loans.php",            REMOTE + "/loans.php",            "644", "www-data:www-data"),
        ("dashboard.php",        REMOTE + "/dashboard.php",        "644", "www-data:www-data"),
        ("reports.php",          REMOTE + "/reports.php",          "644", "www-data:www-data"),
    ]
    for local_rel, remote_path, mode, owner in app_files:
        local_abs = os.path.join(PROJECT, local_rel)
        upload_via_tmp(client, local_abs, remote_path, mode, owner)

    # ── Step 8: Restart Apache ────────────────────────────
    print("\n[STEP 8] Restart Apache to apply all config changes")
    run(client, "systemctl restart apache2", sudo=True, desc="Restart Apache2")
    time.sleep(2)
    run(client, "systemctl is-active apache2", sudo=True, desc="Apache2 active?")

    # ── Step 9: Start the digitracker service ─────────────
    print("\n[STEP 9] Start digitracker service")
    run(client, "systemctl start digitracker.service", sudo=True, desc="Start digitracker.service")
    time.sleep(3)
    run(client, "systemctl status digitracker.service --no-pager -l 2>&1 | head -10",
        sudo=True, desc="Service status after start")

    # ── Step 10: Run healthcheck ───────────────────────────
    print("\n[STEP 10] Run healthcheck")
    run(client, "%s/healthcheck.sh --log" % SCRIPT_DIR, sudo=True, desc="Full health check")

    # ── Step 11: Verify log files created ─────────────────
    print("\n[STEP 11] Verify log infrastructure")
    run(client, "ls -lh %s/" % LOG_DIR, sudo=True, desc="Files in /var/app/logs/")
    run(client, "cat %s/service.log 2>/dev/null | tail -20" % LOG_DIR,
        sudo=True, desc="service.log tail")

    # ── Step 12: Smoke test ────────────────────────────────
    print("\n[STEP 12] HTTP smoke tests")
    time.sleep(1)
    run(client, "curl -sI http://localhost/ | head -4", desc="HTTP headers")
    run(client, "curl -s -o /dev/null -w 'expenses.php: HTTP %{http_code}' http://localhost/expenses.php",
        desc="expenses.php response")
    run(client, "curl -s -o /dev/null -w 'loans.php: HTTP %{http_code}' http://localhost/loans.php",
        desc="loans.php response")
    run(client, "curl -s -o /dev/null -w 'reports.php: HTTP %{http_code}' http://localhost/reports.php",
        desc="reports.php response")

    # ── Final summary ──────────────────────────────────────
    print("\n" + "=" * 60)
    print("OPS DEPLOYMENT COMPLETE")
    print("=" * 60)
    print("  Startup script : %s/startup.sh"     % SCRIPT_DIR)
    print("  Shutdown script: %s/shutdown.sh"    % SCRIPT_DIR)
    print("  Health check   : %s/healthcheck.sh" % SCRIPT_DIR)
    print("  Systemd service: digitracker.service (enabled, auto-start on boot)")
    print("  Log rotation   : /etc/logrotate.d/digitracker")
    print("  App logs dir   : %s/" % LOG_DIR)
    print("    service.log    — startup/shutdown/health events")
    print("    app.log        — PHP action audit trail (add/edit/delete)")
    print("    php_errors.log — PHP error log")
    print("    apache_access.log — HTTP access log with response times")
    print("    apache_error.log  — Apache error log")
    print("")
    print("  Commands:")
    print("    systemctl status digitracker")
    print("    %s/healthcheck.sh" % SCRIPT_DIR)
    print("    tail -f %s/app.log" % LOG_DIR)
    print("    journalctl -u digitracker -f")
    print("=" * 60)
    client.close()


if __name__ == "__main__":
    main()
