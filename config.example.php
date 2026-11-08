<?php
// Copy to static/comments/config.php in your deployed site, or generate it during deployment.
// Never commit the real file. Create the hash with scripts/hash-password.sh.
return [
    'admin_password_hash' => '',                        // required for the moderation page
    'author_name'         => 'Your Name',               // shown on your replies
    'site_url'            => 'https://example.org',     // no trailing slash needed
    'mail_to'             => 'you@example.org',         // receives the notifications
    'mail_from'           => 'noreply@example.org',     // should be on your domain
    'allowed_sections'    => ['posts'],                 // Hugo sections that may receive comments

    // Optional:
    // 'language'           => 'en',                    // or 'de'
    // 'timezone'           => 'Europe/Berlin',
    // 'date_format'        => 'd.m.Y, H:i',
    // 'data_dir'           => '/path/outside/webroot', // recommended; required on nginx
    // 'data_dir_protected' => true,                    // only if your server blocks data_dir itself
    // 'admin_css'          => ':root { --accent: #c2410c; }',
];
