<?php
/* 极简图床 - 单文件无依赖 */
define('DIR', __DIR__ . '/uploads');
define('MAX', 10 * 1024 * 1024);
$ALLOWED = ['jpg'=>'jpg','jpeg'=>'jpg','png'=>'png','gif'=>'gif','webp'=>'webp'];

/* Cloudflare Turnstile：下面是官方测试密钥（任何主机名都通过验证，仅用于联调）。
   上线时去 dash.cloudflare.com → Turnstile 新建 widget，域名填 gofor.hyperphp.com，
   把 TS_SITEKEY / TS_SECRET 换成真实密钥即可，代码不用再改。*/
define('TS_SITEKEY', '1x00000000000000000000AA');
define('TS_SECRET', '1x0000000000000000000000000000000AA');
define('TS_VERIFY_URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify');

// 校验 Turnstile token：ok / fail / no-token / unreachable（主机连不上 Cloudflare）
function ts_verify($token) {
    if ($token === '') return 'no-token';
    $post = http_build_query(['secret'=>TS_SECRET, 'response'=>$token, 'remoteip'=>$_SERVER['REMOTE_ADDR'] ?? '']);
    $out = false;
    if (function_exists('curl_init')) {
        $c = curl_init(TS_VERIFY_URL);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$post, CURLOPT_TIMEOUT=>10]);
        $out = curl_exec($c);
    }
    if ($out === false && ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http'=>['method'=>'POST', 'header'=>"Content-Type: application/x-www-form-urlencoded\r\n", 'content'=>$post, 'timeout'=>10]]);
        $out = @file_get_contents(TS_VERIFY_URL, false, $ctx);
    }
    if ($out === false) return 'unreachable';
    $j = json_decode($out, true);
    return (!empty($j['success'])) ? 'ok' : 'fail';
}

// 上传接口
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $ts = ts_verify($_POST['cf-turnstile-response'] ?? '');
    if ($ts !== 'ok') {
        $msg = ($ts === 'unreachable') ? '验证服务不可达（主机可能禁止对外请求）' : '请先完成人机验证';
        echo json_encode(['ok'=>false,'err'=>$msg]); exit;
    }
    if (empty($_FILES['file'])) { echo json_encode(['ok'=>false,'err'=>'没有文件']); exit; }
    $f = $_FILES['file'];
    if ($f['error'] !== UPLOAD_ERR_OK) { echo json_encode(['ok'=>false,'err'=>'上传失败 code='.$f['error']]); exit; }
    if ($f['size'] > MAX) { echo json_encode(['ok'=>false,'err'=>'超过 10M']); exit; }
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!isset($ALLOWED[$ext])) { echo json_encode(['ok'=>false,'err'=>'仅支持 jpg/png/gif/webp']); exit; }
    $info = @getimagesize($f['tmp_name']);
    if ($info === false) { echo json_encode(['ok'=>false,'err'=>'不是有效图片']); exit; }
    $realExt = strtolower(image_type_to_extension($info[2], false));
    if ($realExt === 'jpeg') $realExt = 'jpg';
    if ($realExt !== $ALLOWED[$ext]) { echo json_encode(['ok'=>false,'err'=>'文件内容与扩展名不符']); exit; }
    if (!is_dir(DIR)) mkdir(DIR, 0755, true);
    $name = md5_file($f['tmp_name']) . '.' . $ext;
    if (!file_exists(DIR.'/'.$name)) {
        if (!move_uploaded_file($f['tmp_name'], DIR.'/'.$name)) { echo json_encode(['ok'=>false,'err'=>'保存失败']); exit; }
    }
    $url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off' ? 'https':'http') . '://' . $_SERVER['HTTP_HOST'] . '/uploads/' . $name;
    $orig = basename($f['name']);
    $orig = preg_replace('/[\x00-\x1F]/', '', $orig);
    echo json_encode(['ok'=>true,'url'=>$url,'name'=>$name,'orig'=>$orig]); exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>图床</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,-apple-system,sans-serif;background:#fff;color:#111;max-width:760px;margin:0 auto;padding:32px 20px}
