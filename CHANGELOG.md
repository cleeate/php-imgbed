# Changelog

## Unreleased (v1.0.0 candidate)

- Single-file S3 endpoint: Put/Get/Head/Delete, ListObjectsV2/V1, CopyObject,
  multipart upload (create/part/complete/abort/list-parts), presigned URLs,
  CORS preflight.
- SigV4 header auth, accepting both a real body hash and aws4fetch's
  `UNSIGNED-PAYLOAD` literal (required for Rin).
- HEAD/GET signature fallback for front proxies that turn HEAD into GET.
- Security: reserved-name blocking (code/config cannot be overwritten or
  read via S3), PUT extension allowlist (images/video by default),
  path-traversal rejection, atomic writes via temp file + rename.
- Docs: README, Rin integration, aws-cli, Nginx; end-to-end test with
  aws4fetch (the client Rin uses).
