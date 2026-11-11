# Security policy

## 支持的版本

仅 `main` 分支最新版本获得安全修复。

## 安全说明

- 上传白名单：仅 jpg / png / gif / webp，且用 `getimagesize` 校验真实内容与扩展名一致
- 单文件 ≤ 10M；文件名 = 内容 MD5，无用户可控路径，无路径穿越
- `uploads/.htaccess` 禁止 PHP / PHTML / PHAR / CGI / PL / PY 执行，并禁止目录列表
- 本服务**无鉴权**，公开匿名上传：请部署在可接受匿名上传的环境，
  有滥用风险时在 CDN / WAF / 主机层加限流

## 报告漏洞

请先开 issue 标题「Security report」并给简要描述，勿公开利用细节；
修复完成前请留出时间再公开披露。
