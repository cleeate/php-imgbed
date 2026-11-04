<?php
// Copy this file to s3-config.php and fill in real values.
// s3-config.php is gitignored and must never be committed.
// Accessed directly it outputs nothing (it only returns an array),
// and .htaccess also denies it, but keep it out of git anyway.
return [
    // Required: the key pair your S3 clients (e.g. Rin) will use.
    // Generate a long random pair; do not reuse a password you use elsewhere.
    'access_key' => 'TODO_REPLACE_ACCESS_KEY',
    'secret_key' => 'TODO_REPLACE_SECRET_KEY',

    // Optional: maximum size of a single PUT, in bytes (default 10 MB).
    // Free shared hosts often cap uploads lower than this; check your host.
    'max_bytes' => 10 * 1024 * 1024,

    // Optional: maximum total size of a multipart upload (default 100 MB).
    'multipart_max_bytes' => 100 * 1024 * 1024,

    // Optional: extensions a PUT may create. Default is images + video only.
    // This is a security control: allowing .php/.html uploads on a public
    // host lets anyone overwrite code or serve phishing pages from your domain.
    // Widen it only if you understand that risk.
    'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif', 'mp4', 'webm', 'mov'],

    // Optional: allowed bucket names. Empty (default) accepts any bucket name
    // and stores by key only, which is what Rin needs. Set a list to lock it down.
    'buckets' => [],

    // Optional: CORS origin for browser-based S3 clients (default '*').
    'cors_origin' => '*',
];
