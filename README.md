# php-s3-lite

A single-file, S3-compatible object storage endpoint for plain PHP hosting.
No framework, no Composer, no database, no SimpleXML extension required.
Drop three files on a PHP host and
you have an S3 endpoint that Rin, aws-cli, and other S3 clients can talk to.

> 🇨🇳 中文用户：核心用法见下文；`docs/` 里有 Rin 集成、Nginx、aws-cli 的详细说明。

## Why this exists

Cloudflare R2 is great, but not everyone wants (or can use) R2 for a personal
blog. This project started as the storage backend for a
[Rin](https://github.com/openRin/Rin) blog: Rin uploads images with an S3
client (aws4fetch), and this endpoint lets a cheap PHP host play the role of
that S3 bucket — no server, no Docker, no MinIO.

It is intentionally boring: one PHP file, one config file, one `.htaccess`.

## What it supports

| Operation | Notes |
|---|---|
| PutObject / GetObject / HeadObject / DeleteObject | Path-style `/{bucket}/{key}`, SigV4 header auth |
| ListObjectsV2 (and V1 `marker`) | `GET /{bucket}?list-type=2&prefix=…` |
| CopyObject | `PUT` with `x-amz-copy-source` |
| Multipart upload | Create / UploadPart / Complete / Abort / ListParts |
| Presigned URLs | `signQuery` (GET, etc.), expiry enforced |
| CORS | Preflight `OPTIONS` + `Access-Control-Allow-Origin` (configurable) |
| Public read | Objects are stored under the web root at `/{key}`, so `https://your-host/{key}` works as a plain static file — no S3 GET needed to view an image |

Not supported (by design, to stay small): versioning, ACLs, bucket policies,
server-side encryption, object locking, lifecycle rules, `ListBuckets`.

## Known host limitation: ListBucket on iFastNet free hosting

`ListObjectsV2` works locally and in principle, but on iFastNet/VistaPanel
free hosting, a `GET /{bucket}?list-type=2` request (no file extension,
looks like a page) is intercepted by the host's JavaScript browser
verification (`aes.js`, sets a cookie and redirects with `?i=1`) and returns
challenge HTML with status 200 instead of XML. Object PUT/GET/HEAD/DELETE
for paths ending in e.g. `.png`/`.jpg` are not intercepted, so Rin upload
and public image URLs work fine on that host. `ListObjectsV2` is not used
by Rin. If you need List from a non-browser S3 client on such a host, use a
host without that verification (e.g. a VPS) instead.

## Known host limitation: video and other non-image files on iFastNet

On the same host, only a whitelist of extensions reaches PHP from a
non-browser client (observed passing: `jpg`, `jpeg`, `png`, `gif`, `svg`,
`bmp`, `css`; observed challenged: `mp4`, `webm`, `mov`, `mp3`, `webp`,
`avif`, `pdf`, `zip`, `txt`, `js`, `html`, `json`). A PUT or GET for
`.mp4` returns the browser-verification HTML with status 200, so an S3
client that only checks the status code can wrongly report the upload as
successful while nothing is stored, and a `<video>` tag cannot pass the
JavaScript verification either. Verified 2026-11-05: an `.mp4` uploaded
from Rin never appeared in `images/` (only the two earlier `.jpg` files
were there), and its public URL returns the verification page to a
non-browser client and `SignatureDoesNotMatch` XML to a browser that
already holds the host cookie. In short, this host is usable for Rin
images, but not for video; use real R2 or a VPS for video.

## Quick start (Apache, e.g. iFastNet / VistaPanel free hosting)

1. Copy these files to your web root (`/htdocs`):
   - `s3.php`
   - `.htaccess` (merge into an existing one if you already have one)
   - `index.php` (optional, just a notice page)
2. Copy `s3-config.example.php` to `s3-config.php` and fill in a long random
   `access_key` / `secret_key`. **Never commit `s3-config.php`** — it is
   gitignored, and `.htaccess` also denies it over the web.
3. Test with curl (replace the host and key):

```bash
# A public read needs no signing:
curl -I https://your-host.example/images/hello.jpg
```

4. Configure your S3 client (see `docs/rin.md` for Rin, `docs/aws-cli.md`
   for aws-cli). The bucket name is accepted (and optionally locked down in
   config) but objects are stored by key only, so the public URL has no bucket
   in it.

## Rin configuration

Blog domain and storage domain are separate. If your blog is at
`https://blog.example.com` and this endpoint is at
`https://storage.example.com`, set in Rin (Secrets vs Variables as shown):

```
# Variables
S3_ENDPOINT = https://storage.example.com
S3_ACCESS_HOST = https://storage.example.com
S3_BUCKET = rin
S3_REGION = auto
S3_FORCE_PATH_STYLE = true
S3_FOLDER = images/

# Secrets (encrypted)
S3_ACCESS_KEY_ID = <your access_key>
S3_SECRET_ACCESS_KEY = <your secret_key>
```

Then redeploy Rin. Uploading an image in the Rin editor should now store it
at `https://storage.example.com/images/<sha1>.<ext>` and insert that link
into the article. See `docs/rin.md` for the full walkthrough.

## Security notes (read before opening this to the internet)

- **Reserved names are blocked for every S3 operation**: `s3.php`,
  `s3-config.php`, `.htaccess`, `index.php`, anything under `.multipart/`,
  and any `*.php` file. Without this, anyone with the S3 key could overwrite
  the endpoint itself or read the config file through the S3 API. The
  `.htaccess` deny for `s3-config.php` alone is *not* enough, because an S3
  GET goes through `s3.php`, not through static file serving.
- **PUT is limited to image/video extensions by default**
  (`config['allowed_extensions']`). Widening it to `.html`/`.js` lets anyone
  with the key serve pages from your domain.
- **The S3 key is a full write key.** Treat it like a password, rotate it if
  it leaks, and do not put it in a public repo, a screenshot, or a blog post.
- This endpoint does not rate-limit. On a shared host, put it behind
  Cloudflare or your host's own limits if you expect abuse.

## Two quirks this project exists to handle

1. **aws4fetch + `UNSIGNED-PAYLOAD`.** Rin uses aws4fetch, which signs S3
   requests with `X-Amz-Content-Sha256` set to the literal string
   `UNSIGNED-PAYLOAD`, not the body hash. An endpoint that rejects that
   literal (or that insists the body hash match it) returns 403 for every
   Rin upload, while a hand-rolled test with a real body hash passes. This
   endpoint accepts `UNSIGNED-PAYLOAD` as a first-class payload hash.
2. **HEAD turned into GET by a front proxy.** Some hosts' front proxies
   convert an incoming HEAD into a GET upstream, so PHP sees GET while the
   client signed HEAD. For the read-only HEAD/GET pair, either signature is
   accepted; PUT/DELETE are unaffected.

## Local development & tests

```bash
cp s3-config.example.php s3-config.php   # fill in TEST keys
php -S 127.0.0.1:8765 tests/router.php
npm i aws4fetch
node tests/test-aws4fetch.mjs
```

The test covers PUT/GET/HEAD/DELETE, public read, reserved-file blocking,
ListObjectsV2, CopyObject, multipart upload, and presigned GET, all with the
same client library Rin uses.

## Project layout

```
s3.php                 # the endpoint (the only file that matters at runtime)
s3-config.example.php  # copy to s3-config.php, never commit the real one
.htaccess              # Apache rewrite + deny rules
index.php              # 上传面板 + 对接帮助（浏览器内 SigV4 直传）
tests/                 # router for php -S + aws4fetch end-to-end test
docs/                  # Rin, aws-cli, Nginx guides
```

## License

MIT — see `LICENSE`. If you use this in production, a link back is welcome
but not required.
