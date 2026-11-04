// End-to-end test using the same S3 client Rin uses: aws4fetch.
// Run:  cp s3-config.example.php s3-config.php   (fill in TEST keys below)
//       php -S 127.0.0.1:8765 tests/router.php
//       node tests/test-aws4fetch.mjs
// The test uses its own TEST keys; set them in s3-config.php first.
// Requires aws4fetch: npm i aws4fetch  (or it is fetched by the CI job).
import { AwsClient } from 'aws4fetch';

const BASE = process.env.S3_TEST_BASE || 'http://127.0.0.1:8765';
const AK = process.env.S3_TEST_AK || 'TESTACCESSKEY123';
const SK = process.env.S3_TEST_SK || 'TESTSECRETKEY4567890abcdef';
const client = new AwsClient({ accessKeyId: AK, secretAccessKey: SK, service: 's3' });

function assert(cond, msg) {
  if (!cond) throw new Error('FAIL: ' + msg);
  console.log('ok -', msg);
}
const body = new Uint8Array(Buffer.from('\x89PNG\r\n\x1a\nTESTIMAGE'));
const key = 'images/test.png';
const url = `${BASE}/rin/${key}`;

// PUT / GET / HEAD / DELETE
let r = await client.fetch(url, { method: 'PUT', body, headers: { 'Content-Type': 'image/png' } });
assert(r.status === 200, `PUT ${r.status}`);
r = await client.fetch(url, { method: 'GET' });
assert(r.status === 200 && Buffer.from(await r.arrayBuffer()).toString() === Buffer.from(body).toString(), 'GET round-trip');
r = await client.fetch(url, { method: 'HEAD' });
assert(r.status === 200, `HEAD ${r.status}`);

// Public URL (no auth, static file)
let pub = await fetch(`${BASE}/images/test.png`);
assert(pub.status === 200, `GET public ${pub.status}`);

// Reserved files must not be readable or overwritable via S3
r = await client.fetch(`${BASE}/rin/s3-config.php`, { method: 'GET' });
assert(r.status === 403, `GET reserved ${r.status}`);
r = await client.fetch(`${BASE}/rin/s3.php`, { method: 'PUT', body, headers: { 'Content-Type': 'image/png' } });
assert(r.status === 403, `PUT reserved ${r.status}`);

// ListObjectsV2
r = await client.fetch(`${BASE}/rin?list-type=2&prefix=images/`, { method: 'GET' });
const listXml = await r.text();
assert(r.status === 200 && listXml.includes('<Key>images/test.png</Key>'), 'ListObjectsV2');

// CopyObject
r = await client.fetch(`${BASE}/rin/images/test-copy.png`, { method: 'PUT', headers: { 'x-amz-copy-source': '/rin/images/test.png' } });
assert(r.status === 200, `CopyObject ${r.status}`);

// Multipart upload
r = await client.fetch(`${BASE}/rin/images/test-mp.png?uploads`, { method: 'POST' });
const upXml = await r.text();
const uploadId = upXml.match(/<UploadId>([^<]+)<\/UploadId>/)?.[1];
assert(r.status === 200 && uploadId, 'CreateMultipartUpload');
r = await client.fetch(`${BASE}/rin/images/test-mp.png?partNumber=1&uploadId=${uploadId}`, { method: 'PUT', body });
assert(r.status === 200, `UploadPart ${r.status}`);
const partEtag = r.headers.get('etag');
r = await client.fetch(`${BASE}/rin/images/test-mp.png?uploadId=${uploadId}`, {
  method: 'POST',
  body: `<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>${partEtag}</ETag></Part></CompleteMultipartUpload>`,
  headers: { 'Content-Type': 'application/xml' },
});
assert(r.status === 200, `CompleteMultipartUpload ${r.status}`);

// Presigned GET (signQuery)
const signed = await client.sign(`${BASE}/rin/images/test.png`, { method: 'GET', signQuery: true });
r = await fetch(signed.url.toString());
assert(r.status === 200, `Presigned GET ${r.status}`);

// Cleanup
for (const k of ['images/test.png', 'images/test-copy.png', 'images/test-mp.png']) {
  r = await client.fetch(`${BASE}/rin/${k}`, { method: 'DELETE' });
  assert(r.status === 204, `DELETE ${k} ${r.status}`);
}
console.log('ALL TESTS PASSED');
