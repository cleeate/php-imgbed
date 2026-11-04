<?php
/**
 * php-s3-lite — a single-file S3-compatible object storage endpoint.
 *
 * No framework, no Composer, PHP 8.1+ (tested on 8.3/8.4).
 * Built for plain PHP shared hosting (Apache with mod_rewrite, see .htaccess).
 *
 * Supported S3 operations (path-style, SigV4 header auth and presigned URLs):
 *   PUT    /{bucket}/{key}                     PutObject (also CopyObject via x-amz-copy-source)
 *   GET    /{bucket}/{key}                     GetObject
 *   HEAD   /{bucket}/{key}                     HeadObject
 *   DELETE /{bucket}/{key}                     DeleteObject
 *   GET    /{bucket}  ?list-type=2             ListObjectsV2 (also V1 ?marker=)
 *   POST   /{bucket}/{key}?uploads             CreateMultipartUpload
 *   PUT    /{bucket}/{key}?partNumber=N&uploadId=...   UploadPart
 *   POST   /{bucket}/{key}?uploadId=...        CompleteMultipartUpload (XML body)
 *   DELETE /{bucket}/{key}?uploadId=...        AbortMultipartUpload
 *   GET    /{bucket}/{key}?uploadId=...        ListParts
 *   OPTIONS (any path)                          CORS preflight, no auth required
 *
 * Design notes (see README.md for the full story):
 * - The bucket segment is accepted and, if config['buckets'] is set, validated,
 *   but objects are stored under the web root at /{key}. That makes the public
 *   URL /{key} (no bucket) work as a plain static file, which is exactly what
 *   Rin (openRin/Rin) expects: its public URL is S3_ACCESS_HOST + "/" + key,
 *   while its API URL is S3_ENDPOINT + "/" + bucket + "/" + key.
 * - aws4fetch (used by Rin) signs S3 with X-Amz-Content-Sha256 = the literal
 *   string "UNSIGNED-PAYLOAD". This endpoint accepts that literal, as well as
 *   a real hex body hash. Rejecting UNSIGNED-PAYLOAD breaks Rin.
 * - Some front proxies convert an incoming HEAD into a GET upstream. For the
 *   read-only HEAD/GET pair, either signature is accepted; PUT/DELETE are not
 *   affected by that fallback.
 *
 * SECURITY: never let an S3 upload overwrite code or read secrets. Reserved
 * names (s3.php, s3-config.php, .htaccess, index.php, anything under
 * .multipart/, any *.php/*.phtml file) are rejected for every S3 operation,
 * and PUT is limited to the extensions in config['allowed_extensions']
 * (default: images and video only). Change that list only if you understand
 * that uploaded HTML/JS served from your domain can be used for phishing.
 */
declare(strict_types=1);

const S3_PLACEHOLDER_ACCESS = 'TODO_REPLACE_ACCESS_KEY';
const S3_PLACEHOLDER_SECRET = 'TODO_REPLACE_SECRET_KEY';

function s3_xml_error(int $status, string $code, string $message): void
{
    http_response_code($status);
    header('Content-Type: application/xml');
    header('Access-Control-Allow-Origin: *');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<Error><Code>' . htmlspecialchars($code, ENT_XML1) . '</Code>'
        . '<Message>' . htmlspecialchars($message, ENT_XML1) . '</Message>'
        . '<RequestId>' . bin2hex(random_bytes(8)) . '</RequestId></Error>';
    exit;
}

function s3_headers(): array
{
    if (function_exists('getallheaders')) {
        $raw = getallheaders();
        if (is_array($raw)) {
            $out = [];
            foreach ($raw as $k => $v) {
                $out[strtolower((string)$k)] = trim((string)$v);
            }
            return $out;
        }
    }
    $out = [];
    foreach ($_SERVER as $k => $v) {
        if (str_starts_with($k, 'HTTP_')) {
            $out[strtolower(str_replace('_', '-', substr($k, 5)))] = trim((string)$v);
        }
    }
    if (isset($_SERVER['CONTENT_TYPE'])) {
        $out['content-type'] = trim((string)$_SERVER['CONTENT_TYPE']);
    }
    if (isset($_SERVER['CONTENT_LENGTH'])) {
        $out['content-length'] = trim((string)$_SERVER['CONTENT_LENGTH']);
    }
    if (isset($_SERVER['HTTP_HOST'])) {
        $out['host'] = trim((string)$_SERVER['HTTP_HOST']);
    }
    return $out;
}

