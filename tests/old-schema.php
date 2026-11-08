<?php
// Creates a database with the schema from before replies existed.
$db = new PDO('sqlite:' . $argv[1]);
$db->exec("CREATE TABLE comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT, section TEXT NOT NULL, page TEXT NOT NULL, path TEXT NOT NULL,
    name TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '', website TEXT NOT NULL DEFAULT '',
    comment TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending', token TEXT NOT NULL,
    ip_hash TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL)");
$old = date('c', time() - 3600);
$new = date('c');
$s = $db->prepare("INSERT INTO comments (section,page,path,name,email,website,comment,status,token,ip_hash,created_at)
                   VALUES ('posts','hello','/posts/hello/',?,?,?,?,?,?,?,?)");
$s->execute(['Open', '', '', 'Still open', 'pending', str_repeat('b', 32), 'stale', $old]);
$s->execute(['Free', 'a@example.org', 'https://example.org', "Text\n\nwith paragraph", 'approved', str_repeat('a', 32), 'stale', $old]);
$s->execute(['Recent', '', '', 'Just now', 'approved', str_repeat('c', 32), 'recent', $new]);
