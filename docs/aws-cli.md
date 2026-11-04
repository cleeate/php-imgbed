# aws-cli and other S3 clients

php-s3-lite speaks path-style SigV4, so most S3 clients work if you point
them at your host and force path style.

## aws-cli

```bash
export AWS_ACCESS_KEY_ID=<your access_key>
export AWS_SECRET_ACCESS_KEY=<your secret_key>

# Upload (note: --endpoint-url, and path style is the default for a
# custom endpoint in recent aws-cli versions; add --no-verify-ssl only
# if your host has a certificate problem, which you should fix instead)
aws --endpoint-url https://storage.example.com s3 cp photo.jpg s3://rin/images/photo.jpg

# List
aws --endpoint-url https://storage.example.com s3 ls s3://rin/images/ --recursive

# Download via the S3 API (signed)
aws --endpoint-url https://storage.example.com s3 cp s3://rin/images/photo.jpg ./photo.jpg

# Public download (no signing needed, static file)
curl https://storage.example.com/images/photo.jpg -o photo.jpg
```

If aws-cli signs with a real body hash instead of `UNSIGNED-PAYLOAD`, that
also works: php-s3-lite accepts both.

## S3 browser / GUI clients

Point the client at `https://storage.example.com`, set the region to `auto`
or `us-east-1` (either is accepted), enable "path style" / "use path-style
addressing", and use the key pair from `s3-config.php`. If a client offers a
"signature version" setting, choose SigV4.

## Limits to be aware of

- Single PUTs are capped by `max_bytes` in config (default 10 MB). Use
  multipart upload for larger files (up to `multipart_max_bytes`, default
  100 MB), if your client supports it.
- PUT extensions are limited by `allowed_extensions` in config (default
  images + video). A client uploading e.g. `.pdf` will get a 400 until you
  add `pdf` to that list.
