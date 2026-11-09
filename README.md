# hugo-php-comments

Self-hosted comments for [Hugo](https://gohugo.io) sites on plain PHP webspace.

PHP and one SQLite file, on the webspace you already pay for. No account for your readers, no third party loading anything on page view, no database server, no mail credentials. Every comment waits for your approval.

About 700 lines of PHP you can read in one sitting.

> [!NOTE]
> This component was developed with the help of AI, using [Claude Code](https://claude.com/claude-code).

## Features

- Name, email and website are optional; only the comment is required
- Every comment is moderated before it appears, from a small admin page or from links in the notification mail
- Replies as the author, shown nested below a comment
- Notifications through PHP's `mail()`, so no SMTP account or mail service
- Submitting works without JavaScript; showing comments needs it
- New comments appear immediately after approval, without rebuilding the site
- Plain text only, comments are never rendered as HTML
- English and German included

### Protection

- Honeypot field and a limit of 3 comments per 10 minutes and IP
- IP addresses are only kept as a keyed hash and removed on the next comment fetch after 10 minutes
- Admin password stored only as a hash, lockout after 5 failed logins, CSRF tokens, session timeout
- Mail links open a confirmation page first and expire after 30 days, so mail scanners cannot approve or delete anything

### Limits

No threads between readers, no Markdown, no Webmentions and no 2FA. If you need those, have a look at [Pure Comments](https://github.com/kevquirk/purecomments). Spam protection is deliberately simple, because a human reviews everything anyway.

Notifications depend on your host allowing `mail()`. Comments are still saved and shown in the admin page if mail fails.

## Requirements

- Hugo 0.120 or newer
- PHP 8.1 or newer with `pdo_sqlite` and `mbstring`
- Apache or LiteSpeed with `.htaccess` enabled, or any other server with the data directory outside the web root

## Installation

### 1. Add the component

```bash
git submodule add https://github.com/FdyFox/hugo-php-comments themes/hugo-php-comments
```

List it before your theme in `hugo.toml`:

```toml
theme = ["hugo-php-comments", "your-theme"]

[params]
  commentsLanguage = "en"                # optional, defaults to the page language
  commentsPrivacyUrl = "/privacy/"       # optional, linked below the form
```

### 2. Show it on your posts

Call the partial in your single page template, or in the comments hook of your theme:

```go-html-template
{{ partial "hugo-php-comments.html" . }}
```

### 3. Configure the backend

Create the password hash:

```bash
COMMENTS_ADMIN_PASSWORD='a long password' themes/hugo-php-comments/scripts/hash-password.sh
```

Copy [`config.example.php`](config.example.php), fill it in and make sure it ends up at `comments/config.php` on your server. Do not commit it. You can either upload it once or generate it in your deployment.

### 4. Exclude the data directory from deployment

> [!CAUTION]
> The database lives on the server, not in your repository. A deployment that deletes unknown files will **delete all comments** unless the data directory is excluded. The same goes for `config.php` if you upload it by hand.

With `lftp`:

```bash
lftp -c "open ftp://user:password@host; mirror --reverse --delete --exclude ^comments/data/ --exclude ^comments/config.php$ public/ /"
```

With `rsync`: `--delete --exclude /comments/data/ --exclude /comments/config.php`

The moderation page is then at `/comments/admin.php`.

## Protecting the database

By default the database is in `comments/data/`, inside the web root, and protected by `.htaccess`. That only works on Apache and LiteSpeed. On other servers, such as nginx, the file would be downloadable, so the backend **refuses to run** in that case.

Set `data_dir` in `config.php` to a directory outside the web root. If your server blocks the directory some other way, set `'data_dir_protected' => true`.

After deploying, check that `https://your-site/comments/data/comments.sqlite` does not return the file.

## Backups

Nothing backs up your comments for you. Download the data directory from time to time.

## Styling

Override the variables on `.hpc` to match your theme:

```css
.hpc {
  --hpc-surface: var(--entry);
  --hpc-accent: var(--accent);
}
```

Set `commentsNoCss = true` under `[params]` to skip the bundled stylesheet. The admin page takes its own overrides through `admin_css` in `config.php`.

## Translations

Add `data/hugo_php_comments/<lang>.toml` to your site for the form, and `static/comments/lang/<lang>.php` for the backend, then set `commentsLanguage` and `language` in `config.php`. Missing backend strings fall back to English.

## Privacy

The component stores what a commenter enters and the time of the comment, and a keyed hash of the IP address for about 10 minutes. It sets no cookies for readers and loads nothing from other servers. Your privacy policy should still mention it; what you need to say there depends on where you are.

## Development

```bash
tests/run.sh
```

Needs Docker, Hugo and Python 3. It builds a small test site and runs the backend and frontend tests against it, including an upgrade from an older database.

## License

[MIT](LICENSE)
