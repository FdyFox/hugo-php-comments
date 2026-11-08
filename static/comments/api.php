<?php
/*
 * Public endpoint.
 *
 *   GET  ?section=…&page=…                approved comments as JSON
 *   POST section, page, path, name, …     submit a new comment (goes to moderation)
 *   GET  ?action=approve|delete&id&token  confirmation page for links in notification mails
 *   POST action, id, token                carry out the action from the mail
 *
 * Mail links deliberately open a confirmation page first, so that mail scanners
 * following links in advance cannot approve or delete anything.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

const RATE_LIMIT = 3;  // comments per RATE_WINDOW and IP

if (config()['cors']) {
    header('Access-Control-Allow-Origin: *');
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_REQUEST['action'] ?? '';

if ($action === 'approve' || $action === 'delete') {
    moderate_via_mail_link($method, $action);
} elseif ($method === 'GET' && isset($_GET['section'], $_GET['page'])) {
    list_comments((string) $_GET['section'], (string) $_GET['page']);
} elseif ($method === 'POST') {
    submit_comment();
} else {
    header('Location: /');
}
exit;

function list_comments(string $section, string $page): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        purge_ip_hashes();
    } catch (PDOException $e) {
        error_log('comments: ' . $e->getMessage());  // showing comments should still work
    }
    $stmt = db()->prepare(
        "SELECT id, parent_id, name, website, comment, created_at AS date FROM comments
         WHERE section = ? AND page = ? AND status = 'approved' ORDER BY created_at, id"
    );
    $stmt->execute([$section, $page]);
    $rows = $stmt->fetchAll();

    $public  = fn(array $c) => ['name' => display_name($c['name']), 'website' => $c['website'],
                                'comment' => $c['comment'], 'date' => $c['date']];
    $replies = [];
    foreach ($rows as $c) {
        if ($c['parent_id'] !== null) {
            $replies[$c['parent_id']][] = $public($c);
        }
    }
    // Replies hang off their comment: if it is hidden, they are too
    $comments = [];
    foreach ($rows as $c) {
        if ($c['parent_id'] === null) {
            $comments[] = $public($c) + ['replies' => $replies[$c['id']] ?? []];
        }
    }
    echo json_encode(['comments' => $comments], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function submit_comment(): never
{
    $wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    $path      = preg_match('#^/[A-Za-z0-9/_\-]*$#', $_POST['path'] ?? '') ? $_POST['path'] : '/';

    $respond = function (bool $ok, string $message, int $status = 200) use ($wantsJson, $path): never {
        if ($wantsJson) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE);
        } else {
            // Without JavaScript: back to the post, the message is shown with CSS :target
            header('Location: ' . $path . ($ok ? '#comment-thanks' : '#comment-error'), true, 303);
        }
        exit;
    };

    // Honeypot: bots fill in hidden fields, people do not
    if (!empty($_POST['fax'])) {
        $respond(true, t('thanks_short'));
    }

    $section = (string) ($_POST['section'] ?? '');
    $page    = (string) ($_POST['page'] ?? '');
    if (!in_array($section, config()['allowed_sections'], true) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_\-]{0,99}$/', $page)) {
        $respond(false, t('error_unknown_post'), 400);
    }
    $fields = clean_fields($_POST);
    if (is_string($fields)) {
        $respond(false, $fields, 400);
    }

    try {
        $db     = db();
        $ipHash = ip_hash();
        purge_ip_hashes();
        $stmt = $db->prepare('SELECT COUNT(*) FROM comments WHERE ip_hash = ? AND created_at > ?');
        $stmt->execute([$ipHash, date('c', time() - RATE_WINDOW)]);
        if ((int) $stmt->fetchColumn() >= RATE_LIMIT) {
            $respond(false, t('error_rate_limit'), 429);
        }

        $token = bin2hex(random_bytes(16));
        $db->prepare(
            'INSERT INTO comments (section, page, path, name, email, website, comment, token, ip_hash, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$section, $page, $path, $fields['name'], $fields['email'], $fields['website'],
                    $fields['comment'], $token, $ipHash, date('c')]);
        $id = (int) $db->lastInsertId();
    } catch (PDOException $e) {
        error_log('comments: ' . $e->getMessage());
        $respond(false, t('error_save'), 500);
    }

    notify($id, $token, $path, $fields);
    $respond(true, t('thanks'));
}

function notify(int $id, string $token, string $path, array $f): void
{
    $base = rtrim((string) config()['site_url'], '/');
    $dir  = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/comments/api.php'), '/');
    $link = fn(string $action) => "$base$dir/api.php?action=$action&id=$id&token=$token";
    $name = display_name($f['name']);
    $body = t('mail_post') . ': ' . $base . $path . "\n"
          . t('mail_name') . ": $name\n"
          . ($f['email'] !== '' ? t('mail_email') . ": {$f['email']}\n" : '')
          . ($f['website'] !== '' ? t('mail_website') . ": {$f['website']}\n" : '')
          . "\n" . $f['comment'] . "\n\n"
          . t('approve') . ': ' . $link('approve') . "\n"
          . t('delete') . ': ' . $link('delete') . "\n"
          . t('manage') . ": $base$dir/admin.php\n";
    send_mail('[' . site_host() . '] ' . t('mail_subject', ['name' => $name]), $body);
}

function moderate_via_mail_link(string $method, string $action): never
{
    $id    = (int) ($_REQUEST['id'] ?? 0);
    $token = (string) ($_REQUEST['token'] ?? '');
    $stmt  = db()->prepare('SELECT * FROM comments WHERE id = ?');
    $stmt->execute([$id]);
    $c = $stmt->fetch();

    if (!$c || !hash_equals($c['token'], $token) || strtotime($c['created_at']) < time() - TOKEN_TTL) {
        layout(t('not_found'), '<p>' . h(t('link_invalid')) . '</p><p><a href="admin.php">' . h(t('all_comments')) . '</a></p>');
    }

    $post = h(rtrim((string) config()['site_url'], '/') . $c['path']) . '#comments';
    $all  = '<a href="admin.php">' . h(t('all_comments')) . '</a>';

    if ($method === 'POST') {
        if ($action === 'approve') {
            db()->prepare("UPDATE comments SET status = 'approved' WHERE id = ?")->execute([$id]);
            layout(t('approved'), '<p>' . h(t('now_online')) . "</p><p><a href=\"$post\">" . h(t('to_post')) . "</a> · $all</p>");
        }
        delete_comment($id);
        layout(t('deleted'), '<p>' . h(t('comment_deleted')) . "</p><p>$all</p>");
    }

    $label = t($action);
    $note  = $c['status'] === 'approved' && $action === 'approve'
        ? '<p class="muted">' . h(t('already_approved')) . '</p>' : '';
    layout($label . '?', $note
        . '<div class="comment"><div class="meta"><strong>' . h(display_name($c['name'])) . '</strong> · '
        . h(format_date($c['created_at'])) . " · <a href=\"$post\">" . h(t('post')) . '</a></div>'
        . '<div class="text">' . nl2br(h($c['comment'])) . '</div></div>'
        . '<form method="post"><input type="hidden" name="action" value="' . h($action) . '">'
        . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="token" value="' . h($token) . '">'
        . '<button class="' . ($action === 'delete' ? 'danger' : '') . '">' . h($label) . '</button></form>');
}
