# php-imgbed

一个极简单文件图床：拖拽上传图片，得到可复制的直链 / HTML / Markdown。
无框架、无 Composer、无数据库、无外部存储依赖——图片直接存本机磁盘（`uploads/`），
一个 `index.php` 搞定前后端。

## 功能

- 拖拽 / 点击上传图片：jpg、jpeg、png、gif、webp，单张 ≤ 10M
- 上传前 Cloudflare Turnstile 人机验证，服务端校验 token 后才接受文件
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

`POST /`（`multipart/form-data`，文件字段名 `file`，另需字段 `cf-turnstile-response` 为 Turnstile token）：

```json
{ "ok": true, "url": "https://your-host/uploads/<md5>.jpg", "name": "<md5>.jpg", "orig": "原名.jpg" }
```

失败返回 `{ "ok": false, "err": "原因" }`。没有 token 或验证不通过时返回 `请先完成人机验证`。

## Turnstile（人机验证）

- 不需要自定义域名、也不用把网站接到 Cloudflare：Turnstile 是嵌在页面的小部件，任何网站都能用
- 上线步骤：注册免费 Cloudflare 账号 → 后台 Turnstile 新建 widget，域名填你的站点（如 `gofor.hyperphp.com`）→ 把 `index.php` 顶部 `TS_SITEKEY` / `TS_SECRET` 换成真实密钥
- 当前代码里是 Cloudflare 官方测试密钥（任何主机名都通过验证，仅用于联调）：`1x00000000000000000000AA` / `1x0000000000000000000000000000000AA`，上线前务必换掉
- 前提：主机 PHP 必须能对外发 HTTPS 请求到 `challenges.cloudflare.com` 做服务端验证（curl 或 `allow_url_fopen`）；若主机禁止对外请求，上传会报 `验证服务不可达`，说明该主机用不了 Turnstile

## 安全

- 上传前需通过 Cloudflare Turnstile 人机验证，token 由服务端向 Cloudflare 校验（仅前端 widget 不算防护）
- 仅接受 jpg / png / gif / webp，且文件内容必须与扩展名一致（`getimagesize`），
  防止伪装成图片的脚本
- 单文件 ≤ 10M；文件名由内容 MD5 生成，不含任何用户输入，无路径穿越风险
- `uploads/.htaccess` 禁止该目录执行 PHP / CGI 等脚本，并禁止目录列表，
  防止上传恶意文件后被执行
- 本服务**无鉴权**（公开匿名上传）：请部署在可接受匿名上传的环境，
  有滥用风险时在 CDN / WAF / 主机层加限流

## 报告漏洞

请先开 issue 标题「Security report」并给简要描述，勿公开利用细节；
修复完成前请留出时间再公开披露。

## License

MIT — 见 `LICENSE`。用在生产环境欢迎回个链接，但不强制。
