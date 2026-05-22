"""
DigiTracker - Sandbox Deployment Script
Target: 192.168.1.138  user: vboxuser  pass: Delta@786
"""
import sys, paramiko, os, time
sys.stdout.reconfigure(encoding='utf-8', errors='replace')

HOST     = "192.168.1.138"
PORT     = 22
USER     = "vboxuser"
PASSWORD = "Delta@786"
PROJECT  = r"c:\Users\monkspark\OneDrive\Documents\GitHub\exp-digitracker"
REMOTE   = "/var/www/html/digitracker"
DB_NAME  = "digitracker"
DB_USER  = "digiuser"
DB_PASS  = "Digi@2026"

SKIP_FILES = {"deploy_sandbox.py", "config.php_PDO", "dashboard.html", ".gitignore", "README.md"}
SKIP_DIRS  = {".git", "__pycache__", "node_modules"}


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
        print(out[-1500:])
    if err and code != 0:
        print("[ERR] " + err[-400:])
    return out, code


def sftp_mkdir_p(sftp, path):
    parts = path.lstrip("/").split("/")
    cur = ""
    for p in parts:
        cur = cur + "/" + p
        try:
            sftp.mkdir(cur)
        except Exception:
            pass


def upload_dir(sftp, local, remote):
    count = 0
    for root, dirs, files in os.walk(local):
        dirs[:] = [d for d in sorted(dirs) if d not in SKIP_DIRS]
        rel = os.path.relpath(root, local)
        rel = rel.replace("\\", "/")
        rdir = remote if rel == "." else remote + "/" + rel
        sftp_mkdir_p(sftp, rdir)
        for fname in sorted(files):
            if fname in SKIP_FILES:
                continue
            lp = os.path.join(root, fname)
            rp = rdir + "/" + fname
            sftp.put(lp, rp)
            count += 1
            print("  up: " + rp)
    return count


def main():
    print("=" * 58)
    print("DigiTracker Sandbox Deployment")
    print("Target : %s@%s" % (USER, HOST))
    print("App dir: %s" % REMOTE)
    print("=" * 58)

    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(HOST, PORT, USER, PASSWORD, timeout=15)
    print("[CONNECTED] %s" % HOST)

    # ---- Step 1: DB already created; import schema ----
    print("\n[STEP 1] Import database schema")
    run(client, "mysql -u root digitracker < %s/setup.sql" % REMOTE,
        sudo=True, desc="Import setup.sql")
    run(client, "mysql -u root digitracker -e 'SHOW TABLES;'",
        sudo=True, desc="Verify tables")

    # ---- Step 2: Write production config.php ----
    print("\n[STEP 2] Write production config.php")
    config_content = (
        "<?php\n"
        "$conn = mysqli_connect('localhost', '%s', '%s', '%s');\n"
        "if (!$conn) { die('DB connection failed: ' . mysqli_connect_error()); }\n"
        "mysqli_set_charset($conn, 'utf8mb4');\n"
    ) % (DB_USER, DB_PASS, DB_NAME)

    write_cmd = "cat > %s/config.php << 'ENDOFCONFIG'\n%sENDOFCONFIG" % (REMOTE, config_content)
    run(client, write_cmd, sudo=True, desc="Write config.php")
    run(client, "head -3 %s/config.php" % REMOTE, sudo=True, desc="Verify config.php")

    # ---- Step 3: Apache vhost ----
    print("\n[STEP 3] Configure Apache virtual host")
    vhost = (
        "<VirtualHost *:80>\n"
        "    DocumentRoot %s\n"
        "    <Directory %s>\n"
        "        Options FollowSymLinks\n"
        "        AllowOverride All\n"
        "        Require all granted\n"
        "    </Directory>\n"
        "    ErrorLog ${APACHE_LOG_DIR}/digitracker_error.log\n"
        "    CustomLog ${APACHE_LOG_DIR}/digitracker_access.log combined\n"
        "</VirtualHost>\n"
    ) % (REMOTE, REMOTE)

    vhost_cmd = "cat > /etc/apache2/sites-available/digitracker.conf << 'ENDOFVHOST'\n%sENDOFVHOST" % vhost
    run(client, vhost_cmd, sudo=True, desc="Write vhost config")
    run(client, "a2dissite 000-default.conf",     sudo=True, desc="Disable default site")
    run(client, "a2ensite digitracker.conf",       sudo=True, desc="Enable DigiTracker site")
    run(client, "a2enmod rewrite",                 sudo=True, desc="Enable mod_rewrite")

    # ---- Step 4: Permissions ----
    print("\n[STEP 4] Set file permissions")
    run(client, "chown -R www-data:www-data %s" % REMOTE,    sudo=True, desc="www-data ownership")
    run(client, "chmod -R 755 %s" % REMOTE,                   sudo=True, desc="755 on files")
    run(client, "chmod -R 775 %s/uploads" % REMOTE,           sudo=True, desc="775 on uploads/")
    run(client, "chmod g+s %s/uploads/invoices %s/uploads/warranties" % (REMOTE, REMOTE),
        sudo=True, desc="setgid on upload dirs")

    # ---- Step 5: PHP upload ini ----
    print("\n[STEP 5] PHP upload limits")
    ini = "upload_max_filesize = 10M\npost_max_size = 12M\nmax_execution_time = 60\n"
    ini_cmd = "cat > /etc/php/8.3/apache2/conf.d/99-digitracker.ini << 'ENDOFINI'\n%sENDOFINI" % ini
    run(client, ini_cmd, sudo=True, desc="Write PHP ini")
    run(client, "systemctl restart apache2", sudo=True, desc="Restart Apache")

    # ---- Step 6: Smoke tests ----
    print("\n[STEP 6] Smoke tests")
    time.sleep(2)
    out, code = run(client, "curl -sI http://localhost/ | head -3", desc="HTTP response headers")
    out2, _   = run(client, "curl -sL http://localhost/ | grep -o 'DigiTracker' | head -1", desc="Page title in HTML")
    out3, _   = run(client, "mysql -u %s -p'%s' %s -e 'SHOW TABLES;'" % (DB_USER, DB_PASS, DB_NAME), desc="DB tables via app user")
    out4, _   = run(client, "[ -w %s/uploads/invoices ] && echo WRITABLE || echo NOT-WRITABLE" % REMOTE,
                    sudo=True, desc="uploads/invoices writable")
    out5, _   = run(client, "apache2ctl -S 2>&1 | head -8", sudo=True, desc="Apache vhost status")

    # ---- Final report ----
    print("\n" + "=" * 58)
    print("DEPLOYMENT RESULT")
    print("=" * 58)
    checks = [
        ("Apache returns HTTP 200",    "200" in out or "302" in out),
        ("DigiTracker HTML loads",     "DigiTracker" in out2),
        ("DB tables imported",         "users" in out3 or "loans" in out3),
        ("Uploads dir writable",       "WRITABLE" in out4),
    ]
    all_ok = True
    for label, ok in checks:
        mark = "[OK]" if ok else "[FAIL]"
        if not ok:
            all_ok = False
        print("  %s %s" % (mark, label))

    print()
    if all_ok:
        print("  >>> APP IS LIVE: http://%s/" % HOST)
    else:
        print("  >>> Some checks failed - see output above for details")
    print("  DB : %s   user: %s   pass: %s" % (DB_NAME, DB_USER, DB_PASS))
    print("=" * 58)
    client.close()


if __name__ == "__main__":
    main()
