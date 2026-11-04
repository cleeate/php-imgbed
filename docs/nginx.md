# Nginx (instead of Apache)

The shipped `.htaccess` is for Apache. On Nginx, put this in your server
block (adjust the root to your web root):

```nginx
# Pass the Authorization header through (Nginx does this by default for
# proxy/fastcgi, but be explicit if you use a custom setup).
# Route /{bucket}/{key} to s3.php only when the file does not exist,
# so existing static files are still served directly.
location / {
    try_files $uri $uri/ @s3;
}

location @s3 {
    # Rewrite /{bucket}/{key} -> /s3.php?bucket=$1&key=$2
    rewrite ^/([^/]+)/(.+)$ /s3.php?bucket=$1&key=$2 last;
}

# Never serve the credentials file directly.
location = /s3-config.php {
    deny all;
}
location ^~ /.multipart/ {
    deny all;
}
```

Notes:

- `try_files` must come first: a public URL like `/images/photo.jpg`
  exists as a static file and must not be rewritten to `s3.php`.
- If you use PHP-FPM, make sure the `@s3` rewrite lands in a location that
  actually executes PHP (typically by falling through to your existing
  `location ~ \.php$` block).
- Nginx does not read `.htaccess`, so do not rely on it here; the deny
  rules above are the Nginx equivalent.
