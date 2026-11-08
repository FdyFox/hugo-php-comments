<?php
/*
 * Shared code for api.php (public endpoint) and admin.php.
 *
 * Comments live in a single SQLite file in the data directory. Settings come
 * from config.php next to this file, or from the file named by COMMENTS_CONFIG.
 */
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

const MAX_NAME     = 80;
const MAX_EMAIL    = 200;
const MAX_WEBSITE  = 200;
const MAX_COMMENT  = 5000;
const RATE_WINDOW  = 600;      // rate limit window in seconds; IP hashes are kept this long
const LOGIN_WINDOW = 900;      // failed admin logins count for this many seconds
const TOKEN_TTL    = 2592000;  // moderation links in notification mails work for 30 days

function config(): array
{
    static $config = null;
    if ($config === null) {
        $file   = getenv('COMMENTS_CONFIG') ?: __DIR__ . '/config.php';
        $config = (is_file($file) ? require $file : []) + [
            'admin_password_hash' => '',
            'author_name'         => 'Author',
            'site_url'            => '',
            'mail_to'             => '',
            'mail_from'           => '',
            'allowed_sections'    => ['posts'],
            'language'            => 'en',
            'timezone'            => 'UTC',
            'date_format'         => 'Y-m-d H:i',
            'data_dir'            => __DIR__ . '/data',
            'data_dir_protected'  => false,  // set to true once your server blocks the data directory itself
            'admin_css'           => '',
            'cors'                => false,  // development only: Hugo's dev server runs on another port
        ];
        date_default_timezone_set($config['timezone']);
    }
    return $config;
}

function t(string $key, array $vars = []): string
{
    static $strings = null;
    if ($strings === null) {
        $file    = __DIR__ . '/lang/' . basename((string) config()['language']) . '.php';
        $strings = (is_file($file) ? require $file : []) + require __DIR__ . '/lang/en.php';
    }
    $replace = [];
    foreach ($vars as $name => $value) {
        $replace['{' . $name . '}'] = (string) $value;
    }
    return strtr($strings[$key] ?? $key, $replace);
}

/**
 * Refuses to run when the database would be downloadable: .htaccess only works
 * on Apache and LiteSpeed, so anywhere else the data must live outside the web root.
 */
function assert_data_dir_is_safe(string $dir): void
{
    if (config()['data_dir_protected']) {
        return;
    }
    $root   = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
    $real   = realpath($dir) ?: $dir;
    $inside = $root !== '' && str_starts_with(rtrim($real, '/') . '/', rtrim($root, '/') . '/');
    if ($inside && !preg_match('/apache|litespeed/i', $_SERVER['SERVER_SOFTWARE'] ?? '')) {
        error_log('comments: data_dir is inside the web root on a server that ignores .htaccess. '
                . "Move data_dir outside the web root, or block it and set 'data_dir_protected' => true.");
        http_response_code(500);
        exit('Comments are disabled until the data directory is protected. See the server error log.');
    }
}

function db(): PDO
{
    static $db = null;
    if ($db !== null) {
        return $db;
    }
    $dir = config()['data_dir'];
    assert_data_dir_is_safe($dir);
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    if (!is_file("$dir/.htaccess")) {
        file_put_contents("$dir/.htaccess", "Require all denied\n");
    }
    $db = new PDO('sqlite:' . $dir . '/comments.sqlite', null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA busy_timeout = 5000');
    // Overwrite deleted comments instead of only marking the pages as free
    $db->exec('PRAGMA secure_delete = ON');
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS comments (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            section    TEXT NOT NULL,
            page       TEXT NOT NULL,
            path       TEXT NOT NULL,
            name       TEXT NOT NULL DEFAULT '',
            email      TEXT NOT NULL DEFAULT '',
            website    TEXT NOT NULL DEFAULT '',
            comment    TEXT NOT NULL,
            status     TEXT NOT NULL DEFAULT 'pending',  -- pending | approved
            token      TEXT NOT NULL,                     -- for the moderation links in mails
            ip_hash    TEXT NOT NULL DEFAULT '',          -- rate limiting only
            created_at TEXT NOT NULL,
            parent_id  INTEGER DEFAULT NULL               -- set on replies by the author
        );
        CREATE INDEX IF NOT EXISTS comments_page ON comments (section, page, status);
        CREATE TABLE IF NOT EXISTS login_failures (
            ip_hash    TEXT NOT NULL,
            created_at TEXT NOT NULL
        );
        SQL);
    add_column_if_missing($db, 'parent_id', 'INTEGER DEFAULT NULL');
    return $db;
}

