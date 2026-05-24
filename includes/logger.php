<?php
/**
 * DigiTracker - Structured Application Logger
 * Writes to /var/app/logs/app.log on server; silently no-ops in local dev.
 */
class AppLogger
{
    private static string $file = '';

    public static function init(): void
    {
        $dir = '/var/app/logs';
        if (is_dir($dir) && is_writable($dir)) {
            self::$file = $dir . '/app.log';
            ini_set('log_errors', '1');
            ini_set('error_log', $dir . '/php_errors.log');
        }
        // No-op in local dev (dir doesn't exist) — no logs written, no errors thrown
    }

    private static function write(string $level, string $message): void
    {
        if (self::$file === '') return;

        $uid  = (string)(isset($_SESSION['id']) ? $_SESSION['id'] : '-');
        $user = isset($_SESSION['username']) ? $_SESSION['username'] : '-';
        $ip   = $_SERVER['REMOTE_ADDR'] ?? '-';
        $page = basename($_SERVER['PHP_SELF'] ?? '-');

        $line = sprintf(
            '[%s] [%-6s] [uid:%-3s|%s] [%s] [%s] %s',
            date('Y-m-d H:i:s'), $level, $uid, $user, $ip, $page, $message
        );
        @file_put_contents(self::$file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public static function info(string $msg): void   { self::write('INFO',   $msg); }
    public static function warn(string $msg): void   { self::write('WARN',   $msg); }
    public static function error(string $msg): void  { self::write('ERROR',  $msg); }
    public static function action(string $msg): void { self::write('ACTION', $msg); }
    public static function auth(string $msg): void   { self::write('AUTH',   $msg); }
}

AppLogger::init();
