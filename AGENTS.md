# Notes for AI coding agents

See [README.md](README.md) for what this component does and how it is set up.

## Testing

Run `tests/run.sh` before every commit. It needs Docker, Hugo and Python 3, and must end with `ALL PASSED`.

The tests run on a current PHP image, so they do not prove compatibility with PHP 8.1. Check by hand that new code avoids newer language features and functions.

## Constraints

- PHP 8.1 and Hugo 0.120 are the minimum versions.
- No dependencies and no build step. The backend stays plain PHP on webspace, with `pdo_sqlite` and `mbstring` as the only extensions.
- No cookies for readers and no requests to other servers. The session cookie in `admin.php` is for the admin only.
- Comments are plain text. Use `textContent` in the frontend and the escaping helper in `lib.php` in the backend, never raw HTML.
- Store IP addresses only as a keyed hash, and keep the removal after about 10 minutes.
- Do not weaken the check that refuses to run when the data directory is unprotected.
- The notification mail links must keep their confirmation page, so mail scanners cannot approve or delete anything.

## Changes that touch several places

- User-facing text: add it in English and German, in `data/hugo_php_comments/` for the form and `static/comments/lang/` for the backend.
- Database schema: existing installations must upgrade automatically, using the column upgrade in `lib.php`. `tests/old-schema.php` creates an older database to test this, so do not change it to match the new schema.
- New settings: add them to `config.example.php` and document them in the README if users need to know about them.
