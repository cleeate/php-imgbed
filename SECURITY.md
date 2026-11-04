# Security policy

## Supported versions

Only the latest release on the `main` branch is supported with security
fixes. If you are running an older copy, upgrade `s3.php` (and check
`s3-config.example.php` for new options) before reporting.

## What this endpoint trusts

- The S3 access key is a **full write key** for the web root. Anyone with
  it can upload, overwrite (within the allowed extensions), list, and
  delete objects. Rotate it immediately if it leaks: change
  `s3-config.php` on the host and update every client.
- Reserved names (`s3.php`, `s3-config.php`, `.htaccess`, `index.php`,
  `.multipart/`, any `*.php` file) are rejected for every S3 operation.
  This is load-bearing: without it, the S3 key could overwrite the endpoint
  or read the config file through the S3 API itself.
- PUT extensions are limited by config (`allowed_extensions`, default
  images + video). Do not widen this to executable or HTML types on a
  public host without understanding the phishing / XSS risk.

## Reporting a vulnerability

Please do not open a public issue with exploit details first. Open an
issue titled "Security report" with a minimal description, or contact the
maintainer through the repository, and allow time for a fix before
disclosing details publicly.
