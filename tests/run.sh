#!/bin/bash
# Builds the test site and runs the frontend and backend tests. Needs Docker, Hugo and Python 3.
set -u
ROOT=$(cd "$(dirname "$0")/.." && pwd)
WORK=$ROOT/tests/.work
HUGO=${HUGO:-hugo}
USER_ARGS=(--user "$(id -u):$(id -g)")
PHP_IMAGE=php:8.5-cli
PW='p"a$s`w\ör'"'"'d #1'
free_port() { python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1])'; }
wait_for() { for _ in $(seq 40); do curl -s -o /dev/null "http://127.0.0.1:$1/" && return; python3 -c 'import time;time.sleep(0.25)'; done; }
cleanup() { docker stop hpc-test hpc-guard >/dev/null 2>&1; }
trap cleanup EXIT
cleanup

status=0
chmod -R u+w "$WORK" 2>/dev/null
rm -rf "$WORK"
mkdir -p "$WORK/data" "$WORK/guard"

echo "== build"
"$HUGO" --quiet --source "$ROOT/tests/site" --destination "$WORK/public" || exit 1
PAGE=$WORK/public/posts/hello/index.html
grep -q 'id="comments"\|id=comments' "$PAGE" && echo "ok   form rendered" || { echo "FAIL form rendered"; status=1; }
grep -q 'privacy' "$PAGE" && echo "ok   privacy link rendered" || { echo "FAIL privacy link"; status=1; }

echo "== frontend"
docker run --rm "${USER_ARGS[@]}" -v "$ROOT/tests:/t:ro" node:22-alpine node /t/frontend.js /t/.work/public/posts/hello/index.html || status=1

echo "== password hash"
HASH=$(COMMENTS_ADMIN_PASSWORD="$PW" "$ROOT/scripts/hash-password.sh") || { echo "FAIL hash"; exit 1; }
cat > "$WORK/data/config.php" <<EOF
<?php
return ['admin_password_hash' => '$HASH', 'author_name' => 'Tester', 'site_url' => 'https://example.org',
        'mail_to' => 'a@example.org', 'mail_from' => 'b@example.org', 'data_dir' => '/data'];
EOF

echo "== backend"
docker run --rm "${USER_ARGS[@]}" -v "$ROOT/tests:/t:ro" -v "$WORK/data:/data" $PHP_IMAGE php /t/old-schema.php /data/comments.sqlite
P1=$(free_port)
docker run -d --rm --name hpc-test "${USER_ARGS[@]}" -e PHP_CLI_SERVER_WORKERS=4 -p "127.0.0.1:$P1:8000" \
  -v "$ROOT/static:/app:ro" -v "$WORK/data:/data" -e COMMENTS_CONFIG=/data/config.php -w /app $PHP_IMAGE php -S 0.0.0.0:8000 >/dev/null
wait_for "$P1"

# Second server: data directory inside the web root, and a server that ignores .htaccess
cp -r "$ROOT/static/comments" "$WORK/guard/comments"
cat > "$WORK/guard/config.php" <<'EOF'
<?php
return ['data_dir' => '/app/comments/data'];
EOF
P2=$(free_port)
docker run -d --rm --name hpc-guard "${USER_ARGS[@]}" -p "127.0.0.1:$P2:8000" \
  -v "$WORK/guard:/app" -e COMMENTS_CONFIG=/app/config.php -w /app $PHP_IMAGE php -S 0.0.0.0:8000 >/dev/null
wait_for "$P2"

python3 "$ROOT/tests/backend.py" "http://127.0.0.1:$P1" "$WORK/data/comments.sqlite" "$PW" "http://127.0.0.1:$P2" || status=1

ERRORS=$(docker logs hpc-test 2>&1 | grep -i -E "fatal|uncaught|deprecated|php warning|database is locked" | grep -v "could not send mail")
if [[ -n $ERRORS ]]; then echo "FAIL PHP errors:"; echo "$ERRORS" | sort | uniq -c | head; status=1; else echo "ok   no PHP errors in the log"; fi

[[ $status == 0 ]] && echo "ALL PASSED" || echo "FAILURES"
exit $status
