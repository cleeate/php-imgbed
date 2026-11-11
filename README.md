# php-imgbed

一个极简单文件图床：拖拽上传图片，得到可复制的直链 / HTML / Markdown。
无框架、无 Composer、无数据库、**无 S3**——图片直接存本机磁盘（`uploads/`），
一个 `index.php` 搞定前后端。

## 功能

- 拖拽 / 点击上传图片：jpg、jpeg、png、gif、webp，单张 ≤ 10M
- 上传后返回 **直链 / HTML / Markdown** 三种格式，浏览器端一键复制
- 「最近图片」历史（浏览器 localStorage，最多 60 条）
- 上传校验：扩展名白名单 + `getimagesize` 真实图片校验 + 内容 MD5 去重命名
- 本地磁盘存储，无任何外部服务 / 密钥

## 部署（Apache，如 iFastNet / VistaPanel 免费主机）

1. 复制 `index.php`、`.htaccess` 到 web root（如 `/htdocs`）
2. 完成。`uploads/` 目录首次上传时自动创建（权限 0755）
3. 上传后的图片地址：`https://your-host/uploads/<md5>.<ext>`，可直接引用

> 单文件、无依赖，任何普通 PHP 虚拟主机都能跑。

## 上传接口

`POST /`（`multipart/form-data`，文件字段名 `file`）：

```json
{ "ok": true, "url": "https://your-host/uploads/<md5>.jpg", "name": "<md5>.jpg", "orig": "原名.jpg" }
```

失败返回 `{ "ok": false, "err": "原因" }`。

## 安全

- 仅接受 jpg / png / gif / webp，且文件内容必须与扩展名一致（`getimagesize`），
  防止伪装成图片的脚本
- 单文件 ≤ 10M；文件名由内容 MD5 生成，不含任何用户输入，无路径穿越风险
- `uploads/.htaccess` 禁止该目录执行 PHP / CGI 等脚本，并禁止目录列表，
  防止上传恶意文件后被执行

## License

MIT — 见 `LICENSE`。用在生产环境欢迎回个链接，但不强制。