function s3_encode(string $v): string
{
    return rawurlencode($v);
}

function s3_canonical_query(string $query, array $exclude = []): string
{
    if ($query === '') {
        return '';
    }
    $pairs = [];
    foreach (explode('&', $query) as $pair) {
        if ($pair === '') {
            continue;
        }
        $kv = explode('=', $pair, 2);
        $k = rawurldecode($kv[0]);
        if (in_array($k, $exclude, true)) {
            continue;
        }
        $pairs[] = [s3_encode($k), isset($kv[1]) ? s3_encode(rawurldecode($kv[1])) : ''];
    }
    usort($pairs, static function (array $a, array $b): int {
        $c = strcmp($a[0], $b[0]);
        return $c !== 0 ? $c : strcmp($a[1], $b[1]);
    });
    $out = [];
    foreach ($pairs as [$k, $v]) {
        $out[] = $k . '=' . $v;
    }
    return implode('&', $out);
}

function s3_canonical_uri(string $path): string
{
    if ($path === '' || $path === '/') {
        return '/';
    }
    return '/' . implode('/', array_map(static fn (string $s): string => s3_encode($s), explode('/', ltrim($path, '/'))));
}

function s3_hmac(string $key, string $data): string
{
    return hash_hmac('sha256', $data, $key, true);
}

function s3_signing_key(string $secret, string $date, string $region): string
{
    $kDate = s3_hmac('AWS4' . $secret, $date);
    $kRegion = s3_hmac($kDate, $region);
    $kService = s3_hmac($kRegion, 's3');
    return s3_hmac($kService, 'aws4_request');
}

