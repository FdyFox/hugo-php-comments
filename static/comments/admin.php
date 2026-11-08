<?php
/*
 * Moderation page: log in against the password hash from config.php,
 * then approve, hide, reply to, edit and delete comments.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

const LOGIN_MAX_FAILURES = 5;     // failed attempts per LOGIN_WINDOW and IP
const SESSION_IDLE       = 7200;  // log out after 2 hours of inactivity

session_name('hugo_php_comments_admin');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => is_https()]);
session_start();

if (!empty($_SESSION['admin']) && time() - ($_SESSION['seen'] ?? 0) > SESSION_IDLE) {
    $_SESSION = [];
}
$_SESSION['seen'] = time();

$passwordHash = (string) config()['admin_password_hash'];
$self         = 'admin.php';
$flash        = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

function redirect(string $to, string $flash = ''): never
{
    if ($flash !== '') {
        $_SESSION['flash'] = $flash;
    }
    header('Location: ' . $to, true, 303);
    exit;
}

// --- Login / logout -----------------------------------------------------------

if ($passwordHash === '') {
    layout(t('comments'), '<p class="muted">' . h(t('no_password')) . '</p>');
}

if (($_POST['do'] ?? '') === 'login') {
    $db    = db();
    $ip    = ip_hash();
    $since = date('c', time() - LOGIN_WINDOW);
    $db->prepare('DELETE FROM login_failures WHERE created_at <= ?')->execute([$since]);
    $stmt = $db->prepare('SELECT COUNT(*) FROM login_failures WHERE ip_hash = ?');
    $stmt->execute([$ip]);

    if ((int) $stmt->fetchColumn() >= LOGIN_MAX_FAILURES) {
        $flash = t('too_many_failures', ['minutes' => LOGIN_WINDOW / 60]);
    } elseif (password_verify((string) ($_POST['password'] ?? ''), $passwordHash)) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['csrf']  = bin2hex(random_bytes(16));
        redirect($self);
    } else {
        $db->prepare('INSERT INTO login_failures (ip_hash, created_at) VALUES (?, ?)')->execute([$ip, date('c')]);
        sleep(1);
        $flash = t('wrong_password');
    }
}

if (empty($_SESSION['admin'])) {
    layout(t('log_in'), '<form method="post" class="login">'
        . '<input type="hidden" name="do" value="login">'
        . '<input type="password" name="password" placeholder="' . h(t('password')) . '" autofocus required autocomplete="current-password">'
        . '<button>' . h(t('log_in')) . '</button></form>', $flash);
}

// --- Actions ------------------------------------------------------------------

$view = in_array($_GET['status'] ?? '', ['pending', 'approved', 'all'], true) ? $_GET['status'] : 'pending';
$back = "$self?status=$view";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        redirect($back, t('session_expired'));
    }
    $do = (string) ($_POST['do'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $db = db();

    switch ($do) {
        case 'logout':
            $_SESSION = [];
            session_destroy();
            redirect($self);
        case 'approve':
            $db->prepare("UPDATE comments SET status = 'approved' WHERE id = ?")->execute([$id]);
            redirect($back, t('flash_approved'));
        case 'unapprove':
            $db->prepare("UPDATE comments SET status = 'pending' WHERE id = ?")->execute([$id]);
            redirect($back, t('flash_hidden'));
        case 'delete':
            delete_comment($id);
            redirect($back, t('flash_deleted'));
        case 'reply':
            $stmt = $db->prepare('SELECT * FROM comments WHERE id = ? AND parent_id IS NULL');
            $stmt->execute([$id]);
            $parent = $stmt->fetch();
            if (!$parent) {
                redirect($back, t('comment_gone'));
            }
            $fields = clean_fields(['name' => config()['author_name'], 'comment' => $_POST['comment'] ?? '']);
            if (is_string($fields)) {
                redirect($back . "#c$id", $fields);
            }
            // Replying to a pending comment approves it as well
            $db->beginTransaction();
            $db->prepare("UPDATE comments SET status = 'approved' WHERE id = ?")->execute([$id]);
            $db->prepare(
                "INSERT INTO comments (section, page, path, name, comment, status, token, created_at, parent_id)
                 VALUES (?, ?, ?, ?, ?, 'approved', ?, ?, ?)"
            )->execute([$parent['section'], $parent['page'], $parent['path'], $fields['name'], $fields['comment'],
                        bin2hex(random_bytes(16)), date('c'), $id]);
            $db->commit();
            redirect($back . "#c$id", t('flash_replied'));
        case 'save':
            $fields = clean_fields($_POST);
            if (is_string($fields)) {
                redirect($back, $fields);
            }
            $db->prepare('UPDATE comments SET name = ?, email = ?, website = ?, comment = ? WHERE id = ?')
               ->execute([$fields['name'], $fields['email'], $fields['website'], $fields['comment'], $id]);
            redirect($back . "#c$id", t('flash_saved'));
    }
    redirect($back);
}

// --- List ---------------------------------------------------------------------

$db     = db();
$counts = $db->query('SELECT status, COUNT(*) AS n FROM comments WHERE parent_id IS NULL GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$counts += ['pending' => 0, 'approved' => 0];
$counts['all'] = $counts['pending'] + $counts['approved'];

$sql  = 'SELECT * FROM comments WHERE parent_id IS NULL' . ($view === 'all' ? '' : ' AND status = :status') . ' ORDER BY created_at DESC, id DESC';
$stmt = $db->prepare($sql);
$stmt->execute($view === 'all' ? [] : ['status' => $view]);
$comments = $stmt->fetchAll();

$replies = [];
foreach ($db->query('SELECT * FROM comments WHERE parent_id IS NOT NULL ORDER BY created_at, id') as $reply) {
    $replies[$reply['parent_id']][] = $reply;
}

$csrf = h($_SESSION['csrf']);
$tabs = '';
foreach (['pending' => t('tab_pending'), 'approved' => t('tab_approved'), 'all' => t('tab_all')] as $key => $label) {
    $tabs .= '<a href="' . "$self?status=$key" . '"' . ($key === $view ? ' class="active"' : '') . '>' . h($label) . " <span>{$counts[$key]}</span></a>";
}

$hidden = fn(string $do, int $id) =>
    "<input type=\"hidden\" name=\"csrf\" value=\"$csrf\"><input type=\"hidden\" name=\"do\" value=\"$do\">"
    . "<input type=\"hidden\" name=\"id\" value=\"$id\">";

$button = fn(string $do, int $id, string $label, string $class = '', string $confirm = '') =>
    '<form method="post"' . ($confirm ? ' onsubmit="return confirm(' . h(json_encode($confirm, JSON_UNESCAPED_UNICODE)) . ')"' : '') . '>'
    . $hidden($do, $id) . "<button class=\"$class\">" . h($label) . '</button></form>';

// Renders one comment; $repliesHtml is null when $c is itself a reply
$card = function (array $c, ?string $repliesHtml = null) use ($hidden, $button): string {
    $id      = (int) $c['id'];
    $isReply = $repliesHtml === null;
    $pending = $c['status'] === 'pending';
    $post    = h(rtrim((string) config()['site_url'], '/') . $c['path']) . '#comments';
    $who     = '<strong>' . h(display_name($c['name'])) . '</strong>'
             . ($c['email'] !== '' ? ' · <a href="mailto:' . h($c['email']) . '">' . h($c['email']) . '</a>' : '')
             . ($c['website'] !== '' ? ' · <a href="' . h($c['website']) . '" rel="noopener noreferrer" target="_blank">' . h(preg_replace('#^https?://#', '', $c['website'])) . '</a>' : '');

    return "<article id=\"c$id\" class=\"comment" . ($pending ? ' pending' : '') . ($isReply ? ' reply' : '') . '">'
        . '<div class="meta">' . $who . ($isReply ? ' · <span class="badge author">' . h(t('badge_reply')) . '</span>' : '') . '</div>'
        . '<div class="meta muted">' . h(format_date($c['created_at']))
        . ($isReply ? '' : " · <a href=\"$post\" target=\"_blank\">" . h($c['section'] . '/' . $c['page']) . '</a>')
        . ($pending ? ' · <span class="badge">' . h(t('badge_pending')) . '</span>' : '') . '</div>'
        . '<div class="text">' . nl2br(h($c['comment'])) . '</div>'
        . '<div class="actions">'
        . ($isReply ? '' : ($pending ? $button('approve', $id, t('approve')) : $button('unapprove', $id, t('hide'), 'secondary')))
        . $button('delete', $id, t('delete'), 'danger', $isReply ? t('confirm_delete_reply') : t('confirm_delete'))
        . '</div>'
        . ($isReply ? '' : '<details><summary>' . h($pending ? t('approve_and_reply') : t('reply')) . '</summary><form method="post" class="edit">'
            . $hidden('reply', $id)
            . '<textarea name="comment" rows="4" required></textarea>'
            . '<button>' . h(t('publish_reply')) . '</button></form></details>')
        . '<details><summary>' . h(t('edit')) . '</summary><form method="post" class="edit">'
        . $hidden('save', $id)
        . '<div class="row"><input name="name" placeholder="' . h(t('name')) . '" value="' . h($c['name']) . '">'
        . '<input name="email" placeholder="' . h(t('email')) . '" value="' . h($c['email']) . '">'
        . '<input name="website" placeholder="' . h(t('website')) . '" value="' . h($c['website']) . '"></div>'
        . '<textarea name="comment" rows="5" required>' . h($c['comment']) . '</textarea>'
        . '<button>' . h(t('save')) . '</button></form></details>'
        . ($repliesHtml ?? '')
        . '</article>';
};

$list = '';
foreach ($comments as $c) {
    $list .= $card($c, implode('', array_map(fn($r) => $card($r), $replies[$c['id']] ?? [])));
}
if ($list === '') {
    $list = '<p class="muted">' . h($view === 'pending' ? t('nothing_to_moderate') : t('no_comments')) . '</p>';
}

$logout = "<form method=\"post\"><input type=\"hidden\" name=\"csrf\" value=\"$csrf\">"
        . '<input type="hidden" name="do" value="logout"><button class="link">' . h(t('log_out')) . '</button></form>';

layout(t('comments'), "<nav class=\"tabs\">$tabs</nav>$list", $flash, $logout);
