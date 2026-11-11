# Changelog

## v2.0.0 — 极简图床（移除 S3）

- **移除** S3 端点及其配套：`s3.php`、`s3-config.example.php`、`docs/`（Rin、aws-cli、Nginx）、`tests/`
- 改为极简单文件图床：本地磁盘存储（`uploads/`），无任何外部依赖
- 上传返回直链 / HTML / Markdown，浏览器端一键复制；「最近图片」历史（localStorage）
- 上传校验：扩展名白名单 + `getimagesize` 真实图片校验 + 内容 MD5 去重命名
- `uploads/.htaccess` 禁止脚本执行与目录列表；首页页脚新增源码仓库链接

## v1.0.0（历史）

- 单文件 S3 兼容对象存储端点：SigV4（含 aws4fetch `UNSIGNED-PAYLOAD`）、
  分片上传、预签名 URL、CopyObject、ListObjectsV2 —— 已被 v2.0 取代，不再维护