/** Verify a SigV4 signature for one canonical method. Returns true on match. */
function s3_verify_for_method(string $accessKey, string $secretKey, string $method, string $rawPath, string $rawQuery, array $headers, ?string $bodyHash, array $queryParams): bool
{
    // Presigned URL (query auth) takes precedence when present.
    if (isset($queryParams['X-Amz-Algorithm'])) {
        if ($queryParams['X-Amz-Algorithm'] !== 'AWS4-HMAC-SHA256') {
            return false;
        }
        $cred = $queryParams['X-Amz-Credential'] ?? '';
        $signedHeadersStr = $queryParams['X-Amz-SignedHeaders'] ?? '';
        $signature = $queryParams['X-Amz-Signature'] ?? '';
        $amzDate = $queryParams['X-Amz-Date'] ?? '';
        $expires = (int)($queryParams['X-Amz-Expires'] ?? 0);
        if ($cred === '' || $signedHeadersStr === '' || $signature === '' || $amzDate === '') {
            return false;
        }
        $credParts = explode('/', $cred);
        if (count($credParts) !== 5) {
            return false;
        }
        [$ak, $dateStamp, $region, $service, $term] = $credParts;
        if ($ak !== $accessKey || $service !== 's3' || $term !== 'aws4_request') {
            return false;
        }
        $dt = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $amzDate, new DateTimeZone('UTC'));
        if ($dt === false || $dt->format('Ymd') !== $dateStamp) {
            return false;
        }
        if ($expires > 0 && time() > $dt->getTimestamp() + $expires) {
            return false;
        }
        $signedHeaders = explode(';', $signedHeadersStr);
        $canon = [];
        foreach ($signedHeaders as $h) {
            $h = strtolower(trim($h));
            if ($h === '') {
                continue;
            }
            $value = $headers[$h] ?? '';
            if ($h === 'host' && $value === '' && isset($_SERVER['HTTP_HOST'])) {
                $value = (string)$_SERVER['HTTP_HOST'];
            }
            // Presigned URLs are opened by a plain browser, which does not
            // send X-Amz-Content-Sha256; aws4fetch signed it as UNSIGNED-PAYLOAD.
            if ($h === 'x-amz-content-sha256' && $value === '') {
                $value = 'UNSIGNED-PAYLOAD';
            }
            $canon[] = $h . ':' . trim((string)preg_replace('/\s+/', ' ', (string)$value));
        }
        $canonicalHeaders = implode("\n", $canon) . "\n";
        $payloadHash = $headers['x-amz-content-sha256'] ?? 'UNSIGNED-PAYLOAD';
        $canonicalRequest = $method . "\n" . s3_canonical_uri($rawPath) . "\n"
            . s3_canonical_query($rawQuery, ['X-Amz-Signature']) . "\n"
            . $canonicalHeaders . "\n" . $signedHeadersStr . "\n" . $payloadHash;
        $scope = $dateStamp . '/' . $region . '/s3/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);
        return hash_equals(hash_hmac('sha256', $stringToSign, s3_signing_key($secretKey, $dateStamp, $region)), $signature);
    }

    // Header auth.
    $auth = $headers['authorization'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null);
    if (!is_string($auth) || !str_starts_with($auth, 'AWS4-HMAC-SHA256 ')) {
        return false;
    }
    $parts = [];
    foreach (explode(',', substr($auth, strlen('AWS4-HMAC-SHA256 '))) as $part) {
        $eq = strpos($part, '=');
        if ($eq === false) {
            continue;
        }
        $parts[trim(substr($part, 0, $eq))] = trim(substr($part, $eq + 1));
    }
    $cred = $parts['Credential'] ?? '';
    $signedHeadersStr = $parts['SignedHeaders'] ?? '';
    $signature = $parts['Signature'] ?? '';
    if ($cred === '' || $signedHeadersStr === '' || $signature === '') {
        return false;
    }
    $credParts = explode('/', $cred);
    if (count($credParts) !== 5) {
        return false;
    }
    [$ak, $dateStamp, $region, $service, $term] = $credParts;
    if ($ak !== $accessKey || $service !== 's3' || $term !== 'aws4_request') {
        return false;
    }
    $amzDate = $headers['x-amz-date'] ?? '';
    if ($amzDate === '' || !preg_match('/^\d{8}T\d{6}Z$/', $amzDate) || substr($amzDate, 0, 8) !== $dateStamp) {
        return false;
    }
    $signedHeaders = explode(';', $signedHeadersStr);
    $canon = [];
    foreach ($signedHeaders as $h) {
        $h = strtolower(trim($h));
        if ($h === '') {
            continue;
        }
        $value = $headers[$h] ?? '';
        $canon[] = $h . ':' . trim((string)preg_replace('/\s+/', ' ', (string)$value));
    }
    $canonicalHeaders = implode("\n", $canon) . "\n";
    // Header value is authoritative (aws4fetch: UNSIGNED-PAYLOAD for S3).
    $payloadHash = $headers['x-amz-content-sha256'] ?? $bodyHash ?? hash('sha256', '');
    $canonicalRequest = $method . "\n" . s3_canonical_uri($rawPath) . "\n"
        . s3_canonical_query($rawQuery) . "\n"
        . $canonicalHeaders . "\n" . $signedHeadersStr . "\n" . $payloadHash;
    $scope = $dateStamp . '/' . $region . '/s3/aws4_request';
    $stringToSign = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);
    return hash_equals(hash_hmac('sha256', $stringToSign, s3_signing_key($secretKey, $dateStamp, $region)), $signature);
}

