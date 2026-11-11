<?php
/* 极简图床 - 单文件无依赖 */
define('DIR', __DIR__ . '/uploads');
define('MAX', 10 * 1024 * 1024);
$ALLOWED = ['jpg'=>'jpg','jpeg'=>'jpg','png'=>'png','gif'=>'gif','webp'=>'webp'];

// 上传接口
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
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
footer{margin-top:44px;padding-top:16px;border-top:1px solid #eee;color:#888;font-size:12px;text-align:center}
footer a{color:#555;text-decoration:none}
footer a:hover{color:#111}
</style>
</head>
<body>
<h1>图床</h1>
<p class="sub">上传图片，得到可复制的直链 / HTML / Markdown</p>

<div class="drop" id="drop">
  <div class="big">点击或拖拽图片到此处</div>
  <div class="hint">支持 jpg / png / gif / webp，单张 ≤ 10M</div>
</div>
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
  status.textContent='上传中…';
  const fd=new FormData();fd.append('file',f);
  fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
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

<footer>源码仓库 · <a href="https://github.com/cleeate/php-s3-lite" target="_blank" rel="noopener">github.com/cleeate/php-s3-lite</a></footer>
</body>
</html>
