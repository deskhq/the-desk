<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Message Attachments
    |--------------------------------------------------------------------------
    |
    | Files uploaded to messages (drag-and-drop, paste, or picker). Uploads are
    | two-phase: a file is stored immediately as a "pending" attachment, then
    | claimed by the message that sends it. These limits are enforced at upload
    | time and on send. Note that raising `max_size_mb` also requires raising the
    | server's PHP `upload_max_filesize` / `post_max_size` and any reverse-proxy
    | body-size limit to match — Laravel validation runs only after the file has
    | already been received.
    |
    */

    'max_size_mb' => (int) env('ATTACHMENT_MAX_SIZE_MB', 25),

    'max_per_message' => (int) env('ATTACHMENT_MAX_PER_MESSAGE', 10),

    /*
    | How long a pending (never-claimed) attachment lives before the hourly GC
    | sweep deletes its row and blob. A draft that is never sent, or an upload
    | whose send never arrives, is reclaimed after this window.
    */
    'pending_ttl_hours' => (int) env('ATTACHMENT_PENDING_TTL_HOURS', 24),

    /*
    | The filesystem disk attachments live on. Private by default (not the
    | `public` disk custom emoji uses) because message attachments belong to
    | private channels — a guessable, auth-free URL would leak them. Files are
    | served through an authorized route, never a filesystem URL, so pointing
    | this at S3 later is a config flip.
    */
    'disk' => env('ATTACHMENT_DISK', 'local'),

    /*
    | File extensions rejected regardless of the "any type" default, because they
    | are executable or interpreted by a web server. Files are stored outside the
    | webroot so nothing is executable anyway, but the denylist is defence in
    | depth. Matched case-insensitively against the client extension.
    */
    'executable_denylist' => [
        'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht',
        'phps', 'htaccess', 'htpasswd', 'cgi', 'pl', 'py', 'sh', 'bash', 'exe',
        'bat', 'cmd', 'com', 'msi', 'jar', 'js', 'mjs', 'cjs', 'jsp', 'asp',
        'aspx', 'dll', 'so',
    ],

];
