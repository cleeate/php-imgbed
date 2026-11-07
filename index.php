<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>php-s3-lite 面板</title>
<style>
body{font-family:system-ui,-apple-system,"PingFang SC","Microsoft YaHei",sans-serif;max-width:720px;margin:0 auto;padding:20px 16px 48px;color:#222;line-height:1.6}
h1{font-size:20px;margin:0 0 4px}h2{font-size:16px;margin:28px 0 8px;border-top:1px solid #eee;padding-top:16px}
p{margin:6px 0}.muted{color:#666;font-size:13px}code{background:#f4f4f4;padding:1px 5px;border-radius:4px;font-size:13px}
pre{background:#f6f6f6;padding:10px 12px;border-radius:6px;overflow:auto;font-size:13px;line-height:1.5;margin:8px 0}
label{display:block;font-size:13px;color:#555;margin:10px 0 4px}
input[type=text],input[type=password]{width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #ccc;border-radius:6px;font-size:14px}
button{padding:8px 16px;border:0;border-radius:6px;background:#111;color:#fff;font-size:14px;cursor:pointer}
button:disabled{opacity:.5;cursor:default}.ghost{background:#eee;color:#222}
#drop{border:1px dashed #aaa;border-radius:8px;padding:22px;text-align:center;color:#555;margin-top:8px}
#drop.over{border-color:#111;background:#fafafa}
.item{display:flex;gap:10px;align-items:center;border-top:1px solid #eee;padding:10px 0}
.item img{width:64px;height:64px;object-fit:cover;border-radius:6px;background:#f4f4f4}
.item .info{flex:1;min-width:0}.item .name{font-size:14px;word-break:break-all}
.item .st{font-size:13px;color:#666}.item .st.ok{color:#0a7d2c}.item .st.err{color:#c00}
.item .url{font-size:13px;word-break:break-all}.item .url a{color:#111}
.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
</style>
</head>
<body>
<h1>php-s3-lite 面板</h1>
<p class="muted">S3 兼容存储 · <code>https://gofor.hyperphp.com</code> · 上传直接走 S3 签名，密钥只留在本机浏览器，不经过本面板服务器。</p>

<h2>上传图片</h2>
<label>Endpoint</label>
<input type="text" id="endpoint" value="https://gofor.hyperphp.com">
<label>Bucket</label>
<input type="text" id="bucket" value="rin">
<label>目录前缀</label>
<input type="text" id="prefix" value="images/">
<label>Access Key</label>
<input type="text" id="ak" autocomplete="off" placeholder="s3-config.php 里的 access_key">
<label>Secret Key</label>
<input type="password" id="sk" autocomplete="off" placeholder="s3-config.php 里的 secret_key">
<p class="row"><label style="margin:0"><input type="checkbox" id="remember"> 记住密钥（仅存本机浏览器 localStorage）</label>
<button class="ghost" id="clearKeys" type="button">清除已存密钥</button></p>
<div id="drop">把图片拖到这里，或 <button class="ghost" id="pickBtn" type="button">选择文件</button><input type="file" id="fileInput" accept="image/*,video/*" multiple hidden></div>
<p class="muted">单文件 ≤ 10 MB；仅图片 / 视频扩展名（jpg、png、gif、webp、svg、avif、mp4、webm、mov）。该主机可能拦截 webp / mp4，推荐 jpg、png。</p>
<p><button id="uploadBtn" type="button" disabled>上传</button> <span id="summary" class="muted"></span></p>
<div id="list"></div>

<h2>对接帮助</h2>
<p>Endpoint <code>https://gofor.hyperphp.com</code> · Bucket <code>rin</code> · Region <code>auto</code> · Path-style <code>true</code> · 目录 <code>images/</code></p>
<p>公开地址没有 bucket：<code>https://gofor.hyperphp.com/images/xxx.jpg</code>，无需签名直接打开。</p>
<p><b>Rin 配置</b>（密钥放 Secrets，其余放 Variables，改完重新部署 Rin）</p>
<pre id="rinCfg">S3_ENDPOINT = https://gofor.hyperphp.com
S3_ACCESS_HOST = https://gofor.hyperphp.com
S3_BUCKET = rin
S3_REGION = auto
S3_FORCE_PATH_STYLE = true
S3_FOLDER = images/
S3_ACCESS_KEY_ID = &lt;access_key&gt;
S3_SECRET_ACCESS_KEY = &lt;secret_key&gt;</pre>
<p><button class="ghost" type="button" data-copy="rinCfg">复制 Rin 配置</button></p>
<p><b>aws-cli</b></p>
<pre id="awsCfg">export AWS_ACCESS_KEY_ID=&lt;access_key&gt;
export AWS_SECRET_ACCESS_KEY=&lt;secret_key&gt;
aws --endpoint-url https://gofor.hyperphp.com s3 cp photo.jpg s3://rin/images/photo.jpg
curl https://gofor.hyperphp.com/images/photo.jpg -o photo.jpg</pre>
<p><button class="ghost" type="button" data-copy="awsCfg">复制 aws-cli 命令</button></p>
<p class="muted">限制：单次 PUT ≤ 10 MB，大文件走分片（上限 100 MB）；ListObjects 在该免费主机上会被浏览器验证拦截，不可用；不要把密钥发给别人或贴到公开处，泄露后去服务器改 s3-config.php 换一对。</p>

<script>
"use strict";
const $=id=>document.getElementById(id);
const enc=new TextEncoder();
const ALLOWED=["jpg","jpeg","png","gif","webp","svg","avif","mp4","webm","mov"];
const MAX_BYTES=10*1024*1024;
let files=[];

// --- saved keys (localStorage only, never sent to server except as HMAC key in browser) ---
try{
  const saved=JSON.parse(localStorage.getItem("s3panel")||"null");
  if(saved){$("ak").value=saved.ak||"";$("sk").value=saved.sk||"";$("remember").checked=true;}
}catch(e){}
function saveKeys(){
  try{
    if($("remember").checked) localStorage.setItem("s3panel",JSON.stringify({ak:$("ak").value,sk:$("sk").value}));
    else localStorage.removeItem("s3panel");
  }catch(e){}
}
$("clearKeys").onclick=()=>{try{localStorage.removeItem("s3panel")}catch(e){}$("ak").value="";$("sk").value="";$("remember").checked=false;};
document.querySelectorAll("[data-copy]").forEach(b=>b.onclick=async()=>{
  const t=$(b.dataset.copy).innerText;
  try{await navigator.clipboard.writeText(t);b.textContent="已复制";setTimeout(()=>b.textContent=b.dataset.copy==="rinCfg"?"复制 Rin 配置":"复制 aws-cli 命令",1500);}
  catch(e){const r=document.createRange();r.selectNodeContents($(b.dataset.copy));const s=getSelection();s.removeAllRanges();s.addRange(r);}
});

// --- file picking ---
function addFiles(list){
  for(const f of list){
    const ext=(f.name.split(".").pop()||"").toLowerCase();
    if(!ALLOWED.includes(ext)){alert(f.name+"：扩展名不在白名单");continue;}
    if(f.size>MAX_BYTES){alert(f.name+"：超过 10 MB");continue;}
    if(f.size===0){alert(f.name+"：空文件");continue;}
    files.push(f);
  }
  render();
}
$("pickBtn").onclick=()=>$("fileInput").click();
$("fileInput").onchange=e=>{addFiles(e.target.files);e.target.value="";};
const drop=$("drop");
["dragover","dragenter"].forEach(ev=>drop.addEventListener(ev,e=>{e.preventDefault();drop.classList.add("over");}));
["dragleave","drop"].forEach(ev=>drop.addEventListener(ev,e=>{e.preventDefault();drop.classList.remove("over");}));
drop.addEventListener("drop",e=>addFiles(e.dataTransfer.files));

function sanitize(name){
  return name.replace(/[^\w.\-]+/g,"-").replace(/-+/g,"-").replace(/^\.+|\.+$/g,"")||"file";
}
function keyFor(f){
  let p=$("prefix").value.trim();
  if(p&&!p.endsWith("/"))p+="/";
  p=p.replace(/^\/+/,"");
  return p+sanitize(f.name);
}
function render(){
  $("uploadBtn").disabled=files.length===0;
  $("list").innerHTML="";
  files.forEach((f,i)=>{
    const div=document.createElement("div");div.className="item";
    const url=URL.createObjectURL(f);
    div.innerHTML='<img alt=""><div class="info"><div class="name"></div><div class="st"></div><div class="url"></div></div>';
    div.querySelector("img").src=url;
    div.querySelector(".name").textContent=f.name+"（"+(f.size/1024).toFixed(0)+" KB）→ "+keyFor(f);
    div.querySelector(".st").textContent="待上传";
    div.id="item-"+i;
    $("list").appendChild(div);
  });
}

// --- SigV4 (UNSIGNED-PAYLOAD, s3.php 接受；不算 body hash，大文件也不用先读两遍) ---
async function sha256Hex(data){
  const d=await crypto.subtle.digest("SHA-256",typeof data==="string"?enc.encode(data):data);
  return [...new Uint8Array(d)].map(b=>b.toString(16).padStart(2,"0")).join("");
}
async function hmac(key,data){
  const k=await crypto.subtle.importKey("raw",typeof key==="string"?enc.encode(key):key,{name:"HMAC",hash:"SHA-256"},false,["sign"]);
  const s=await crypto.subtle.sign("HMAC",k,typeof data==="string"?enc.encode(data):data);
  return new Uint8Array(s);
}
async function signPut(endpoint,bucket,key,ak,sk,contentType){
  const region="auto";
  const now=new Date();
  const amzDate=now.toISOString().slice(0,19).replace(/[-:]/g,"")+"Z";
  const dateStamp=amzDate.slice(0,8);
  const host=new URL(endpoint).host;
  const canonicalUri="/"+encodeURIComponent(bucket)+"/"+key.split("/").map(encodeURIComponent).join("/");
  const signedHeaders="host;x-amz-content-sha256;x-amz-date";
  const canonicalHeaders="host:"+host+"\nx-amz-content-sha256:UNSIGNED-PAYLOAD\nx-amz-date:"+amzDate+"\n";
  const canonicalRequest="PUT\n"+canonicalUri+"\n\n"+canonicalHeaders+"\n"+signedHeaders+"\nUNSIGNED-PAYLOAD";
  const scope=dateStamp+"/"+region+"/s3/aws4_request";
  const stringToSign="AWS4-HMAC-SHA256\n"+amzDate+"\n"+scope+"\n"+await sha256Hex(canonicalRequest);
  let k=await hmac("AWS4"+sk,dateStamp);
  k=await hmac(k,region);k=await hmac(k,"s3");k=await hmac(k,"aws4_request");
  const sig=[...await hmac(k,stringToSign)].map(b=>b.toString(16).padStart(2,"0")).join("");
  const url=endpoint.replace(/\/+$/,"")+canonicalUri;
  const headers={"X-Amz-Date":amzDate,"X-Amz-Content-Sha256":"UNSIGNED-PAYLOAD",
    "Authorization":"AWS4-HMAC-SHA256 Credential="+ak+"/"+scope+", SignedHeaders="+signedHeaders+", Signature="+sig};
  if(contentType)headers["Content-Type"]=contentType;
  return {url,headers};
}

$("uploadBtn").onclick=async()=>{
  const endpoint=$("endpoint").value.trim().replace(/\/+$/,"");
  const bucket=$("bucket").value.trim();
  const ak=$("ak").value.trim(),sk=$("sk").value;
  if(!/^https?:\/\//.test(endpoint)){alert("Endpoint 填 https:// 开头的地址");return;}
  if(!bucket||!ak||!sk){alert("Bucket、Access Key、Secret Key 都要填");return;}
  saveKeys();
  $("uploadBtn").disabled=true;$("summary").textContent="上传中…";
  let ok=0;
  for(let i=0;i<files.length;i++){
    const f=files[i],key=keyFor(f);
    const st=document.querySelector("#item-"+i+" .st");
    const urlBox=document.querySelector("#item-"+i+" .url");
    st.textContent="上传中…";st.className="st";
    try{
      const {url,headers}=await signPut(endpoint,bucket,key,ak,sk,f.type||"application/octet-stream");
      const res=await fetch(url,{method:"PUT",headers,body:f});
      if(!res.ok){
        const txt=await res.text();
        const m=txt.match(/<Message>([^<]*)<\/Message>/)||txt.match(/<Code>([^<]*)<\/Code>/);
        throw new Error("HTTP "+res.status+" "+(m?m[1]:txt.slice(0,120)));
      }
      const pub=endpoint+"/"+key.split("/").map(encodeURIComponent).join("/");
      st.textContent="已上传";st.className="st ok";
      urlBox.innerHTML="";
      const a=document.createElement("a");a.href=pub;a.target="_blank";a.rel="noopener";a.textContent=pub;urlBox.appendChild(a);
      const cp=document.createElement("button");cp.className="ghost";cp.style.marginLeft="6px";cp.textContent="复制链接";
      cp.onclick=async()=>{try{await navigator.clipboard.writeText(pub);cp.textContent="已复制";}catch(e){}};
      urlBox.appendChild(cp);
      ok++;
    }catch(e){
      st.textContent="失败："+(e.message||e);st.className="st err";
    }
  }
  $("summary").textContent="完成："+ok+"/"+files.length+" 成功";
  $("uploadBtn").disabled=false;
};
render();
</script>
</body>
</html>