function s3_verify(string $accessKey, string $secretKey, string $method, string $rawPath, string $rawQuery, array $headers, ?string $bodyHash, array $queryParams): bool
{
    if (s3_verify_for_method($accessKey, $secretKey, $method, $rawPath, $rawQuery, $headers, $bodyHash, $queryParams)) {
        return true;
    }
    // HEAD/GET proxy quirk: some front proxies turn HEAD into GET upstream.
    if ($method === 'GET') {
        return s3_verify_for_method($accessKey, $secretKey, 'HEAD', $rawPath, $rawQuery, $headers, $bodyHash, $queryParams);
    }
    if ($method === 'HEAD') {
        return s3_verify_for_method($accessKey, $secretKey, 'GET', $rawPath, $rawQuery, $headers, $bodyHash, $queryParams);
    }
    return false;
}

function s3_content_type(string $key): string
{
    $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
    $map = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
        'avif' => 'image/avif', 'mp4' => 'video/mp4', 'webm' => 'video/webm',
        'mov' => 'video/quicktime', 'pdf' => 'application/pdf', 'txt' => 'text/plain',
    ];
    return $map[$ext] ?? 'application/octet-stream';
}

function s3_is_reserved(string $key): bool
{
    $base = strtolower(basename($key));
    if (in_array($base, ['s3.php', 's3-config.php', '.htaccess', 'index.php', 'index.html'], true)) {
        return true;
    }
    if (str_starts_with($key, '.multipart/') || str_contains($key, '/.multipart/')) {
        return true;
    }
    $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
    if (in_array($ext, ['php', 'phtml', 'phar', 'cgi', 'pl'], true)) {
        return true;
    }
    return false;
}

