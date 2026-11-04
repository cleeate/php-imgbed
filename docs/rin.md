# Rin integration (openRin/Rin)

Rin is a Cloudflare Pages + Workers + D1 blog. Its image upload path is:

1. Editor: drag / paste an image → `POST /storage` on the Rin backend.
2. Backend (`server/src/services/storage.ts`): hashes the file (SHA-1) and
   calls `putStorageObject(env, hashkey, file, file.type)`.
3. Storage (`server/src/utils/storage.ts` + `s3.ts`): builds
   `storageKey = S3_FOLDER + hashkey` (default `images/<sha1>.<ext>`) and,
   when no R2 binding is configured, PUTs it with aws4fetch to
   `S3_ENDPOINT + "/" + S3_BUCKET + "/" + storageKey` (path-style, when
   `S3_FORCE_PATH_STYLE=true`).
4. Public URL: if `S3_ACCESS_HOST` is set, Rin returns
   `S3_ACCESS_HOST + "/" + storageKey` — **no bucket in the public URL**.

That last point is why php-s3-lite stores by key only: the API path has a
bucket segment, the public URL does not, and both must resolve to the same
file. php-s3-lite strips the first path segment as the bucket and stores the
rest under the web root.

## Configuration

Set these in Rin. How you set them depends on how you deploy Rin:

- **GitHub Actions deploy**: `S3_ACCESS_KEY_ID` / `S3_SECRET_ACCESS_KEY`
  go in Repository Secrets; the rest go in Repository Variables.
- **Cloudflare dashboard / wrangler**: the keys go in Worker Secrets, the
  rest in Worker Variables. Redeploy the Worker after changing them.

```
S3_ENDPOINT = https://storage.example.com
S3_ACCESS_HOST = https://storage.example.com
S3_BUCKET = rin
S3_REGION = auto
S3_FORCE_PATH_STYLE = true
S3_FOLDER = images/
S3_ACCESS_KEY_ID = <access_key from s3-config.php>
S3_SECRET_ACCESS_KEY = <secret_key from s3-config.php>
```

Notes:

- `S3_REGION` is accepted but not actually used by Rin's aws4fetch client:
  it guesses the region from the hostname and falls back to `us-east-1`
  for a non-Cloudflare, non-AWS host. php-s3-lite accepts any region in the
  credential scope, so this does not need to match anything.
- `S3_FOLDER` must end with `/` (default `images/`).
- Do **not** set an R2 binding at the same time: Rin's `resolveStorageTarget`
  prefers R2 when `R2_BUCKET` is set, and then the S3 settings are ignored.
- After changing the settings, redeploy Rin and upload a test image in the
  editor. If it fails with 403, check first that the keys match
  `s3-config.php` exactly (no trailing space) and that `S3_FORCE_PATH_STYLE`
  is literally `true` (the string, not a boolean in the dashboard UI).

## Verifying end to end

1. Upload an image in the Rin editor.
2. Publish (or save) the article and open it in a private browser window.
3. The image URL in the article should be
   `https://storage.example.com/images/<sha1>.<ext>` and should load
   directly, without any login.
4. If the article is private ("visible only to me"), a visitor will see
   Rin's "Permission denied" for the article itself — that is Rin's own
   access control, not a storage failure. Make the article public to verify
   the image link from outside.