/**
 * Upgrades existing databases (additive only, data stays untouched).
 * If two requests race, one wins and the other ignores the error.
 */
function add_column_if_missing(PDO $db, string $column, string $definition): void
{
    if (in_array($column, array_column($db->query('PRAGMA table_info(comments)')->fetchAll(), 'name'), true)) {
        return;
    }
    try {
        $db->exec("ALTER TABLE comments ADD COLUMN $column $definition");
    } catch (PDOException $e) {
        if (!str_contains($e->getMessage(), 'duplicate column')) {
            throw $e;
        }
    }
}

/** Removes expired IP hashes. Runs whenever comments are fetched or submitted. */
function purge_ip_hashes(): void
{
    db()->prepare("UPDATE comments SET ip_hash = '' WHERE ip_hash != '' AND created_at <= ?")
        ->execute([date('c', time() - RATE_WINDOW)]);
    db()->prepare('DELETE FROM login_failures WHERE created_at <= ?')
        ->execute([date('c', time() - LOGIN_WINDOW)]);
}

/** Deletes a comment and the replies to it. */
function delete_comment(int $id): void
{
    db()->prepare('DELETE FROM comments WHERE id = ? OR parent_id = ?')->execute([$id, $id]);
}

/**
 * Keyed hash of the visitor's IP for rate limiting; the IP itself is never stored.
 * The key is a random secret kept on the server, so someone holding only the
 * database cannot find the IP behind a hash by trying every address.
 */
function ip_hash(): string
{
    $file = config()['data_dir'] . '/salt';
    if (!is_file($file)) {
        db();  // creates the data directory and its .htaccess
        file_put_contents($file, bin2hex(random_bytes(32)), LOCK_EX);
        chmod($file, 0600);
    }
    return hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? '', trim(file_get_contents($file)));
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function display_name(string $name): string
{
    return $name !== '' ? $name : t('anonymous');
}

function site_host(): string
{
    return (string) (parse_url((string) config()['site_url'], PHP_URL_HOST) ?: '');
}

function format_date(string $date): string
{
    return date((string) config()['date_format'], strtotime($date));
}