// ---- Config ----
$configFile = __DIR__ . '/s3-config.php';
if (!is_file($configFile)) {
    s3_xml_error(500, 'InternalError', 'Configuration missing');
}
$config = require $configFile;
if (!is_array($config)
    || !isset($config['access_key'], $config['secret_key'])
    || $config['access_key'] === S3_PLACEHOLDER_ACCESS
    || $config['secret_key'] === S3_PLACEHOLDER_SECRET
    || $config['access_key'] === '' || $config['secret_key'] === '') {
    s3_xml_error(500, 'InternalError', 'Configuration not set');
}
$S3_ACCESS_KEY = (string)$config['access_key'];
$S3_SECRET_KEY = (string)$config['secret_key'];
$MAX_BYTES = (int)($config['max_bytes'] ?? 10 * 1024 * 1024);
$MULTIPART_MAX_BYTES = (int)($config['multipart_max_bytes'] ?? 100 * 1024 * 1024);
$ALLOWED_EXT = $config['allowed_extensions'] ?? ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif', 'mp4', 'webm', 'mov'];
$ALLOWED_BUCKETS = $config['buckets'] ?? []; // empty = accept any bucket name
$CORS_ORIGIN = (string)($config['cors_origin'] ?? '*');
unset($config);

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$headers = s3_headers();
if (!isset($headers['authorization']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $headers['authorization'] = trim((string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
}

// CORS preflight needs no auth.
if ($method === 'OPTIONS') {
    http_response_code(200);
    header('Access-Control-Allow-Origin: ' . $CORS_ORIGIN);
    header('Access-Control-Allow-Methods: GET, PUT, HEAD, DELETE, POST, OPTIONS');
    header('Access-Control-Allow-Headers: *');
    header('Access-Control-Max-Age: 86400');
    header('Content-Length: 0');
    exit;
}
header('Access-Control-Allow-Origin: ' . $CORS_ORIGIN);

// ---- Parse bucket/key ----
$rawUri = $_SERVER['REQUEST_URI'] ?? '/';
$rawPath = (string)parse_url($rawUri, PHP_URL_PATH);
$rawQuery = (string)parse_url($rawUri, PHP_URL_QUERY);
parse_str($rawQuery, $queryParams);
if (isset($_GET['bucket'], $_GET['key']) && $_GET['bucket'] !== '') {
    $bucket = (string)$_GET['bucket'];
    $storageKey = (string)$_GET['key'];
} else {
    $trimmed = ltrim($rawPath, '/');
    $slash = strpos($trimmed, '/');
    if ($slash === false) {
        $bucket = $trimmed;
        $storageKey = '';
    } else {
        $bucket = substr($trimmed, 0, $slash);
        $storageKey = substr($trimmed, $slash + 1);
    }
    $storageKey = $storageKey === '' ? '' : implode('/', array_map('rawurldecode', explode('/', $storageKey)));
}
if ($bucket === '') {
    s3_xml_error(400, 'InvalidArgument', 'Missing bucket');
}
if ($ALLOWED_BUCKETS !== [] && !in_array($bucket, $ALLOWED_BUCKETS, true)) {
    s3_xml_error(404, 'NoSuchBucket', 'The specified bucket does not exist');
}

// ListBucket: GET on /{bucket} (or with list-type/marker query).
$isList = $method === 'GET' && ($storageKey === '' || isset($queryParams['list-type']) || isset($queryParams['marker']));

// Validate key (list requests skip this).
if (!$isList) {
    if ($storageKey === '' || strlen($storageKey) > 1024
        || str_contains($storageKey, "\0") || str_contains($storageKey, '\\')
        || str_starts_with($storageKey, '/')) {
        s3_xml_error(400, 'InvalidArgument', 'Invalid key');
    }
    foreach (explode('/', $storageKey) as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            s3_xml_error(400, 'InvalidArgument', 'Invalid key');
        }
    }
    if (!preg_match('#^[A-Za-z0-9._/-]+$#', $storageKey)) {
        s3_xml_error(400, 'InvalidArgument', 'Invalid key characters');
    }
    if (s3_is_reserved($storageKey)) {
        s3_xml_error(403, 'AccessDenied', 'Reserved key');
    }
}

// ---- Auth (header or presigned) ----
if (!isset($headers['authorization']) && !isset($queryParams['X-Amz-Algorithm'])) {
    s3_xml_error(401, 'AccessDenied', 'Missing Authorization');
}
$body = null;
$bodyHash = null;
if ($method === 'PUT' || $method === 'POST') {
    $body = file_get_contents('php://input');
    if ($body === false) {
        $body = '';
    }
    $bodyHash = hash('sha256', $body);
    $claimed = $headers['x-amz-content-sha256'] ?? '';
    if ($claimed !== '' && $claimed !== 'UNSIGNED-PAYLOAD' && $claimed !== $bodyHash) {
        s3_xml_error(403, 'SignatureDoesNotMatch', 'Payload hash mismatch');
    }
}
if (!s3_verify($S3_ACCESS_KEY, $S3_SECRET_KEY, $method, $rawPath, $rawQuery, $headers, $bodyHash, $queryParams)) {
    s3_xml_error(403, 'SignatureDoesNotMatch', 'Signature does not match');
}

// ---- Storage path ----
$target = __DIR__ . '/' . $storageKey;
$realRoot = realpath(__DIR__);
if ($realRoot === false) {
    s3_xml_error(500, 'InternalError', 'Storage root error');
}
$parent = dirname($target);
if ($storageKey !== '' && !is_dir($parent) && $method === 'PUT') {
    if (!mkdir($parent, 0755, true) && !is_dir($parent)) {
        s3_xml_error(500, 'InternalError', 'Cannot create directory');
    }
}
if ($method === 'POST' && isset($queryParams['uploadId']) && $storageKey !== '' && !is_dir($parent)) {
    mkdir($parent, 0755, true);
}
$realParent = $storageKey === '' ? $realRoot : realpath($parent);
if ($realParent === false) {
    // A missing parent directory means a missing object, not a bad key.
    if ($method === 'GET' || $method === 'HEAD') {
        s3_xml_error(404, 'NoSuchKey', 'The specified key does not exist');
    }
    if ($method === 'DELETE') {
        http_response_code(204);
        exit;
    }
    s3_xml_error(400, 'InvalidArgument', 'Invalid key');
}
if (!str_starts_with($realParent, $realRoot)) {
    s3_xml_error(400, 'InvalidArgument', 'Invalid key');
}
$multipartDir = __DIR__ . '/.multipart';
$multipartMeta = static function (string $uploadId) use ($multipartDir): string {
    return $multipartDir . '/' . $uploadId . '.json';
};

// ---- Multipart ----
$uploadId = $queryParams['uploadId'] ?? null;
if (is_string($uploadId) && !preg_match('#^[A-Za-z0-9_-]{1,128}$#', $uploadId)) {
    s3_xml_error(400, 'InvalidArgument', 'Invalid uploadId');
}
if ($method === 'POST' && isset($queryParams['uploads'])) {
    $newId = bin2hex(random_bytes(16));
    if (!is_dir($multipartDir) && !mkdir($multipartDir, 0755, true) && !is_dir($multipartDir)) {
        s3_xml_error(500, 'InternalError', 'Cannot create multipart directory');
    }
    if (!is_dir($multipartDir . '/' . $newId) && !mkdir($multipartDir . '/' . $newId, 0755, true)) {
        s3_xml_error(500, 'InternalError', 'Cannot create multipart directory');
    }
    file_put_contents($multipartMeta($newId), json_encode(['key' => $storageKey, 'bucket' => $bucket, 'parts' => []]));
    http_response_code(200);
    header('Content-Type: application/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<InitiateMultipartUploadResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
        . '<Bucket>' . htmlspecialchars($bucket, ENT_XML1) . '</Bucket>'
        . '<Key>' . htmlspecialchars($storageKey, ENT_XML1) . '</Key>'
        . '<UploadId>' . $newId . '</UploadId></InitiateMultipartUploadResult>';
    exit;
}
if ($uploadId !== null && $method === 'PUT' && isset($queryParams['partNumber'])) {
    $partNumber = (int)$queryParams['partNumber'];
    if ($partNumber < 1 || $partNumber > 10000 || $body === null || $body === '') {
        s3_xml_error(400, 'InvalidArgument', 'Invalid part');
    }
    $metaFile = $multipartMeta($uploadId);
    if (!is_file($metaFile)) {
        s3_xml_error(404, 'NoSuchUpload', 'The specified upload does not exist');
    }
    $partFile = $multipartDir . '/' . $uploadId . '/part-' . $partNumber;
    file_put_contents($partFile, $body);
    http_response_code(200);
    header('ETag: "' . md5($body) . '"');
    header('Content-Length: 0');
    exit;
}
if ($uploadId !== null && $method === 'POST') {
    // CompleteMultipartUpload
    $metaFile = $multipartMeta($uploadId);
    if (!is_file($metaFile)) {
        s3_xml_error(404, 'NoSuchUpload', 'The specified upload does not exist');
    }
    // Parse CompleteMultipartUpload without simplexml (not guaranteed on
    // shared hosts): the body is a small, fixed-shape XML document.
    $parts = [];
    if (!preg_match_all('#<Part>\s*<PartNumber>(\d+)</PartNumber>\s*<ETag>([^<]+)</ETag>\s*</Part>#i', $body ?? '', $m, PREG_SET_ORDER)) {
        s3_xml_error(400, 'MalformedXML', 'Invalid CompleteMultipartUpload body');
    }
    foreach ($m as $match) {
        $parts[(int)$match[1]] = trim($match[2], '" ');
    }
    ksort($parts);
    $out = '';
    $md5s = '';
    foreach ($parts as $n => $etag) {
        $partFile = $multipartDir . '/' . $uploadId . '/part-' . $n;
        if (!is_file($partFile)) {
            s3_xml_error(400, 'InvalidPart', 'Missing part ' . $n);
        }
        $data = (string)file_get_contents($partFile);
        $out .= $data;
        $md5s .= md5($data, true);
    }
    if (strlen($out) > $MULTIPART_MAX_BYTES) {
        s3_xml_error(413, 'EntityTooLarge', 'Multipart object too large');
    }
    if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
        s3_xml_error(500, 'InternalError', 'Cannot create directory');
    }
    file_put_contents($target, $out);
    @chmod($target, 0644);
    // Cleanup
    foreach (glob($multipartDir . '/' . $uploadId . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($multipartDir . '/' . $uploadId);
    @unlink($metaFile);
    $finalEtag = md5($md5s) . '-' . count($parts);
    http_response_code(200);
    header('Content-Type: application/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<CompleteMultipartUploadResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
        . '<Location>/' . htmlspecialchars($storageKey, ENT_XML1) . '</Location>'
        . '<Bucket>' . htmlspecialchars($bucket, ENT_XML1) . '</Bucket>'
        . '<Key>' . htmlspecialchars($storageKey, ENT_XML1) . '</Key>'
        . '<ETag>"' . $finalEtag . '"</ETag></CompleteMultipartUploadResult>';
    exit;
}
if ($uploadId !== null && $method === 'DELETE') {
    $metaFile = $multipartMeta($uploadId);
    if (is_file($metaFile)) {
        foreach (glob($multipartDir . '/' . $uploadId . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($multipartDir . '/' . $uploadId);
        @unlink($metaFile);
    }
    http_response_code(204);
    exit;
}
if ($uploadId !== null && $method === 'GET') {
    $metaFile = $multipartMeta($uploadId);
    if (!is_file($metaFile)) {
        s3_xml_error(404, 'NoSuchUpload', 'The specified upload does not exist');
    }
    http_response_code(200);
    header('Content-Type: application/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<ListPartsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
        . '<Bucket>' . htmlspecialchars($bucket, ENT_XML1) . '</Bucket>'
        . '<Key>' . htmlspecialchars($storageKey, ENT_XML1) . '</Key>'
        . '<UploadId>' . htmlspecialchars($uploadId, ENT_XML1) . '</UploadId>'
        . '<IsTruncated>false</IsTruncated>';
    foreach (glob($multipartDir . '/' . $uploadId . '/part-*') ?: [] as $f) {
        $n = (int)preg_replace('/^part-/', '', basename($f));
        echo '<Part><PartNumber>' . $n . '</PartNumber>'
            . '<LastModified>' . gmdate('Y-m-d\TH:i:s.000\Z', (int)filemtime($f)) . '</LastModified>'
            . '<ETag>"' . md5_file($f) . '"</ETag>'
            . '<Size>' . filesize($f) . '</Size></Part>';
    }
    echo '</ListPartsResult>';
    exit;
}

// ---- ListObjectsV2 / V1 ----
if ($isList) {
    $prefix = (string)($queryParams['prefix'] ?? '');
    $maxKeys = min(1000, max(1, (int)($queryParams['max-keys'] ?? 1000)));
    $offset = 0;
    if (isset($queryParams['continuation-token'])) {
        $offset = (int)$queryParams['continuation-token'];
    } elseif (isset($queryParams['marker'])) {
        // V1: marker is a key; handled below by skipping keys <= marker.
        $offset = 0;
    }
    $all = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $rel = substr($file->getPathname(), strlen(__DIR__) + 1);
        $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
        if (str_starts_with($rel, '.multipart/') || s3_is_reserved($rel)) {
            continue;
        }
        if ($prefix !== '' && !str_starts_with($rel, $prefix)) {
            continue;
        }
        $all[] = $rel;
    }
    sort($all);
    if (isset($queryParams['marker'])) {
        $marker = (string)$queryParams['marker'];
        $all = array_values(array_filter($all, static fn (string $k): bool => strcmp($k, $marker) > 0));
    }
    $total = count($all);
    $page = array_slice($all, $offset, $maxKeys);
    $isTruncated = ($offset + count($page)) < $total;
    http_response_code(200);
    header('Content-Type: application/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
        . '<Name>' . htmlspecialchars($bucket, ENT_XML1) . '</Name>'
        . '<Prefix>' . htmlspecialchars($prefix, ENT_XML1) . '</Prefix>'
        . '<KeyCount>' . count($page) . '</KeyCount>'
        . '<MaxKeys>' . $maxKeys . '</MaxKeys>'
        . '<IsTruncated>' . ($isTruncated ? 'true' : 'false') . '</IsTruncated>';
    if ($isTruncated) {
        echo '<NextContinuationToken>' . ($offset + count($page)) . '</NextContinuationToken>';
    }
    foreach ($page as $k) {
        $full = __DIR__ . '/' . $k;
        echo '<Contents><Key>' . htmlspecialchars($k, ENT_XML1) . '</Key>'
            . '<LastModified>' . gmdate('Y-m-d\TH:i:s.000\Z', (int)filemtime($full)) . '</LastModified>'
            . '<ETag>"' . md5_file($full) . '"</ETag>'
            . '<Size>' . filesize($full) . '</Size>'
            . '<StorageClass>STANDARD</StorageClass></Contents>';
    }
    echo '</ListBucketResult>';
    exit;
}

// ---- PutObject (with optional CopyObject) ----
if ($method === 'PUT') {
    if ($body === '' || $body === null) {
        // CopyObject has an empty body and a copy source header.
        $copySource = $headers['x-amz-copy-source'] ?? null;
        if ($copySource !== null) {
            $src = ltrim(rawurldecode($copySource), '/');
            $slash = strpos($src, '/');
            $srcKey = $slash === false ? '' : substr($src, $slash + 1);
            $srcFile = __DIR__ . '/' . $srcKey;
            if ($srcKey === '' || s3_is_reserved($srcKey) || !is_file($srcFile)) {
                s3_xml_error(404, 'NoSuchKey', 'Copy source does not exist');
            }
            if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
                s3_xml_error(500, 'InternalError', 'Cannot create directory');
            }
            copy($srcFile, $target);
            @chmod($target, 0644);
            http_response_code(200);
            header('Content-Type: application/xml');
            echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
            echo '<CopyObjectResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
                . '<LastModified>' . gmdate('Y-m-d\TH:i:s.000\Z', (int)filemtime($target)) . '</LastModified>'
                . '<ETag>"' . md5_file($target) . '"</ETag></CopyObjectResult>';
            exit;
        }
        s3_xml_error(400, 'InvalidArgument', 'Empty body');
    }
    if (strlen($body) > $MAX_BYTES) {
        s3_xml_error(413, 'EntityTooLarge', 'File larger than configured max_bytes');
    }
    $ext = strtolower(pathinfo($storageKey, PATHINFO_EXTENSION));
    if ($ALLOWED_EXT !== [] && !in_array($ext, array_map('strtolower', $ALLOWED_EXT), true)) {
        s3_xml_error(400, 'InvalidArgument', 'File extension not allowed');
    }
    $ct = $headers['content-type'] ?? '';
    if ($ct !== '' && !str_starts_with($ct, 'image/') && !str_starts_with($ct, 'video/') && $ct !== 'application/octet-stream' && $ct !== 'application/pdf' && $ct !== 'text/plain') {
        s3_xml_error(400, 'InvalidArgument', 'Unsupported content type');
    }
    $tmp = tempnam($parent, 's3tmp');
    if ($tmp === false || file_put_contents($tmp, $body) === false || !rename($tmp, $target)) {
        if (is_string($tmp) && is_file($tmp)) {
            @unlink($tmp);
        }
        s3_xml_error(500, 'InternalError', 'Cannot write file');
    }
    @chmod($target, 0644);
    http_response_code(200);
    header('ETag: "' . md5($body) . '"');
    header('Content-Length: 0');
    exit;
}

if ($method === 'GET' || $method === 'HEAD') {
    if (!is_file($target)) {
        s3_xml_error(404, 'NoSuchKey', 'The specified key does not exist');
    }
    http_response_code(200);
    header('Content-Type: ' . s3_content_type($storageKey));
    header('Content-Length: ' . (string)filesize($target));
    header('ETag: "' . md5_file($target) . '"');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int)filemtime($target)) . ' GMT');
    if ($method === 'GET') {
        readfile($target);
    }
    exit;
}

if ($method === 'DELETE') {
    if (is_file($target)) {
        @unlink($target);
    }
    http_response_code(204);
    exit;
}

s3_xml_error(405, 'MethodNotAllowed', 'Method not allowed');