h1{font-size:22px;margin-bottom:4px}
.sub{color:#888;font-size:13px;margin-bottom:24px}
.drop{border:2px dashed #ccc;border-radius:10px;padding:48px 20px;text-align:center;cursor:pointer;transition:.15s}
.drop:hover,.drop.over{border-color:#111;background:#fafafa}
.drop .big{font-size:16px;margin-bottom:6px}
.drop .hint{color:#888;font-size:13px}
#status{margin-top:16px;font-size:14px}
#result{margin-top:20px;display:none}
#result img{max-width:100%;max-height:260px;border-radius:8px;display:block;margin-bottom:12px}
.fmt{display:flex;align-items:center;gap:8px;margin-bottom:8px}
.fmt .lb{width:36px;color:#888;font-size:12px;flex:none}
.fmt input{flex:1;padding:8px 10px;border:1px solid #ccc;border-radius:6px;font-size:13px;color:#111;min-width:0}
.fmt button{padding:6px 12px;border:0;background:#111;color:#fff;border-radius:6px;cursor:pointer;font-size:12px;flex:none}
.fmt button:hover{background:#333}
h2{font-size:16px;margin:36px 0 12px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px}
.item img{width:100%;aspect-ratio:1;object-fit:cover;border-radius:8px;display:block;cursor:pointer;border:1px solid #eee}
.item .nm{font-size:11px;color:#888;margin-top:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.empty{color:#999;font-size:13px;padding:20px 0}
footer{margin-top:44px;padding-top:20px;border-top:1px solid #eee;color:#999;font-size:13px;text-align:center}
footer a{display:inline-flex;align-items:center;gap:7px;color:#666;text-decoration:none;transition:color .15s ease}
footer a svg{width:18px;height:18px;flex:none;transition:transform .15s ease}
footer a:hover{color:#111}
footer a:hover svg{transform:scale(1.1)}
footer a .sep{color:#ccc}
</style>
</head>
<body>
<h1>图床</h1>
<p class="sub">上传图片，得到可复制的直链 / HTML / Markdown</p>

<div class="drop" id="drop">
  <div class="big">点击或拖拽图片到此处</div>
  <div class="hint">支持 jpg / png / gif / webp，单张 ≤ 10M</div>
</div>
<div id="ts" style="margin-top:16px;display:flex;justify-content:center"></div>
<script>
var tsToken='', tsWidget=null;
function tsLoad(){ tsWidget = turnstile.render('#ts', {sitekey:'<?php echo htmlspecialchars(TS_SITEKEY, ENT_QUOTES); ?>', callback:function(t){tsToken=t;}, 'expired-callback':function(){tsToken='';}}); }
</script>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js?onload=tsLoad" async defer></script>
<input type="file" id="file" accept="image/*" hidden>
<div id="status"></div>

<div id="result">
  <img id="preview" alt="">
  <div class="fmt"><span class="lb">URL</span><input id="uUrl" readonly><button onclick="cp('uUrl')">复制</button></div>
  <div class="fmt"><span class="lb">HTML</span><input id="uHtml" readonly><button onclick="cp('uHtml')">复制</button></div>
  <div class="fmt"><span class="lb">MD</span><input id="uMd" readonly><button onclick="cp('uMd')">复制</button></div>
</div>

<h2>最近图片</h2>
<div class="grid" id="grid"><div class="empty">还没有图片</div></div>

<script>
const drop=document.getElementById('drop'),file=document.getElementById('file'),status=document.getElementById('status');
const KEY='imgbed_history';
let hist=[];try{hist=JSON.parse(localStorage.getItem(KEY)||'[]')}catch(e){}

drop.onclick=()=>file.click();
drop.ondragover=e=>{e.preventDefault();drop.classList.add('over')};
drop.ondragleave=()=>drop.classList.remove('over');
drop.ondrop=e=>{e.preventDefault();drop.classList.remove('over');if(e.dataTransfer.files[0])up(e.dataTransfer.files[0])};
file.onchange=()=>{if(file.files[0])up(file.files[0])};

function up(f){
  if(f.size>10*1024*1024){status.textContent='超过 10M';return}
  if(!tsToken){status.textContent='请先完成人机验证';return}
  status.textContent='上传中…';
  const fd=new FormData();fd.append('file',f);fd.append('cf-turnstile-response',tsToken);
  fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    if(tsWidget!==null){turnstile.reset(tsWidget);tsToken='';}
    if(!d.ok){status.textContent='失败：'+d.err;return}
    status.textContent='';
    hist=[{url:d.url,name:d.orig||d.name}].concat(hist.filter(h=>h.url!==d.url));
    if(hist.length>60)hist=hist.slice(0,60);
    localStorage.setItem(KEY,JSON.stringify(hist));
    showResult(d.url,d.orig||d.name);
    render();
  }).catch(e=>{status.textContent='失败：'+e});
}
function showResult(url,name){
  document.getElementById('result').style.display='block';
  document.getElementById('preview').src=url;
  document.getElementById('uUrl').value=url;
  document.getElementById('uHtml').value='<img src="'+url+'">';
  document.getElementById('uMd').value='![]('+url+')';
}
function cp(id){const el=document.getElementById(id);el.select();navigator.clipboard.writeText(el.value);el.blur();status.textContent='已复制'}
function render(){
  const g=document.getElementById('grid');
  g.innerHTML='';
  if(!hist.length){g.innerHTML='<div class="empty">还没有图片</div>';return}
  hist.forEach(h=>{
    const d=document.createElement('div');d.className='item';
    const img=document.createElement('img');img.src=h.url;img.loading='lazy';img.onclick=()=>showResult(h.url,h.name);
    d.append(img);g.append(d);
  });
}
render();
</script>

<footer><a href="https://github.com/cleeate/php-imgbed" target="_blank" rel="noopener" title="查看源码"><svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27s1.36.09 2 .27c1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0 0 16 8c0-4.42-3.58-8-8-8z"/></svg><span class="sep">·</span><span>源码仓库</span></a></footer>
</body>
</html>