function is_https(): bool
{
    return ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

/** Validates and normalizes the fields. Returns them, or an error message. */
function clean_fields(array $in): array|string
{
    $name    = preg_replace('/\s+/u', ' ', trim((string) ($in['name'] ?? '')));
    $email   = trim((string) ($in['email'] ?? ''));
    $website = trim((string) ($in['website'] ?? ''));
    $comment = trim(str_replace("\r\n", "\n", (string) ($in['comment'] ?? '')));

    if ($comment === '') {
        return t('error_empty');
    }
    if (mb_strlen($comment) > MAX_COMMENT) {
        return t('error_too_long', ['max' => MAX_COMMENT]);
    }
    if (mb_strlen($name) > MAX_NAME) {
        return t('error_name');
    }
    if ($email !== '' && (mb_strlen($email) > MAX_EMAIL || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
        return t('error_email');
    }
    if ($website !== '') {
        if (!preg_match('#^https?://#i', $website)) {
            $website = 'https://' . $website;
        }
        if (mb_strlen($website) > MAX_WEBSITE || !filter_var($website, FILTER_VALIDATE_URL)) {
            return t('error_website');
        }
    }
    return compact('name', 'email', 'website', 'comment');
}

function send_mail(string $subject, string $body): bool
{
    $c = config();
    if ($c['mail_to'] === '' || $c['mail_from'] === '') {
        return false;
    }
    $headers = implode("\r\n", [
        'From: ' . $c['mail_from'],
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ]);
    $ok = @mail($c['mail_to'], '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
    if (!$ok) {
        error_log('comments: could not send mail: ' . $subject);
    }
    return $ok;
}

/** Renders a page of the moderation interface and ends the request. */
function layout(string $title, string $body, string $flash = '', string $header = ''): never
{
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    $title = h($title);
    $host  = h(site_host());
    $lang  = h((string) config()['language']);
    $flash = $flash !== '' ? '<div class="flash">' . h($flash) . '</div>' : '';
    $extra = (string) config()['admin_css'];
    echo <<<HTML
        <!DOCTYPE html>
        <html lang="$lang"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex"><title>$title · $host</title>
        <style>
          :root { --bg:#fff; --entry:#f4f4f5; --text:#18181b; --muted:#63636b; --border:#d4d4d8;
                  --accent:#1d4ed8; --accent2:#7c3aed; --on-accent:#fff; --green:#15803d; --red:#b91c1c; --yellow:#fbbf24; color-scheme:light dark; }
          @media (prefers-color-scheme:dark) {
            :root { --bg:#18181b; --entry:#27272a; --text:#f4f4f5; --muted:#a1a1aa; --border:#3f3f46;
                    --accent:#93c5fd; --accent2:#c4b5fd; --on-accent:#18181b; --green:#4ade80; --red:#fca5a5; --yellow:#fde047; }
          }
          * { box-sizing:border-box; }
          body { background:var(--bg); color:var(--text); font:15px/1.6 system-ui, sans-serif; max-width:820px; margin:2rem auto; padding:0 1rem; }
          a { color:var(--accent); } a:hover { color:var(--accent2); }
          header { display:flex; justify-content:space-between; align-items:baseline; margin-bottom:1rem; }
          h1 { font-size:1.4rem; margin:0; } h1 small { color:var(--muted); font-weight:normal; font-size:.9rem; }
          .muted { color:var(--muted); }
          .tabs { display:flex; gap:.4rem; margin-bottom:1.2rem; border-bottom:1px solid var(--border); }
          .tabs a { padding:.4rem .9rem; text-decoration:none; color:var(--muted); border-bottom:2px solid transparent; }
          .tabs a.active { color:var(--text); border-color:var(--accent); }
          .tabs span { background:var(--entry); border-radius:99px; padding:0 .5rem; font-size:.8rem; }
          .flash { background:var(--entry); border-left:4px solid var(--green); border-radius:8px; padding:.6rem 1rem; margin-bottom:1rem; }
          .comment { background:var(--entry); border-left:3px solid var(--accent); border-radius:8px; padding:.8rem 1rem; margin-bottom:1rem; }
          .comment.pending { border-color:var(--yellow); }
          .comment.reply { background:var(--bg); border-color:var(--accent2); margin:.8rem 0 0 1rem; }
          .meta { font-size:.9rem; } .meta.muted { font-size:.8rem; margin-bottom:.5rem; }
          .badge { color:#18181b; background:var(--yellow); border-radius:4px; padding:0 .4rem; font-size:.75rem; font-weight:bold; }
          .badge.author { color:var(--on-accent); background:var(--accent2); }
          .text { overflow-wrap:anywhere; }
          .actions { display:flex; gap:.5rem; margin-top:.7rem; }
          form { margin:0; }
          button { background:var(--accent); color:var(--on-accent); border:0; border-radius:8px; padding:.35rem 1rem; font:inherit; font-weight:bold; cursor:pointer; }
          button:hover { background:var(--accent2); }
          button.secondary { background:var(--border); color:var(--text); }
          button.danger { background:var(--red); color:var(--bg); }
          button.link { background:none; color:var(--accent); padding:0; font-weight:normal; }
          details { margin-top:.6rem; } summary { cursor:pointer; color:var(--muted); font-size:.85rem; }
          input, textarea { width:100%; background:var(--bg); color:var(--text); border:1px solid var(--border); border-radius:8px; padding:.45rem .7rem; font:inherit; }
          input:focus, textarea:focus { outline:none; border-color:var(--accent); }
          .edit { display:flex; flex-direction:column; gap:.5rem; margin-top:.5rem; align-items:flex-start; }
          .edit .row { display:grid; grid-template-columns:1fr 1fr 1fr; gap:.5rem; width:100%; }
          @media (max-width:640px) { .edit .row { grid-template-columns:1fr; } }
          .login { display:flex; gap:.5rem; max-width:360px; }
          $extra
        </style></head>
        <body><header><h1>$title <small>$host</small></h1>$header</header>$flash$body</body></html>
        HTML;
    exit;
}
