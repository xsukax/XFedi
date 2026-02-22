<?php
/**
 * XFedi – Single-File Federated Social Media Platform
 *
 * @license  GPL-3.0-or-later  https://www.gnu.org/licenses/gpl-3.0.html
 * @author   xsukax
 * @version  2.5.0
 */

define('APP_NAME',    'XFedi');
define('APP_VER',     '2.5.0');
define('DB_PATH',     __DIR__ . '/xfedi.db');
define('UPLOAD_DIR',  __DIR__ . '/uploads/');
define('MAX_CHARS',   500);
define('PPP',         20);
define('FED_TIMEOUT', 8);
define('DEF_PASS',    'admin@123');

session_start();

// ── Database ──────────────────────────────────────────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;');
    return $pdo;
}

function initDB(): void {
    db()->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY, username TEXT UNIQUE NOT NULL, name TEXT NOT NULL,
            password TEXT NOT NULL, bio TEXT DEFAULT '', avatar TEXT DEFAULT '',
            cover TEXT DEFAULT '', links TEXT DEFAULT '[]',
            created_at INTEGER DEFAULT (strftime('%s','now'))
        );
        CREATE TABLE IF NOT EXISTS posts (
            id INTEGER PRIMARY KEY, type TEXT NOT NULL CHECK(type IN ('text','image')),
            content TEXT NOT NULL, image_path TEXT DEFAULT '', likes INTEGER DEFAULT 0,
            created_at INTEGER DEFAULT (strftime('%s','now')),
            updated_at INTEGER DEFAULT (strftime('%s','now'))
        );
        CREATE TABLE IF NOT EXISTS likes (
            id INTEGER PRIMARY KEY, post_id INTEGER NOT NULL, actor TEXT NOT NULL,
            created_at INTEGER DEFAULT (strftime('%s','now')), UNIQUE(post_id, actor)
        );
        CREATE TABLE IF NOT EXISTS comments (
            id INTEGER PRIMARY KEY, post_id INTEGER NOT NULL, author TEXT NOT NULL,
            content TEXT NOT NULL, is_remote INTEGER DEFAULT 0,
            created_at INTEGER DEFAULT (strftime('%s','now'))
        );
        CREATE TABLE IF NOT EXISTS follows (
            id INTEGER PRIMARY KEY, handle TEXT UNIQUE NOT NULL,
            name TEXT DEFAULT '', avatar TEXT DEFAULT '',
            created_at INTEGER DEFAULT (strftime('%s','now'))
        );
        CREATE TABLE IF NOT EXISTS followers (
            id INTEGER PRIMARY KEY, handle TEXT UNIQUE NOT NULL,
            name TEXT DEFAULT '', avatar TEXT DEFAULT '',
            created_at INTEGER DEFAULT (strftime('%s','now'))
        );
        CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY, type TEXT NOT NULL, actor TEXT NOT NULL,
            post_id INTEGER, content TEXT DEFAULT '', is_read INTEGER DEFAULT 0,
            created_at INTEGER DEFAULT (strftime('%s','now'))
        );
        CREATE TABLE IF NOT EXISTS feed_cache (
            id INTEGER PRIMARY KEY, handle TEXT NOT NULL, remote_id TEXT NOT NULL,
            post_json TEXT NOT NULL, fetched_at INTEGER DEFAULT (strftime('%s','now')),
            UNIQUE(handle, remote_id)
        );
    ");
}

function hasUser(): bool { return (bool) db()->query("SELECT COUNT(*) FROM users")->fetchColumn(); }

// ── Helpers ───────────────────────────────────────────────────────────────────
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function linkify(string $text): string {
    $parts = preg_split('#(https?://\S+)#i', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    foreach ($parts as $i => $part) {
        if ($i % 2 === 0) { $out .= h($part); }
        else {
            $url = rtrim($part, '.,;:!?)\'"}>');
            $d   = mb_strlen($url) > 55 ? mb_substr($url, 0, 52) . '…' : $url;
            $out .= '<a href="'.h($url).'" target="_blank" rel="noopener noreferrer">'.h($d).'</a>';
        }
    }
    return $out;
}

function baseURL(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
          || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    return ($https ? 'https' : 'http').'://'.($_SERVER['HTTP_HOST'] ?? 'localhost').($_SERVER['SCRIPT_NAME'] ?? '/xfedi.php');
}

function getUser(bool $fresh = false): ?array {
    static $cache = null;
    if ($fresh || $cache === null) $cache = db()->query("SELECT * FROM users LIMIT 1")->fetch() ?: null;
    return $cache;
}

function isLoggedIn(): bool { return isset($_SESSION['auth']) && $_SESSION['auth'] === true; }

function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function checkCsrf(): bool { return hash_equals($_SESSION['csrf'] ?? '', $_POST['_csrf'] ?? ''); }

function json(array $d, int $c = 200): never {
    http_response_code($c);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function redir(string $u): never { header("Location: $u"); exit; }

function ago(int $ts): string {
    $d = max(0, time() - $ts);
    if ($d < 60)     return "{$d}s";
    if ($d < 3600)   return floor($d/60).'m';
    if ($d < 86400)  return floor($d/3600).'h';
    if ($d < 604800) return floor($d/86400).'d';
    return date('M j, Y', $ts);
}

function timeEl(int $ts): string {
    return '<time datetime="'.date('c', $ts).'" title="'.date('F j, Y g:i a', $ts).'">'.ago($ts).'</time>';
}

function selfHandle(): string {
    $u = getUser(); if (!$u) return '';
    $url = baseURL();
    return '@'.$u['username'].'@'.(parse_url($url, PHP_URL_HOST) ?? 'localhost').(parse_url($url, PHP_URL_PATH) ?? '/xfedi.php');
}

function ensureUploads(): void {
    if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0755, true);
    $ht = UPLOAD_DIR.'.htaccess';
    if (!file_exists($ht)) file_put_contents($ht, "Options -Indexes\nphp_flag engine off\n");
}

function safeUpload(array $file, string $prefix, int $maxBytes = 5242880): ?string {
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > $maxBytes) return null;
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, ['image/jpeg','image/png','image/gif','image/webp'], true)) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','gif','webp'], true)) return null;
    $name = $prefix.'_'.bin2hex(random_bytes(8)).'.'.$ext;
    return move_uploaded_file($file['tmp_name'], UPLOAD_DIR.$name) ? $name : null;
}

function avatarEl(string $avatarUrl, string $name, int $size = 40): string {
    $initials = h(mb_strtoupper(mb_substr($name ?: '?', 0, 1)));
    $fs = floor($size * 0.4);
    if ($avatarUrl) {
        return '<img src="'.h($avatarUrl).'" class="avatar" width="'.$size.'" height="'.$size.'" alt="'.h($name).'"'
             .' onerror="this.outerHTML=\'<div class=\\\'avatar-placeholder\\\' style=\\\'width:'.$size.'px;height:'.$size.'px;font-size:'.$fs.'px\\\'>'.$initials.'</div>\'">';
    }
    return '<div class="avatar-placeholder" style="width:'.$size.'px;height:'.$size.'px;font-size:'.$fs.'px" aria-label="'.h($name).'">'.$initials.'</div>';
}

function resolveCommentAuthor(array $comment, string $myAvatarUrl, string $base): array {
    if (!$comment['is_remote']) {
        return ['avatar' => $myAvatarUrl, 'profile_url' => $base.'?page=profile', 'display' => $comment['author']];
    }
    if (preg_match('/^@[^@\s]+@[^\s]+$/', $comment['author'])) {
        $fq = db()->prepare("SELECT avatar FROM follows WHERE handle=?");
        $fq->execute([$comment['author']]);
        $fr = $fq->fetch();
        return ['avatar' => $fr['avatar'] ?? '', 'profile_url' => $base.'?page=remote_profile&handle='.urlencode($comment['author']), 'display' => $comment['author']];
    }
    return ['avatar' => '', 'profile_url' => '', 'display' => $comment['author']];
}

function textSnippet(string $text, int $len = 160): string {
    $plain = strip_tags($text);
    return mb_strlen($plain) > $len ? mb_substr($plain, 0, $len) . '…' : $plain;
}

// ── Federation ────────────────────────────────────────────────────────────────
function parseHandle(string $h): ?array {
    if (!preg_match('/^@([^@\s]+)@([^\s]+)$/', trim($h), $m)) return null;
    return ['username' => $m[1], 'url_https' => 'https://'.$m[2], 'url_http' => 'http://'.$m[2], 'handle' => $h];
}

function fedFetch(string $url, string $method = 'GET', ?array $body = null): ?array {
    static $cache = [];
    $key = $method.':'.$url;
    if ($method === 'GET' && isset($cache[$key])) return $cache[$key];
    $opts = ['http' => [
        'timeout' => FED_TIMEOUT, 'ignore_errors' => true, 'follow_location' => 1, 'max_redirects' => 3,
        'header'  => "Accept: application/json\r\nContent-Type: application/json\r\nUser-Agent: XFedi/".APP_VER."\r\n",
        'method'  => $method,
    ]];
    if ($body !== null) $opts['http']['content'] = json_encode($body);
    $raw  = @file_get_contents($url, false, stream_context_create($opts));
    $data = $raw ? json_decode($raw, true) : null;
    if ($method === 'GET' && is_array($data)) $cache[$key] = $data;
    return is_array($data) ? $data : null;
}

function fedGet(array $parsed, string $query): ?array {
    return fedFetch($parsed['url_https'].'?'.$query) ?? fedFetch($parsed['url_http'].'?'.$query);
}

function fedPost(array $parsed, string $query, array $body): ?array {
    return fedFetch($parsed['url_https'].'?'.$query, 'POST', $body) ?? fedFetch($parsed['url_http'].'?'.$query, 'POST', $body);
}

function refreshFeedCache(): void {
    $handles = db()->query("SELECT handle FROM follows ORDER BY RANDOM() LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($handles as $handle) {
        $parsed = parseHandle($handle); if (!$parsed) continue;
        $data   = fedGet($parsed, "api=posts&limit=20");
        if (!$data || empty($data['posts'])) continue;
        $ins = db()->prepare("INSERT OR IGNORE INTO feed_cache (handle,remote_id,post_json) VALUES (?,?,?)");
        $upd = db()->prepare("UPDATE feed_cache SET post_json=?, fetched_at=strftime('%s','now') WHERE handle=? AND remote_id=?");
        $chk = db()->prepare("SELECT post_json FROM feed_cache WHERE handle=? AND remote_id=?");
        foreach ($data['posts'] as $p) {
            if (!isset($p['id'])) continue;
            $rid = (string)$p['id'];
            $chk->execute([$handle, $rid]); $existing = $chk->fetch();
            if ($existing) {
                $old = json_decode($existing['post_json'], true) ?? [];
                if (($old['likes']??0)!==($p['likes']??0)||($old['content']??'')!==($p['content']??'')||($old['updated_at']??0)!==($p['updated_at']??0))
                    $upd->execute([json_encode($p), $handle, $rid]);
            } else { $ins->execute([$handle, $rid, json_encode($p)]); }
        }
        if (!empty($data['profile']))
            db()->prepare("UPDATE follows SET name=?,avatar=? WHERE handle=?")->execute([$data['profile']['name']??'', $data['profile']['avatar']??'', $handle]);
    }
}

// ── Federation API (public) ───────────────────────────────────────────────────
if (isset($_GET['api'])) {
    initDB();
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
    $u = getUser(); $base = baseURL();
    $host = parse_url($base, PHP_URL_HOST); $path = parse_url($base, PHP_URL_PATH);

    match ($_GET['api']) {
        'profile' => json(!$u ? ['error'=>'no user'] : [
            'handle'          => '@'.$u['username'].'@'.$host.$path,
            'username'        => $u['username'], 'name' => $u['name'], 'bio' => $u['bio'],
            'avatar'          => $u['avatar'] ? $base.'?file=avatar' : '',
            'cover'           => $u['cover']  ? $base.'?file=cover'  : '',
            'links'           => json_decode($u['links'] ?: '[]', true),
            'followers_count' => (int)db()->query("SELECT COUNT(*) FROM followers")->fetchColumn(),
            'following_count' => (int)db()->query("SELECT COUNT(*) FROM follows")->fetchColumn(),
            'instance'        => $base, 'version' => APP_VER,
        ]),
        'followers' => (function () {
            $limit = min((int)($_GET['limit']??20),50);
            json(['followers' => db()->query("SELECT handle,name,avatar,created_at FROM followers ORDER BY created_at DESC LIMIT $limit")->fetchAll(), 'count' => (int)db()->query("SELECT COUNT(*) FROM followers")->fetchColumn()]);
        })(),
        'posts' => (function () use ($base, $u) {
            $since = (int)($_GET['since']??0); $limit = min((int)($_GET['limit']??20),50);
            $rows  = db()->prepare("SELECT * FROM posts WHERE created_at > ? ORDER BY created_at DESC LIMIT ?");
            $rows->execute([$since,$limit]); $posts = $rows->fetchAll();
            foreach ($posts as &$p) {
                $p['image_url']   = $p['image_path'] ? $base.'?file=post_img&id='.$p['id'] : '';
                $p['author']      = $u ? ['username'=>$u['username'],'name'=>$u['name'],'avatar'=>$u['avatar']?$base.'?file=avatar':''] : [];
                $cs = db()->prepare("SELECT COUNT(*) FROM comments WHERE post_id=?"); $cs->execute([$p['id']]); $p['comment_count'] = (int)$cs->fetchColumn();
                unset($p['image_path']);
            }
            json(['posts'=>$posts,'profile'=>['name'=>$u['name']??'','avatar'=>$u['avatar']?$base.'?file=avatar':'']]);
        })(),
        'post' => (function () use ($base) {
            $id = (int)($_GET['id']??0);
            $s  = db()->prepare("SELECT * FROM posts WHERE id=?"); $s->execute([$id]); $p = $s->fetch();
            if (!$p) json(['error'=>'not found'],404);
            $p['image_url'] = $p['image_path'] ? $base.'?file=post_img&id='.$p['id'] : '';
            unset($p['image_path']);
            $cs = db()->prepare("SELECT * FROM comments WHERE post_id=? ORDER BY created_at ASC"); $cs->execute([$id]);
            $p['comments'] = $cs->fetchAll();
            json($p);
        })(),
        'interact' => (function () {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json(['error'=>'POST required'],405);
            $b = json_decode(file_get_contents('php://input'),true);
            if (!$b) json(['error'=>'bad body'],400);
            $type   = $b['type']??'';
            $postId = (int)($b['post_id']??0);
            $actor  = substr(preg_replace('/[^\w@.\-\/]/','', $b['actor']??'anon'),0,200);
            try {
                if ($type==='like') {
                    db()->prepare("INSERT OR IGNORE INTO likes(post_id,actor) VALUES(?,?)")->execute([$postId,$actor]);
                    db()->prepare("UPDATE posts SET likes=(SELECT COUNT(*) FROM likes WHERE post_id=?) WHERE id=?")->execute([$postId,$postId]);
                    db()->prepare("INSERT INTO notifications(type,actor,post_id) VALUES('like',?,?)")->execute([$actor,$postId]);
                    json(['ok'=>true,'likes'=>(int)db()->query("SELECT likes FROM posts WHERE id=$postId")->fetchColumn()]);
                }
                if ($type==='comment') {
                    $c = substr(strip_tags($b['content']??''),0,500);
                    if (!$c) json(['error'=>'empty'],400);
                    db()->prepare("INSERT INTO comments(post_id,author,content,is_remote) VALUES(?,?,?,1)")->execute([$postId,$actor,$c]);
                    db()->prepare("INSERT INTO notifications(type,actor,post_id,content) VALUES('comment',?,?,?)")->execute([$actor,$postId,$c]);
                    $cs = db()->prepare("SELECT * FROM comments WHERE post_id=? ORDER BY created_at ASC"); $cs->execute([$postId]);
                    json(['ok'=>true,'comments'=>$cs->fetchAll()]);
                }
                if ($type==='follow') {
                    $name   = substr(strip_tags($b['name']??''),0,100);
                    $avatar = filter_var($b['avatar']??'',FILTER_VALIDATE_URL)?$b['avatar']:'';
                    db()->prepare("INSERT OR IGNORE INTO followers(handle,name,avatar) VALUES(?,?,?)")->execute([$actor,$name,$avatar]);
                    db()->prepare("UPDATE followers SET name=?,avatar=? WHERE handle=?")->execute([$name,$avatar,$actor]);
                    db()->prepare("INSERT OR IGNORE INTO notifications(type,actor) VALUES('follow',?)")->execute([$actor]);
                    json(['ok'=>true]);
                }
                if ($type==='unfollow') {
                    db()->prepare("DELETE FROM followers WHERE handle=?")->execute([$actor]);
                    json(['ok'=>true]);
                }
            } catch (Exception $e) { json(['error'=>'db'],500); }
            json(['error'=>'unknown type'],400);
        })(),
        default => json(['error'=>'unknown endpoint'],404),
    };
}

// ── File serving (public) ─────────────────────────────────────────────────────
if (isset($_GET['file'])) {
    initDB(); $u = getUser(); $key = $_GET['file'];
    $serve = function (string $f) {
        $fp = UPLOAD_DIR.basename($f);
        if (!$f || !file_exists($fp)) { http_response_code(404); exit; }
        header('Content-Type: '.(mime_content_type($fp)?:'application/octet-stream'));
        header('Cache-Control: public, max-age=86400');
        readfile($fp); exit;
    };
    if ($key==='avatar') $serve($u['avatar']??'');
    if ($key==='cover')  $serve($u['cover']??'');
    if ($key==='post_img') {
        $s = db()->prepare("SELECT image_path FROM posts WHERE id=?"); $s->execute([(int)($_GET['id']??0)]);
        $serve($s->fetch()['image_path']??'');
    }
    http_response_code(404); exit;
}

// ── Sitemap (public) ──────────────────────────────────────────────────────────
if (isset($_GET['sitemap'])) {
    initDB();
    $u    = getUser();
    $base = baseURL();
    header('Content-Type: application/xml; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    $posts = db()->query("SELECT id, updated_at FROM posts ORDER BY created_at DESC LIMIT 500")->fetchAll();
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    echo '<url><loc>'.h($base).'</loc><changefreq>daily</changefreq><priority>1.0</priority></url>';
    if ($u) echo '<url><loc>'.h($base.'?page=profile').'</loc><changefreq>weekly</changefreq><priority>0.8</priority></url>';
    foreach ($posts as $p) {
        echo '<url><loc>'.h($base.'?page=post&id='.$p['id']).'</loc>';
        echo '<lastmod>'.date('Y-m-d', $p['updated_at']).'</lastmod>';
        echo '<changefreq>monthly</changefreq><priority>0.6</priority></url>';
    }
    echo '</urlset>';
    exit;
}

// ── Robots.txt (public) ───────────────────────────────────────────────────────
if (isset($_GET['robots'])) {
    $base = baseURL();
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: public, max-age=86400');
    echo "User-agent: *\nAllow: /\n";
    echo "Disallow: /?api=\nDisallow: /?file=\nDisallow: /?ajax=\n";
    echo "Disallow: /?page=settings\nDisallow: /?page=notifications\n";
    echo "Disallow: /?page=following\nDisallow: /?page=followers\nDisallow: /?page=login\n\n";
    echo "Sitemap: ".$base."?sitemap\n";
    exit;
}

// ── Init ──────────────────────────────────────────────────────────────────────
initDB(); ensureUploads();
$csrf_token = csrf();
$base       = baseURL();
$errors     = []; $info = '';
$isFirstRun = !hasUser();

if ($isFirstRun && ($_POST['action']??'')==='setup') {
    $uname = trim($_POST['username']??'');
    if (!preg_match('/^[a-z0-9_]{3,30}$/',$uname)) { $errors[] = 'Username must be 3–30 chars: lowercase, numbers, underscores.'; }
    else {
        db()->prepare("INSERT INTO users(username,name,password) VALUES(?,?,?)")->execute([$uname,$uname,password_hash(DEF_PASS,PASSWORD_DEFAULT)]);
        session_regenerate_id(true); $_SESSION['auth'] = true; redir($base);
    }
}
if ($isFirstRun) { $cu = null; goto render; }

// ── Actions ───────────────────────────────────────────────────────────────────
$act = $_POST['action']??''; $cu = getUser(true); $page = $_GET['page']??'home';

if ($act==='login') {
    if ($cu && trim($_POST['username']??'')===$cu['username'] && password_verify($_POST['password']??'',$cu['password'])) { session_regenerate_id(true); $_SESSION['auth']=true; redir($base); }
    $errors[] = 'Invalid username or password.';
    $page = 'login';
}
if ($act==='logout') { session_destroy(); redir($base); }

if ($act==='create_post' && isLoggedIn() && checkCsrf()) {
    $type    = in_array($_POST['post_type']??'',['text','image'])?$_POST['post_type']:'text';
    $content = mb_substr(trim($_POST['content']??''),0,MAX_CHARS);
    if (!$content) { $errors[] = 'Post cannot be empty.'; }
    $imgName = '';
    if ($type==='image' && !empty($_FILES['image']['name'])) {
        $n = safeUpload($_FILES['image'],'post',10485760);
        if (!$n) $errors[]='Invalid image (JPEG/PNG/GIF/WebP, max 10 MB).'; else $imgName=$n;
    }
    if (!$errors) { db()->prepare("INSERT INTO posts(type,content,image_path) VALUES(?,?,?)")->execute([$type,$content,$imgName]); redir($base); }
}

if ($act==='delete_post' && isLoggedIn() && checkCsrf()) {
    $id = (int)($_POST['post_id']??0);
    $s  = db()->prepare("SELECT image_path FROM posts WHERE id=?"); $s->execute([$id]); $row=$s->fetch();
    if ($row) {
        if ($row['image_path'] && file_exists(UPLOAD_DIR.$row['image_path'])) @unlink(UPLOAD_DIR.$row['image_path']);
        foreach (['posts','likes','comments'] as $t) db()->prepare("DELETE FROM $t WHERE ".($t==='posts'?'id':'post_id')."=?")->execute([$id]);
    }
    redir($base.'?page=profile');
}

if ($act==='edit_post' && isLoggedIn() && checkCsrf()) {
    $id = (int)($_POST['post_id']??0); $content = mb_substr(trim($_POST['content']??''),0,MAX_CHARS);
    if ($content) db()->prepare("UPDATE posts SET content=?,updated_at=strftime('%s','now') WHERE id=?")->execute([$content,$id]);
    redir($base.'?page=post&id='.$id);
}

if ($act==='like' && isLoggedIn() && checkCsrf()) {
    $id = (int)($_POST['post_id']??0); $actor='local:'.$cu['username'];
    db()->prepare("INSERT OR IGNORE INTO likes(post_id,actor) VALUES(?,?)")->execute([$id,$actor]);
    db()->prepare("UPDATE posts SET likes=(SELECT COUNT(*) FROM likes WHERE post_id=?) WHERE id=?")->execute([$id,$id]);
    redir($_SERVER['HTTP_REFERER']??$base);
}

if ($act==='unlike' && isLoggedIn() && checkCsrf()) {
    $id = (int)($_POST['post_id']??0); $actor='local:'.$cu['username'];
    db()->prepare("DELETE FROM likes WHERE post_id=? AND actor=?")->execute([$id,$actor]);
    db()->prepare("UPDATE posts SET likes=(SELECT COUNT(*) FROM likes WHERE post_id=?) WHERE id=?")->execute([$id,$id]);
    redir($_SERVER['HTTP_REFERER']??$base);
}

if ($act==='comment' && isLoggedIn() && checkCsrf()) {
    $id = (int)($_POST['post_id']??0); $text = mb_substr(trim($_POST['comment']??''),0,500);
    if ($text) db()->prepare("INSERT INTO comments(post_id,author,content) VALUES(?,?,?)")->execute([$id,$cu['username'],$text]);
    redir($base.'?page=post&id='.$id);
}

if ($act==='follow' && isLoggedIn() && checkCsrf()) {
    $fh = trim($_POST['handle']??'');
    if (preg_match('/^@[^@\s]+@[^\s]+$/',$fh)) {
        $p = parseHandle($fh); $prof = $p ? fedGet($p,'api=profile') : null;
        db()->prepare("INSERT OR IGNORE INTO follows(handle,name,avatar) VALUES(?,?,?)")->execute([$fh,$prof['name']??'',$prof['avatar']??'']);
        $myHandle=selfHandle(); $myAv=$cu['avatar']?$base.'?file=avatar':'';
        if ($p && $myHandle) fedPost($p,'api=interact',['type'=>'follow','actor'=>$myHandle,'name'=>$cu['name'],'avatar'=>$myAv]);
    }
    redir($_POST['_back']??$base.'?page=following');
}

if ($act==='unfollow' && isLoggedIn() && checkCsrf()) {
    $fh = trim($_POST['handle']??'');
    $p  = parseHandle($fh); $myHandle = selfHandle();
    if ($p && $myHandle) fedPost($p,'api=interact',['type'=>'unfollow','actor'=>$myHandle]);
    db()->prepare("DELETE FROM follows WHERE handle=?")->execute([$fh]);
    redir($_POST['_back']??$base.'?page=following');
}

if ($act==='update_profile' && isLoggedIn() && checkCsrf()) {
    $name  = mb_substr(trim($_POST['name']??''),0,100);
    $bio   = mb_substr(trim($_POST['bio']??''),0,300);
    $links = array_filter(array_map('trim',(array)($_POST['links']??[])),fn($l)=>$l&&filter_var($l,FILTER_VALIDATE_URL));
    $setCols=['name=?','bio=?','links=?']; $setVals=[$name,$bio,json_encode(array_values($links))];
    $cf = getUser(true);
    foreach (['avatar','cover'] as $field) {
        if (!empty($_FILES[$field]['name'])) {
            $n = safeUpload($_FILES[$field],$field);
            if ($n) { if ($cf[$field]&&file_exists(UPLOAD_DIR.$cf[$field])) @unlink(UPLOAD_DIR.$cf[$field]); $setCols[]="$field=?"; $setVals[]=$n; }
            else $errors[]=ucfirst($field).' upload failed.';
        }
    }
    if (!$errors&&$name) { $setVals[]=1; db()->prepare("UPDATE users SET ".implode(',',$setCols)." WHERE id=?")->execute($setVals); redir($base.'?page=profile&saved=1'); }
    $page='settings';
}

if ($act==='change_password' && isLoggedIn() && checkCsrf()) {
    $cf = getUser(true); $cur=$_POST['current']??''; $nw=$_POST['newpw']??''; $conf=$_POST['confirm']??'';
    if (!password_verify($cur,$cf['password']))  $errors[]='Current password is incorrect.';
    elseif (strlen($nw)<8) $errors[]='New password must be at least 8 characters.';
    elseif ($nw!==$conf)   $errors[]='Passwords do not match.';
    else { db()->prepare("UPDATE users SET password=? WHERE id=1")->execute([password_hash($nw,PASSWORD_DEFAULT)]); $info='Password updated successfully.'; }
    $page='settings';
}

if ($act==='refresh_feed' && isLoggedIn()) { refreshFeedCache(); json(['ok'=>true]); }

// ── AJAX feed (public) ────────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax']==='feed') {
    $cu      = getUser();
    $loggedIn = isLoggedIn();
    $actor   = ($loggedIn && $cu) ? 'local:'.$cu['username'] : '';
    $mode    = $_GET['mode']??'before';
    $before  = (int)($_GET['before']??9999999999);
    $after   = (int)($_GET['after']??0);

    if ($mode==='after') {
        $s = db()->prepare("SELECT * FROM posts WHERE created_at > ? ORDER BY created_at DESC");
        $s->execute([$after]);
    } else {
        $s = db()->prepare("SELECT * FROM posts WHERE created_at < ? ORDER BY created_at DESC LIMIT ?");
        $s->execute([$before, PPP]);
    }
    $local = $s->fetchAll();

    foreach ($local as &$p) {
        $p['_src']    = 'local';
        $p['_key']    = 'local:'.$p['id'];
        $p['_author'] = $cu ? ['username'=>$cu['username'],'name'=>$cu['name'],'avatar'=>$cu['avatar']?$base.'?file=avatar':''] : [];
        $p['image_url'] = $p['image_path']?$base.'?file=post_img&id='.$p['id']:'';
        $p['post_url']  = $base.'?page=post&id='.$p['id'];
        $p['_liked']    = false;
        if ($actor) {
            $ls = db()->prepare("SELECT COUNT(*) FROM likes WHERE post_id=? AND actor=?"); $ls->execute([$p['id'],$actor]); $p['_liked']=(bool)$ls->fetchColumn();
        }
        $cs = db()->prepare("SELECT COUNT(*) FROM comments WHERE post_id=?"); $cs->execute([$p['id']]); $p['_comments']=(int)$cs->fetchColumn();
        $lc = db()->prepare("SELECT author, content, created_at FROM comments WHERE post_id=? ORDER BY created_at DESC LIMIT 1"); $lc->execute([$p['id']]); $p['_last_comment']=$lc->fetch()?:null;
        unset($p['image_path']);
    }

    $rs = db()->prepare("SELECT fc.handle,fc.remote_id,fc.post_json,f.avatar AS follow_avatar FROM feed_cache fc LEFT JOIN follows f ON fc.handle=f.handle ORDER BY fc.fetched_at DESC LIMIT 200");
    $rs->execute();
    $remote=[]; $seenRemote=[];

    foreach ($rs->fetchAll() as $r) {
        $dedup = $r['handle'].':'.$r['remote_id'];
        if (isset($seenRemote[$dedup])) continue;
        $seenRemote[$dedup] = true;
        $p = json_decode($r['post_json'],true); if (!$p) continue;
        $ts = (int)($p['created_at']??0);
        if ($mode==='after'  && $ts<=$after)  continue;
        if ($mode==='before' && $ts>=$before) continue;
        if (!empty($r['follow_avatar'])&&isset($p['author'])) $p['author']['avatar']=$r['follow_avatar'];
        $parsed = parseHandle($r['handle']);
        $p['_src']         = 'remote';
        $p['_key']         = $r['handle'].':'.$r['remote_id'];
        $p['_handle']      = $r['handle'];
        $p['_liked']       = false;
        $p['_remote_base'] = $parsed?$parsed['url_https']:'';
        $p['post_url']     = $base.'?page=remote_post&handle='.urlencode($r['handle']).'&id='.$p['id'];
        $p['_profile_url'] = $base.'?page=remote_profile&handle='.urlencode($r['handle']);
        $p['_last_comment'] = null;
        $remote[] = $p;
    }

    $arr = array_merge($local,$remote);
    usort($arr, fn($a,$b)=>($b['created_at']??0)<=>($a['created_at']??0));

    if ($mode==='after') {
        json(['posts'=>$arr,'has_more'=>false,'is_refresh'=>true]);
    } else {
        json(['posts'=>array_slice($arr,0,PPP),'has_more'=>count($arr)>PPP]);
    }
}

// ── Page data ─────────────────────────────────────────────────────────────────
$cu = getUser(true); $page = $_GET['page']??'home';

// Gate private pages
$privatePages = ['settings','notifications','following','followers'];
if (!isLoggedIn() && in_array($page, $privatePages)) { redir($base.'?page=login'); }

$handle     = selfHandle();
$notifCount = isLoggedIn() ? (int)db()->query("SELECT COUNT(*) FROM notifications WHERE is_read=0")->fetchColumn() : 0;
$posts=[]; $follows=[]; $followers=[]; $notifications=[];
$singlePost=null; $comments=[];
$remoteProfileData=null; $remotePosts=[]; $remotePostData=null;
$remoteHandle=''; $remoteBase=''; $remoteParsed=null; $remoteFollowers=[];

$followingCount = (int)db()->query("SELECT COUNT(*) FROM follows")->fetchColumn();
$followersCount = (int)db()->query("SELECT COUNT(*) FROM followers")->fetchColumn();

if ($page==='profile')   $posts     = db()->query("SELECT * FROM posts ORDER BY created_at DESC")->fetchAll();
if ($page==='followers') $followers = db()->query("SELECT * FROM followers ORDER BY created_at DESC")->fetchAll();
if ($page==='post') {
    $pid = (int)($_GET['id']??0);
    $s=db()->prepare("SELECT * FROM posts WHERE id=?"); $s->execute([$pid]); $singlePost=$s->fetch();
    $s=db()->prepare("SELECT * FROM comments WHERE post_id=? ORDER BY created_at ASC"); $s->execute([$pid]); $comments=$s->fetchAll();
}
if ($page==='following') $follows = db()->query("SELECT * FROM follows ORDER BY created_at DESC")->fetchAll();
if ($page==='notifications') {
    $notifications = db()->query("SELECT * FROM notifications ORDER BY created_at DESC LIMIT 80")->fetchAll();
    db()->exec("UPDATE notifications SET is_read=1"); $notifCount=0;
}
if ($page==='remote_profile') {
    $remoteHandle = trim($_GET['handle']??''); $remoteParsed=$remoteHandle?parseHandle($remoteHandle):null;
    $activeTab    = $_GET['tab']??'posts';
    if ($remoteParsed) {
        $remoteBase=$remoteParsed['url_https']; $remoteProfileData=fedGet($remoteParsed,'api=profile');
        if ($activeTab==='posts') { $rpd=fedGet($remoteParsed,'api=posts&limit=20'); $remotePosts=$rpd['posts']??[]; }
        elseif ($activeTab==='followers') { $rfd=fedGet($remoteParsed,'api=followers&limit=50'); $remoteFollowers=$rfd['followers']??[]; }
    }
}
if ($page==='remote_post') {
    $remoteHandle = trim($_GET['handle']??''); $remotePostId=(int)($_GET['id']??0);
    $remoteParsed = $remoteHandle?parseHandle($remoteHandle):null;
    if ($remoteParsed && $remotePostId) {
        $remoteBase=$remoteParsed['url_https'];
        $remotePostData    = fedGet($remoteParsed,"api=post&id=$remotePostId");
        $remoteProfileData = fedGet($remoteParsed,'api=profile');
    }
}
if (isset($_GET['saved'])) $info='Profile saved successfully.';

// ── SEO meta computation ──────────────────────────────────────────────────────
$myAvatarUrl  = ($cu && $cu['avatar']) ? $base.'?file=avatar' : '';
$myCoverUrl   = ($cu && $cu['cover'])  ? $base.'?file=cover'  : '';
$seoTitle     = APP_NAME;
$seoDesc      = ($cu && $cu['bio']) ? $cu['bio'] : 'A federated social platform. Own your posts.';
$seoImage     = $myAvatarUrl;
$seoCanonical = $base;
$seoType      = 'website';
$seoRobots    = 'index,follow';
$seoJsonLd    = null;
$seoAuthor    = $cu ? $cu['name'] : APP_NAME;

if ($cu) {
    if ($page === 'home') {
        $seoTitle     = APP_NAME.' — '.$cu['name'];
        $seoDesc      = ($cu['bio'] ?: 'Public feed from '.$cu['name'].'\'s federated instance.');
        $seoJsonLd    = ['@context'=>'https://schema.org','@type'=>'Blog','name'=>APP_NAME,'url'=>$base,'author'=>['@type'=>'Person','name'=>$cu['name'],'url'=>$base.'?page=profile']];
    }
    if ($page === 'profile') {
        $seoTitle     = h($cu['name']).' (@'.h($cu['username']).') — '.APP_NAME;
        $seoDesc      = $cu['bio'] ?: 'Posts by '.$cu['name'].' on '.APP_NAME;
        $seoCanonical = $base.'?page=profile';
        $seoType      = 'profile';
        $seoJsonLd    = ['@context'=>'https://schema.org','@type'=>'ProfilePage','url'=>$seoCanonical,'mainEntity'=>['@type'=>'Person','name'=>$cu['name'],'description'=>$cu['bio']??'','url'=>$seoCanonical,'image'=>$myAvatarUrl]];
    }
    if ($page === 'post' && $singlePost) {
        $plain        = textSnippet($singlePost['content'], 160);
        $shortTitle   = textSnippet($singlePost['content'], 60);
        $seoTitle     = $shortTitle.' — '.APP_NAME;
        $seoDesc      = $plain;
        $seoCanonical = $base.'?page=post&id='.$singlePost['id'];
        $seoType      = 'article';
        if ($singlePost['image_path']) $seoImage = $base.'?file=post_img&id='.$singlePost['id'];
        $seoJsonLd    = ['@context'=>'https://schema.org','@type'=>'SocialMediaPosting','url'=>$seoCanonical,'headline'=>$shortTitle,'description'=>$plain,'datePublished'=>date('c',$singlePost['created_at']),'dateModified'=>date('c',$singlePost['updated_at']),'author'=>['@type'=>'Person','name'=>$cu['name'],'url'=>$base.'?page=profile'],'image'=>$seoImage?:null,'publisher'=>['@type'=>'Person','name'=>$cu['name'],'url'=>$base.'?page=profile']];
        if (!$seoJsonLd['image']) unset($seoJsonLd['image']);
    }
    if ($page === 'remote_post' && $remotePostData && !isset($remotePostData['error'])) {
        $plain        = textSnippet($remotePostData['content']??'', 160);
        $seoTitle     = textSnippet($remotePostData['content']??'', 60).' — '.APP_NAME;
        $seoDesc      = $plain;
        $seoCanonical = $base.'?page=remote_post&handle='.urlencode($remoteHandle).'&id='.(int)($remotePostData['id']??0);
        $seoRobots    = 'noindex,follow';
    }
    if (in_array($page, ['settings','notifications','following','followers','login'])) {
        $seoRobots = 'noindex,nofollow';
    }
}

render:
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#ffffff">
<title><?= h($seoTitle ?? APP_NAME) ?></title>
<meta name="description" content="<?= h($seoDesc ?? '') ?>">
<meta name="author" content="<?= h($seoAuthor ?? APP_NAME) ?>">
<meta name="robots" content="<?= h($seoRobots ?? 'index,follow') ?>">
<link rel="canonical" href="<?= h($seoCanonical ?? $base) ?>">
<?php if (!$isFirstRun && $cu): ?>
<meta property="og:site_name" content="<?= h(APP_NAME) ?>">
<meta property="og:type" content="<?= h($seoType ?? 'website') ?>">
<meta property="og:title" content="<?= h($seoTitle ?? APP_NAME) ?>">
<meta property="og:description" content="<?= h($seoDesc ?? '') ?>">
<meta property="og:url" content="<?= h($seoCanonical ?? $base) ?>">
<?php if ($seoImage): ?><meta property="og:image" content="<?= h($seoImage) ?>"><?php endif ?>
<meta name="twitter:card" content="<?= ($seoImage && $page==='post') ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= h($seoTitle ?? APP_NAME) ?>">
<meta name="twitter:description" content="<?= h($seoDesc ?? '') ?>">
<?php if ($seoImage): ?><meta name="twitter:image" content="<?= h($seoImage) ?>"><?php endif ?>
<link rel="alternate" type="application/json" title="<?= h(APP_NAME) ?> Posts API" href="<?= h($base.'?api=posts') ?>">
<?php endif ?>
<?php if ($seoJsonLd): ?>
<script type="application/ld+json"><?= json_encode($seoJsonLd, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?></script>
<?php endif ?>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#fff;--bg2:#f6f8fa;--bg3:#eaeef2;--bd:#d0d7de;--tx:#1f2328;--tx2:#636e7b;--blue:#0969da;--blue-h:#0550ae;--green:#1a7f37;--red:#cf222e;--warn:#9a6700;--purple:#8250df;--r:6px;--nav-h:56px;--mob-nav-h:56px;--sh:0 1px 3px rgba(31,35,40,.12),0 8px 24px rgba(66,74,83,.12)}
html{-webkit-text-size-adjust:100%}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;font-size:14px;color:var(--tx);background:var(--bg2);line-height:1.5;min-height:100dvh}
a{color:var(--blue);text-decoration:none}a:hover{text-decoration:underline}
img{max-width:100%;display:block}
button,input,textarea,select{font:inherit}
textarea{touch-action:manipulation}
::-webkit-scrollbar{width:6px;height:6px}::-webkit-scrollbar-track{background:transparent}::-webkit-scrollbar-thumb{background:var(--bd);border-radius:3px}
.wrap{max-width:940px;margin:0 auto;padding:0 16px}
.page-wrap{padding-top:8px;padding-bottom:calc(var(--mob-nav-h) + 12px)}
@media(min-width:768px){.page-wrap{padding-bottom:16px}}
/* ── Nav ── */
.nav{background:var(--bg);border-bottom:1px solid var(--bd);position:sticky;top:0;z-index:100;height:var(--nav-h)}
.nav-inner{display:flex;align-items:center;gap:8px;height:100%;max-width:940px;margin:0 auto;padding:0 16px}
.nav-logo{font-size:18px;font-weight:700;color:var(--tx);display:flex;align-items:center;gap:6px;flex-shrink:0}
.nav-logo span{color:var(--blue)}
.nav-links{display:flex;align-items:center;gap:2px;flex:1;overflow:hidden}
.nav-link{display:flex;align-items:center;gap:5px;padding:6px 10px;border-radius:var(--r);color:var(--tx2);font-weight:500;font-size:13px;white-space:nowrap;transition:background .15s,color .15s;min-height:36px}
.nav-link:hover{background:var(--bg2);color:var(--tx);text-decoration:none}.nav-link.active{color:var(--blue);background:rgba(9,105,218,.06)}
.nav-badge{background:var(--red);color:#fff;font-size:10px;font-weight:700;padding:1px 5px;border-radius:10px;min-width:16px;text-align:center}
.nav-end{margin-left:auto;flex-shrink:0;display:flex;gap:6px;align-items:center}
@media(max-width:767px){.nav-links{display:none}.nav-end{display:none}}
/* ── Mobile nav ── */
.mob-nav{display:none;position:fixed;bottom:0;left:0;right:0;height:var(--mob-nav-h);background:var(--bg);border-top:1px solid var(--bd);z-index:100;padding-bottom:env(safe-area-inset-bottom)}
.mob-nav-inner{display:flex;height:100%}
.mob-nav-item{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;color:var(--tx2);font-size:10px;font-weight:500;position:relative;text-decoration:none;min-width:0;padding:4px 2px;transition:color .15s}
.mob-nav-item:hover{text-decoration:none;color:var(--tx)}.mob-nav-item.active{color:var(--blue)}
.mob-nav-item svg{flex-shrink:0}
.mob-nav-badge{position:absolute;top:4px;right:calc(50% - 16px);background:var(--red);color:#fff;font-size:9px;font-weight:700;padding:0 4px;border-radius:8px;min-width:14px;text-align:center;line-height:14px}
@media(max-width:767px){.mob-nav{display:flex}}
/* ── Layout ── */
.layout{display:grid;grid-template-columns:220px 1fr;gap:20px;padding:16px 0;align-items:start}
@media(max-width:860px){.layout{grid-template-columns:180px 1fr}}
@media(max-width:767px){.layout{grid-template-columns:1fr}.sidebar{display:none}}
/* ── Cards ── */
.card{background:var(--bg);border:1px solid var(--bd);border-radius:var(--r);overflow:hidden}
.card+.card{margin-top:12px}
/* ── Sidebar ── */
.sidebar-profile{padding:14px;text-align:center;border-bottom:1px solid var(--bd)}
.sidebar-cover{height:52px;background:linear-gradient(135deg,var(--blue),var(--purple));margin:-14px -14px 0;overflow:hidden}
.sidebar-cover img{width:100%;height:100%;object-fit:cover}
.sidebar-avatar-wrap{margin-top:-20px;display:flex;justify-content:center;position:relative;z-index:2}
.sidebar-name{font-weight:700;font-size:15px;margin-top:6px}.sidebar-uname{color:var(--tx2);font-size:12px}
.sidebar-bio{font-size:12px;color:var(--tx2);margin-top:6px}
.sidebar-handle{font-size:10px;font-family:monospace;color:var(--tx2);margin-top:4px;word-break:break-all}
.sidebar-stats{display:flex;justify-content:center;gap:16px;margin-top:8px}
.sidebar-nav{padding:6px 0}
.sidebar-nav-item{display:flex;align-items:center;gap:8px;padding:8px 14px;color:var(--tx);font-weight:500;font-size:13px;transition:background .1s}
.sidebar-nav-item:hover{background:var(--bg2);text-decoration:none}.sidebar-nav-item.active{color:var(--blue);background:rgba(9,105,218,.06)}
/* ── Stat items ── */
.stat-item{display:flex;flex-direction:column;align-items:center;gap:1px;text-decoration:none;color:var(--tx);cursor:pointer}
.stat-item:hover{text-decoration:none}.stat-item:hover .stat-num{text-decoration:underline}
.stat-num{font-size:16px;font-weight:700}.stat-label{font-size:11px;color:var(--tx2)}
/* ── Compose ── */
.compose{padding:12px 14px}
.compose-tabs{display:flex;gap:6px;margin-bottom:10px}
.tab-btn{padding:4px 12px;border-radius:var(--r);border:1px solid var(--bd);background:var(--bg);color:var(--tx2);cursor:pointer;font-size:13px;transition:all .15s;min-height:32px}
.tab-btn.active{background:var(--blue);color:#fff;border-color:var(--blue)}
.compose-row{display:flex;gap:10px;align-items:flex-start}
.compose-textarea{flex:1;border:1px solid var(--bd);border-radius:var(--r);padding:10px 12px;resize:vertical;min-height:76px;font-size:14px;transition:border-color .15s;width:0}
.compose-textarea:focus{outline:none;border-color:var(--blue);box-shadow:0 0 0 3px rgba(9,105,218,.1)}
.compose-footer{display:flex;align-items:center;justify-content:space-between;margin-top:8px}
.char-count{font-size:12px;color:var(--tx2)}.char-count.warn{color:var(--warn)}.char-count.over{color:var(--red)}
.file-zone{border:2px dashed var(--bd);border-radius:var(--r);padding:16px;text-align:center;color:var(--tx2);font-size:13px;cursor:pointer;transition:border-color .15s;display:none;margin-top:8px}
.file-zone:hover,.file-zone.drag{border-color:var(--blue)}.file-zone.show{display:block}
.img-preview{max-height:180px;border-radius:var(--r);margin-top:8px;object-fit:contain;max-width:100%}
/* ── Posts ── */
article.post{padding:14px 16px}
article.post+article.post{border-top:1px solid var(--bd)}
.post-header{display:flex;align-items:flex-start;gap:10px;margin-bottom:8px}
.post-meta{flex:1;min-width:0}
.post-author-name{font-weight:600;font-size:14px;color:var(--tx);display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.post-author-name:hover{text-decoration:underline}
.post-author-handle{font-size:12px;color:var(--tx2);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.post-time{font-size:11px;color:var(--tx2);white-space:nowrap;flex-shrink:0;padding-top:2px}
.post-content{font-size:14px;line-height:1.6;word-break:break-word;white-space:pre-wrap}.post-content a{word-break:break-all}
.post-image{margin-top:10px;border-radius:var(--r);overflow:hidden;border:1px solid var(--bd);background:var(--bg3);line-height:0}
.post-image img{width:100%;height:auto;display:block;max-height:75vh;object-fit:contain;background:var(--bg3)}
.post-actions{display:flex;align-items:center;gap:2px;margin-top:10px;padding-top:8px;border-top:1px solid var(--bd);flex-wrap:wrap}
.action-btn{display:inline-flex;align-items:center;gap:4px;padding:5px 8px;border-radius:var(--r);border:none;background:transparent;color:var(--tx2);cursor:pointer;font-size:13px;transition:all .15s;min-height:32px;white-space:nowrap;text-decoration:none}
.action-btn:hover{background:var(--bg2);color:var(--tx);text-decoration:none}.action-btn.liked{color:var(--red)}.action-btn.share-btn{margin-left:auto}
.action-stat{display:inline-flex;align-items:center;gap:4px;padding:5px 8px;color:var(--tx2);font-size:13px;min-height:32px;white-space:nowrap}
.remote-badge{display:inline-flex;align-items:center;background:var(--bg2);border:1px solid var(--bd);border-radius:10px;padding:1px 7px;font-size:11px;color:var(--tx2);flex-shrink:0}
.post-owner-actions{display:flex;gap:4px;margin-left:auto;flex-shrink:0}
/* ── Last comment preview ── */
.post-last-comment{margin-top:8px;padding:8px 10px;background:var(--bg2);border-radius:var(--r);border-left:3px solid var(--bd);font-size:13px}
.post-last-comment-author{font-weight:600;color:var(--tx);margin-right:4px}
.post-last-comment-text{color:var(--tx2);overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
/* ── Guest CTA ── */
.guest-cta{padding:12px 14px;background:rgba(9,105,218,.04);border-top:1px solid var(--bd);font-size:13px;color:var(--tx2);display:flex;align-items:center;gap:8px;flex-wrap:wrap}
/* ── Buttons ── */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:6px 14px;border-radius:var(--r);border:1px solid;cursor:pointer;font-size:14px;font-weight:500;transition:all .15s;text-decoration:none;white-space:nowrap;min-height:36px}
.btn:hover{text-decoration:none}
.btn-primary{background:var(--blue);color:#fff;border-color:var(--blue)}.btn-primary:hover{background:var(--blue-h);border-color:var(--blue-h)}
.btn-secondary{background:var(--bg);color:var(--tx);border-color:var(--bd)}.btn-secondary:hover{background:var(--bg2)}
.btn-danger{background:var(--red);color:#fff;border-color:var(--red)}.btn-danger:hover{background:#a40e26;border-color:#a40e26}
.btn-sm{padding:4px 10px;font-size:12px;min-height:28px}
/* ── Forms ── */
.form-group{margin-bottom:16px}
.form-label{display:block;font-size:14px;font-weight:600;margin-bottom:6px}
.form-input{width:100%;padding:8px 12px;border:1px solid var(--bd);border-radius:var(--r);font-size:14px;background:var(--bg);color:var(--tx);transition:border-color .15s;min-height:38px}
.form-input:focus{outline:none;border-color:var(--blue);box-shadow:0 0 0 3px rgba(9,105,218,.1)}
.form-hint{font-size:12px;color:var(--tx2);margin-top:4px}
/* ── Alerts ── */
.alert{padding:12px 14px;border-radius:var(--r);margin-bottom:14px;font-size:14px;border:1px solid}
.alert-err{background:#fff0ef;color:var(--red);border-color:#fcc3c0}.alert-ok{background:#dafbe1;color:var(--green);border-color:#aceebb}
.alert-info{background:#ddf4ff;color:#0550ae;border-color:#9cd0f5}.alert-warn{background:#fff8c5;color:var(--warn);border-color:#d4a72c}
/* ── Avatar ── */
.avatar{border-radius:50%;object-fit:cover;background:var(--bg3);flex-shrink:0}
.avatar-placeholder{border-radius:50%;background:var(--blue);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0}
/* ── Profile banner ── */
.profile-banner{position:relative}
.profile-cover{height:160px;background:linear-gradient(135deg,var(--blue),var(--purple));overflow:hidden}
@media(min-width:600px){.profile-cover{height:220px}}
.profile-cover img{width:100%;height:100%;object-fit:cover}
.profile-avatar-row{display:flex;align-items:flex-end;justify-content:space-between;padding:0 16px;margin-top:-36px;position:relative;z-index:2}
@media(min-width:600px){.profile-avatar-row{padding:0 24px;margin-top:-44px}}
.profile-avatar-ring{border:4px solid var(--bg);border-radius:50%;box-shadow:0 0 0 1px var(--bd);background:var(--bg);display:inline-block;line-height:0;flex-shrink:0}
.profile-actions{padding-bottom:10px;display:flex;gap:6px;align-items:flex-end;flex-wrap:wrap}
.profile-info-section{padding:10px 16px 16px}
@media(min-width:600px){.profile-info-section{padding:12px 24px 20px}}
.profile-display-name{font-size:20px;font-weight:700}
@media(min-width:600px){.profile-display-name{font-size:22px}}
.profile-handle-line{font-size:13px;color:var(--tx2);font-family:monospace;word-break:break-all;margin-top:2px}
.profile-bio-text{font-size:14px;margin-top:8px}.profile-links{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}
.profile-links a{font-size:13px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.profile-stats{display:flex;gap:20px;margin-top:12px;padding-top:12px;border-top:1px solid var(--bd);flex-wrap:wrap}
/* ── Page tabs ── */
.page-tabs{display:flex;border-bottom:1px solid var(--bd);padding:0 12px;background:var(--bg);overflow-x:auto;-webkit-overflow-scrolling:touch;scrollbar-width:none}
.page-tabs::-webkit-scrollbar{display:none}
.page-tab{padding:12px 14px;font-size:14px;font-weight:500;color:var(--tx2);border-bottom:2px solid transparent;margin-bottom:-1px;text-decoration:none;white-space:nowrap;flex-shrink:0;transition:color .15s}
.page-tab:hover{color:var(--tx);text-decoration:none}.page-tab.active{color:var(--blue);border-bottom-color:var(--blue)}
/* ── Settings ── */
.settings-section{padding:16px}
.cover-preview-box{width:100%;aspect-ratio:3/1;background:linear-gradient(135deg,var(--blue),var(--purple));border-radius:var(--r);overflow:hidden;position:relative;border:1px solid var(--bd);margin-bottom:10px}
.cover-preview-box img{width:100%;height:100%;object-fit:cover}
.cover-res-badge{position:absolute;bottom:8px;right:8px;background:rgba(0,0,0,.55);color:#fff;font-size:11px;padding:2px 8px;border-radius:10px;font-family:monospace}
/* ── Follow / follower items ── */
.follow-item{display:flex;align-items:center;gap:10px;padding:12px 14px;border-bottom:1px solid var(--bd);flex-wrap:wrap}
.follow-item:last-child{border-bottom:none}
.follow-item-info{flex:1;min-width:0}
.follow-handle{font-size:12px;font-family:monospace;color:var(--tx2)}
.follow-item-actions{display:flex;gap:6px;flex-wrap:wrap}
/* ── Notifications ── */
.notif-item{display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border-bottom:1px solid var(--bd)}.notif-item:last-child{border-bottom:none}
.notif-item.unread{background:rgba(9,105,218,.03)}
/* ── Comments ── */
.comment-list{padding:0 14px}
.comment-item{display:flex;gap:10px;padding:10px 0;border-bottom:1px solid var(--bd)}.comment-item:last-child{border-bottom:none}
.comment-body{flex:1;min-width:0}.comment-author{font-weight:600;font-size:13px;color:var(--tx)}.comment-author:hover{text-decoration:underline}
.comment-text{font-size:14px;margin-top:2px;white-space:pre-wrap;word-break:break-word}.comment-time{font-size:11px;color:var(--tx2);margin-top:2px}
.comment-form{padding:12px 14px;border-top:1px solid var(--bd)}
/* ── Feed toolbar ── */
.feed-bar{background:var(--bg);border:1px solid var(--bd);border-radius:var(--r);padding:8px 12px;display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:12px;font-size:13px;color:var(--tx2);flex-wrap:wrap}
.feed-bar-btns{display:flex;gap:6px;flex-wrap:wrap}
/* ── Login ── */
.login-wrap{min-height:100dvh;display:flex;align-items:center;justify-content:center;background:var(--bg2);padding:16px}
.login-card{width:100%;max-width:360px;background:var(--bg);border:1px solid var(--bd);border-radius:12px;padding:28px;box-shadow:var(--sh)}
.login-logo{text-align:center;font-size:26px;font-weight:800;margin-bottom:6px}.login-logo span{color:var(--blue)}
.login-subtitle{text-align:center;color:var(--tx2);font-size:14px;margin-bottom:22px}
/* ── Empty state ── */
.empty-state{text-align:center;padding:40px 20px;color:var(--tx2)}.empty-icon{font-size:36px;margin-bottom:10px}
/* ── Load more ── */
.load-more-wrap{text-align:center;padding:14px}
/* ── Modal ── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:200;display:none;align-items:flex-end;justify-content:center;padding:0}
@media(min-width:500px){.modal-overlay{align-items:center;padding:16px}}
.modal-overlay.open{display:flex}
.modal{background:var(--bg);width:100%;border-radius:16px 16px 0 0;padding:20px;max-height:90dvh;overflow-y:auto;box-shadow:var(--sh);border:1px solid var(--bd)}
@media(min-width:500px){.modal{width:480px;border-radius:12px;max-height:80dvh}}
.modal-title{font-size:16px;font-weight:700;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center}
.modal-close{background:none;border:none;cursor:pointer;font-size:20px;color:var(--tx2);width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:50%;transition:background .15s}
.modal-close:hover{background:var(--bg2)}
/* ── Misc ── */
.link-list{display:flex;flex-direction:column;gap:8px}.link-item{display:flex;gap:8px}.link-item input{flex:1;min-width:0}
.spinner{width:20px;height:20px;border:2px solid var(--bg3);border-top-color:var(--blue);border-radius:50%;animation:spin .6s linear infinite;display:inline-block}
@keyframes spin{to{transform:rotate(360deg)}}
#toast{position:fixed;bottom:calc(var(--mob-nav-h) + 12px);right:16px;color:#fff;padding:10px 16px;border-radius:var(--r);font-size:14px;z-index:999;opacity:0;transform:translateY(6px);transition:all .25s;pointer-events:none;max-width:calc(100vw - 32px)}
@media(min-width:768px){#toast{bottom:24px}}
#toast.show{opacity:1;transform:translateY(0)}
.remote-indicator{display:inline-flex;align-items:center;gap:5px;background:rgba(130,80,223,.1);border:1px solid rgba(130,80,223,.3);border-radius:10px;padding:2px 8px;font-size:12px;color:var(--purple);font-family:monospace}
.remote-post-wrap{max-width:640px;margin:0 auto}
.interaction-loading{opacity:.5;pointer-events:none}
.flex{display:flex}.items-center{align-items:center}.gap-8{gap:8px}.mt-4{margin-top:4px}.mt-8{margin-top:8px}.mt-12{margin-top:12px}.mt-16{margin-top:16px}.text-sm{font-size:13px}.text-muted{color:var(--tx2)}.fw-bold{font-weight:700}.truncate{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.w-full{width:100%}.p-16{padding:16px}.text-center{text-align:center}
.section-header{padding:12px 14px;font-weight:700;border-bottom:1px solid var(--bd);display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}
</style>
</head>
<body>

<?php if ($isFirstRun): ?>
<main class="login-wrap"><div class="login-card">
  <div class="login-logo"><?= h(APP_NAME) ?> <span>✦</span></div>
  <div class="login-subtitle">Welcome! Set up your instance.</div>
  <?php if ($errors): ?><div class="alert alert-err" role="alert"><?= h(implode(' ',$errors)) ?></div><?php endif ?>
  <form method="POST" autocomplete="off">
    <input type="hidden" name="action" value="setup">
    <div class="form-group">
      <label class="form-label" for="setup-username">Username <span class="text-muted" style="font-weight:400">(permanent)</span></label>
      <input class="form-input" id="setup-username" type="text" name="username" pattern="[a-z0-9_]{3,30}" placeholder="e.g. xsukax" required autofocus>
      <div class="form-hint">3–30 chars · lowercase, numbers, underscores only</div>
    </div>
    <div class="alert alert-warn" style="margin-bottom:14px">🔑 Default password: <code style="background:rgba(0,0,0,.06);padding:1px 4px;border-radius:3px"><?= h(DEF_PASS) ?></code> — change after login.</div>
    <button class="btn btn-primary w-full" type="submit">Create my account →</button>
  </form>
</div></main>

<?php elseif ($page === 'login' && !isLoggedIn()): ?>
<main class="login-wrap"><div class="login-card">
  <div class="login-logo"><?= h(APP_NAME) ?> <span>✦</span></div>
  <div class="login-subtitle">Sign in to your instance</div>
  <?php if ($errors): ?><div class="alert alert-err" role="alert"><?= h(implode(' ',$errors)) ?></div><?php endif ?>
  <form method="POST">
    <input type="hidden" name="action" value="login">
    <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
    <div class="form-group"><label class="form-label" for="login-user">Username</label><input class="form-input" id="login-user" type="text" name="username" required autofocus autocomplete="username"></div>
    <div class="form-group"><label class="form-label" for="login-pass">Password</label><input class="form-input" id="login-pass" type="password" name="password" required autocomplete="current-password"></div>
    <button class="btn btn-primary w-full" type="submit">Sign in</button>
    <div style="text-align:center;margin-top:12px"><a href="<?= h($base) ?>" class="text-muted text-sm">← Back to <?= h(APP_NAME) ?></a></div>
  </form>
</div></main>

<?php else: /* ── Public + Authenticated view ── */ ?>

<!-- Desktop nav -->
<header>
<nav class="nav" aria-label="Main navigation"><div class="nav-inner">
  <a class="nav-logo" href="<?= h($base) ?>" aria-label="<?= h(APP_NAME) ?> home"><?= h(APP_NAME) ?> <span aria-hidden="true">✦</span></a>
  <div class="nav-links" role="list">
    <a class="nav-link <?= $page==='home'?'active':'' ?>" href="<?= h($base) ?>" role="listitem">
      <svg width="15" height="15" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M8.354 1.146a.5.5 0 00-.707 0l-6 6-.947.947.708.708.946-.947V13.5A1.5 1.5 0 003.854 15h2.292a.5.5 0 00.5-.5v-3h2.708v3a.5.5 0 00.5.5h2.292a1.5 1.5 0 001.5-1.5V7.854l.946.947.708-.708-.947-.947-6-6z"/></svg>Home
    </a>
    <a class="nav-link <?= $page==='profile'?'active':'' ?>" href="<?= h($base.'?page=profile') ?>" role="listitem">
      <svg width="15" height="15" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M8 8a3 3 0 100-6 3 3 0 000 6zm4 8c0 1-1 1-1 1H3s-1 0-1-1 1-4 6-4 6 3 6 4zm-1-.004c-.001-.246-.154-.986-.832-1.664C9.516 13.68 8.029 13 6 13c-2.03 0-3.516.68-4.168 1.332-.678.678-.83 1.418-.832 1.664h10z"/></svg>Profile
    </a>
    <?php if (isLoggedIn()): ?>
    <a class="nav-link <?= $page==='following'?'active':'' ?>" href="<?= h($base.'?page=following') ?>" role="listitem">
      <svg width="15" height="15" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M15 14s1 0 1-1-1-4-5-4-5 3-5 4 1 1 1 1h8zm-7.978-1c.001-.264.167-1.03.76-1.72C8.312 10.629 9.282 10 11 10c1.717 0 2.687.63 3.24 1.276.593.69.758 1.457.76 1.72H7.022zM11 7a2 2 0 100-4 2 2 0 000 4zm3-2a3 3 0 11-6 0 3 3 0 016 0zM6.936 9.28a5.88 5.88 0 00-1.23-.247A7.35 7.35 0 005 9c-4 0-5 3-5 4 0 .667.333 1 1 1h4.216A2.238 2.238 0 015 13c0-1.01.377-2.042 1.09-2.904.243-.294.526-.569.846-.816zM4.92 10A5.493 5.493 0 004 13H1c0-.26.164-1.03.76-1.724.545-.636 1.492-1.256 3.16-1.275zM1.5 5.5a3 3 0 116 0 3 3 0 01-6 0zm3-2a2 2 0 100 4 2 2 0 000-4z"/></svg>Following
    </a>
    <a class="nav-link <?= $page==='notifications'?'active':'' ?>" href="<?= h($base.'?page=notifications') ?>" role="listitem">
      <svg width="15" height="15" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M8 16a2 2 0 001.985-1.75c.017-.137-.097-.25-.235-.25h-3.5c-.138 0-.252.113-.235.25A2 2 0 008 16zm.25-14.75A5.25 5.25 0 002.75 6.5c0 .682-.184 2.635-.476 4.046-.148.714-.314 1.22-.49 1.454H14.216c-.176-.234-.342-.74-.49-1.454-.292-1.41-.476-3.364-.476-4.046A5.25 5.25 0 008.25 1.25z"/></svg>Alerts
      <?php if ($notifCount>0): ?><span class="nav-badge" aria-label="<?= $notifCount ?> unread"><?= $notifCount ?></span><?php endif ?>
    </a>
    <a class="nav-link <?= $page==='settings'?'active':'' ?>" href="<?= h($base.'?page=settings') ?>" role="listitem">
      <svg width="15" height="15" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M9.796 1.343c-.527-1.79-3.065-1.79-3.592 0l-.094.319a.873.873 0 01-1.255.52l-.292-.16c-1.64-.892-3.433.902-2.54 2.541l.159.292a.873.873 0 01-.52 1.255l-.319.094c-1.79.527-1.79 3.065 0 3.592l.319.094a.873.873 0 01.52 1.255l-.16.292c-.892 1.64.901 3.434 2.541 2.54l.292-.159a.873.873 0 011.255.52l.094.319c.527 1.79 3.065 1.79 3.592 0l.094-.319a.873.873 0 011.255-.52l.292.159c1.64.893 3.434-.902 2.54-2.541l-.159-.292a.873.873 0 01.52-1.255l.319-.094c1.79-.527 1.79-3.065 0-3.592l-.319-.094a.873.873 0 01-.52-1.255l.16-.292c.893-1.64-.902-3.433-2.541-2.54l-.292.16a.873.873 0 01-1.255-.52l-.094-.319z"/></svg>Settings
    </a>
    <?php endif ?>
  </div>
  <div class="nav-end">
    <?php if (isLoggedIn()): ?>
      <form method="POST"><input type="hidden" name="action" value="logout"><input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>"><button class="btn btn-secondary btn-sm" type="submit">Sign out</button></form>
    <?php else: ?>
      <a class="btn btn-primary btn-sm" href="<?= h($base.'?page=login') ?>">Sign in</a>
    <?php endif ?>
  </div>
</div></nav>
</header>

<!-- Mobile bottom nav -->
<nav class="mob-nav" aria-label="Mobile navigation">
  <div class="mob-nav-inner">
    <a class="mob-nav-item <?= $page==='home'?'active':'' ?>" href="<?= h($base) ?>" aria-label="Home">
      <svg width="20" height="20" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M8.354 1.146a.5.5 0 00-.707 0l-6 6-.947.947.708.708.946-.947V13.5A1.5 1.5 0 003.854 15h2.292a.5.5 0 00.5-.5v-3h2.708v3a.5.5 0 00.5.5h2.292a1.5 1.5 0 001.5-1.5V7.854l.946.947.708-.708-.947-.947-6-6z"/></svg>Home
    </a>
    <a class="mob-nav-item <?= $page==='profile'?'active':'' ?>" href="<?= h($base.'?page=profile') ?>" aria-label="Profile">
      <svg width="20" height="20" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M8 8a3 3 0 100-6 3 3 0 000 6zm4 8c0 1-1 1-1 1H3s-1 0-1-1 1-4 6-4 6 3 6 4zm-1-.004c-.001-.246-.154-.986-.832-1.664C9.516 13.68 8.029 13 6 13c-2.03 0-3.516.68-4.168 1.332-.678.678-.83 1.418-.832 1.664h10z"/></svg>Profile
    </a>
    <?php if (isLoggedIn()): ?>
    <a class="mob-nav-item <?= $page==='notifications'?'active':'' ?>" href="<?= h($base.'?page=notifications') ?>" aria-label="Alerts">
      <?php if ($notifCount>0): ?><span class="mob-nav-badge" aria-label="<?= $notifCount ?> unread"><?= $notifCount ?></span><?php endif ?>
      <svg width="20" height="20" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M8 16a2 2 0 001.985-1.75c.017-.137-.097-.25-.235-.25h-3.5c-.138 0-.252.113-.235.25A2 2 0 008 16zm.25-14.75A5.25 5.25 0 002.75 6.5c0 .682-.184 2.635-.476 4.046-.148.714-.314 1.22-.49 1.454H14.216c-.176-.234-.342-.74-.49-1.454-.292-1.41-.476-3.364-.476-4.046A5.25 5.25 0 008.25 1.25z"/></svg>Alerts
    </a>
    <a class="mob-nav-item <?= $page==='following'?'active':'' ?>" href="<?= h($base.'?page=following') ?>" aria-label="Following">
      <svg width="20" height="20" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M15 14s1 0 1-1-1-4-5-4-5 3-5 4 1 1 1 1h8zm-7.978-1c.001-.264.167-1.03.76-1.72C8.312 10.629 9.282 10 11 10c1.717 0 2.687.63 3.24 1.276.593.69.758 1.457.76 1.72H7.022zM11 7a2 2 0 100-4 2 2 0 000 4zm3-2a3 3 0 11-6 0 3 3 0 016 0zM6.936 9.28a5.88 5.88 0 00-1.23-.247A7.35 7.35 0 005 9c-4 0-5 3-5 4 0 .667.333 1 1 1h4.216A2.238 2.238 0 015 13c0-1.01.377-2.042 1.09-2.904.243-.294.526-.569.846-.816zM4.92 10A5.493 5.493 0 004 13H1c0-.26.164-1.03.76-1.724.545-.636 1.492-1.256 3.16-1.275zM1.5 5.5a3 3 0 116 0 3 3 0 01-6 0zm3-2a2 2 0 100 4 2 2 0 000-4z"/></svg>Following
    </a>
    <a class="mob-nav-item <?= $page==='settings'?'active':'' ?>" href="<?= h($base.'?page=settings') ?>" aria-label="Settings">
      <svg width="20" height="20" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M9.796 1.343c-.527-1.79-3.065-1.79-3.592 0l-.094.319a.873.873 0 01-1.255.52l-.292-.16c-1.64-.892-3.433.902-2.54 2.541l.159.292a.873.873 0 01-.52 1.255l-.319.094c-1.79.527-1.79 3.065 0 3.592l.319.094a.873.873 0 01.52 1.255l-.16.292c-.892 1.64.901 3.434 2.541 2.54l.292-.159a.873.873 0 011.255.52l.094.319c.527 1.79 3.065 1.79 3.592 0l.094-.319a.873.873 0 011.255-.52l.292.159c1.64.893 3.434-.902 2.54-2.541l-.159-.292a.873.873 0 01.52-1.255l.319-.094c1.79-.527 1.79-3.065 0-3.592l-.319-.094a.873.873 0 01-.52-1.255l.16-.292c.893-1.64-.902-3.433-2.541-2.54l-.292.16a.873.873 0 01-1.255-.52l-.094-.319z"/></svg>Settings
    </a>
    <?php else: ?>
    <a class="mob-nav-item" href="<?= h($base.'?page=login') ?>" aria-label="Sign in">
      <svg width="20" height="20" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M10 3.5a.5.5 0 00-.5-.5h-8a.5.5 0 00-.5.5v9a.5.5 0 00.5.5h8a.5.5 0 00.5-.5v-2a.5.5 0 011 0v2A1.5 1.5 0 019.5 14h-8A1.5 1.5 0 010 12.5v-9A1.5 1.5 0 011.5 2h8A1.5 1.5 0 0111 3.5v2a.5.5 0 01-1 0v-2z"/><path d="M15.854 8.354a.5.5 0 000-.708l-3-3a.5.5 0 10-.708.708L14.293 7.5H5.5a.5.5 0 000 1h8.793l-2.147 2.146a.5.5 0 00.708.708l3-3z"/></svg>Sign in
    </a>
    <?php endif ?>
  </div>
</nav>

<main>
<div class="wrap page-wrap">
<?php if ($errors): ?><div class="alert alert-err mt-8" role="alert"><?= h(implode(' ',$errors)) ?></div><?php endif ?>
<?php if ($info):   ?><div class="alert alert-ok  mt-8" role="status"><?= h($info) ?></div><?php endif ?>

<?php if ($page==='home'): ?>
<div class="layout">
  <!-- Sidebar (desktop only) -->
  <aside class="sidebar" aria-label="Profile sidebar">
    <div class="card">
      <div class="sidebar-profile">
        <div class="sidebar-cover"><?php if ($cu && $cu['cover']): ?><img src="<?= h($myCoverUrl) ?>" alt="Cover photo"><?php endif ?></div>
        <div class="sidebar-avatar-wrap"><?= avatarEl($myAvatarUrl,$cu['name']??'',52) ?></div>
        <div class="sidebar-name"><?= h($cu['name'] ?? '') ?></div>
        <div class="sidebar-uname">@<?= h($cu['username'] ?? '') ?></div>
        <?php if ($cu && $cu['bio']): ?><div class="sidebar-bio"><?= h($cu['bio']) ?></div><?php endif ?>
        <div class="sidebar-handle"><?= h($handle) ?></div>
        <div class="sidebar-stats mt-8">
          <a class="stat-item" href="<?= h($base.'?page=profile') ?>"><span class="stat-num"><?= $followersCount ?></span><span class="stat-label">Followers</span></a>
          <a class="stat-item" href="<?= h($base.'?page=profile') ?>"><span class="stat-num"><?= $followingCount ?></span><span class="stat-label">Following</span></a>
        </div>
      </div>
      <div class="sidebar-nav">
        <a class="sidebar-nav-item active" href="<?= h($base) ?>">🏠 Home</a>
        <a class="sidebar-nav-item" href="<?= h($base.'?page=profile') ?>">👤 Profile</a>
        <?php if (isLoggedIn()): ?>
        <a class="sidebar-nav-item" href="<?= h($base.'?page=followers') ?>">👥 Followers (<?= $followersCount ?>)</a>
        <a class="sidebar-nav-item" href="<?= h($base.'?page=following') ?>">➕ Following (<?= $followingCount ?>)</a>
        <a class="sidebar-nav-item" href="<?= h($base.'?page=notifications') ?>">🔔 Alerts<?php if ($notifCount): ?> <span class="nav-badge"><?= $notifCount ?></span><?php endif ?></a>
        <?php endif ?>
      </div>
    </div>
    <?php if (isLoggedIn()): ?>
    <div class="card mt-12 p-16">
      <div class="fw-bold text-sm" style="margin-bottom:6px">Your handle</div>
      <code style="font-size:10px;word-break:break-all;color:var(--tx2);display:block"><?= h($handle) ?></code>
      <button class="btn btn-secondary btn-sm w-full mt-8" onclick="copyHandle()">Copy handle</button>
    </div>
    <?php endif ?>
  </aside>

  <!-- Main feed -->
  <div>
    <?php if (isLoggedIn()): ?>
    <div class="card" aria-label="Compose post">
      <div class="compose">
        <div class="compose-tabs">
          <button class="tab-btn active" id="tab-text" onclick="switchPostType('text')">📝 Text</button>
          <button class="tab-btn" id="tab-image" onclick="switchPostType('image')">🖼 Image</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
          <input type="hidden" name="action" value="create_post">
          <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
          <input type="hidden" name="post_type" id="postTypeInput" value="text">
          <div class="compose-row">
            <?= avatarEl($myAvatarUrl,$cu['name']??'',38) ?>
            <textarea class="compose-textarea" name="content" id="composeText" placeholder="What's on your mind?" maxlength="<?= MAX_CHARS ?>" oninput="updateCharCount()" rows="3" aria-label="Post content"></textarea>
          </div>
          <div id="imageUploadZone" class="file-zone" onclick="document.getElementById('imgFile').click()" ondragover="this.classList.add('drag');event.preventDefault()" ondragleave="this.classList.remove('drag')" ondrop="handleImgDrop(event)">
            <input type="file" name="image" id="imgFile" accept="image/*" style="display:none" onchange="previewImg(this)">
            📷 Tap or drag image here (JPEG/PNG/GIF/WebP · max 10 MB)
            <img id="imgPreview" class="img-preview" style="display:none" alt="Image preview">
          </div>
          <div class="compose-footer">
            <span class="char-count" id="charCount" aria-live="polite">0 / <?= MAX_CHARS ?></span>
            <button class="btn btn-primary btn-sm" type="submit">Post</button>
          </div>
        </form>
      </div>
    </div>
    <?php endif ?>

    <div class="feed-bar mt-12" role="status" aria-live="polite">
      <span id="feedStatus">Loading feed…</span>
      <div class="feed-bar-btns">
        <button class="btn btn-secondary btn-sm" id="checkNewBtn" onclick="checkNewPosts()" style="display:none">⬆ New posts</button>
        <?php if (isLoggedIn()): ?><button class="btn btn-secondary btn-sm" onclick="syncAndRefresh()" id="refreshBtn">🔄 Sync</button><?php endif ?>
      </div>
    </div>

    <div id="feedSpinner" class="text-center p-16" style="display:none"><div class="spinner" style="margin:0 auto" aria-label="Loading posts"></div></div>
    <div id="feedContainer" class="card"></div>
    <div class="load-more-wrap" id="loadMoreWrap" style="display:none">
      <button class="btn btn-secondary" id="loadMoreBtn" onclick="loadMore()">Load 20 more</button>
    </div>
  </div>
</div>

<?php elseif ($page==='profile'): ?>
<div class="mt-12">
  <div class="card">
    <div class="profile-banner">
      <div class="profile-cover"><?php if ($cu && $cu['cover']): ?><img src="<?= h($myCoverUrl) ?>" alt="<?= h($cu['name']) ?> cover photo"><?php endif ?></div>
      <div class="profile-avatar-row">
        <div class="profile-avatar-ring"><?= avatarEl($myAvatarUrl,$cu['name']??'',80) ?></div>
        <div class="profile-actions">
          <?php if (isLoggedIn()): ?>
          <a class="btn btn-secondary btn-sm" href="<?= h($base.'?page=settings') ?>">✏️ Edit</a>
          <a class="btn btn-secondary btn-sm" href="<?= h($base.'?api=profile') ?>" target="_blank" rel="noopener noreferrer">API</a>
          <?php endif ?>
        </div>
      </div>
    </div>
    <div class="profile-info-section">
      <h1 class="profile-display-name"><?= h($cu['name'] ?? '') ?></h1>
      <div class="profile-handle-line"><?= h($handle) ?></div>
      <?php if ($cu && $cu['bio']): ?><p class="profile-bio-text"><?= h($cu['bio']) ?></p><?php endif ?>
      <?php $links=json_decode($cu['links']??'[]',true); if ($links): ?>
        <div class="profile-links"><?php foreach ($links as $l): ?><a href="<?= h($l) ?>" target="_blank" rel="noopener noreferrer">🔗 <?= h(parse_url($l,PHP_URL_HOST)?:$l) ?></a><?php endforeach ?></div>
      <?php endif ?>
      <div class="profile-stats">
        <?php if (isLoggedIn()): ?>
        <a class="stat-item" href="<?= h($base.'?page=followers') ?>"><span class="stat-num"><?= $followersCount ?></span><span class="stat-label">Followers</span></a>
        <a class="stat-item" href="<?= h($base.'?page=following') ?>"><span class="stat-num"><?= $followingCount ?></span><span class="stat-label">Following</span></a>
        <?php else: ?>
        <div class="stat-item"><span class="stat-num"><?= $followersCount ?></span><span class="stat-label">Followers</span></div>
        <div class="stat-item"><span class="stat-num"><?= $followingCount ?></span><span class="stat-label">Following</span></div>
        <?php endif ?>
        <div class="stat-item"><span class="stat-num"><?= count($posts) ?></span><span class="stat-label">Posts</span></div>
      </div>
    </div>
    <div class="page-tabs" role="tablist"><span class="page-tab active" role="tab" aria-selected="true">Posts (<?= count($posts) ?>)</span></div>
    <?php if (!$posts): ?>
      <div class="empty-state"><div class="empty-icon">📭</div>No posts yet.</div>
    <?php else: foreach ($posts as $p):
      $actor='local:'.($cu['username']??'');
      $liked=false;
      if (isLoggedIn()) { $ls=db()->prepare("SELECT COUNT(*) FROM likes WHERE post_id=? AND actor=?"); $ls->execute([$p['id'],$actor]); $liked=(bool)$ls->fetchColumn(); }
      $cs=db()->prepare("SELECT COUNT(*) FROM comments WHERE post_id=?"); $cs->execute([$p['id']]); $cCount=(int)$cs->fetchColumn();
      $lc=db()->prepare("SELECT author, content FROM comments WHERE post_id=? ORDER BY created_at DESC LIMIT 1"); $lc->execute([$p['id']]); $lastComment=$lc->fetch();
    ?>
      <article class="post" itemscope itemtype="https://schema.org/SocialMediaPosting">
        <meta itemprop="datePublished" content="<?= date('c', $p['created_at']) ?>">
        <meta itemprop="dateModified" content="<?= date('c', $p['updated_at']) ?>">
        <div class="post-header">
          <?= avatarEl($myAvatarUrl,$cu['name']??'',38) ?>
          <div class="post-meta"><a class="post-author-name" href="<?= h($base.'?page=profile') ?>" itemprop="author"><?= h($cu['name']??'') ?></a><div class="post-author-handle">@<?= h($cu['username']??'') ?></div></div>
          <span class="post-time"><?= timeEl($p['created_at']) ?></span>
          <?php if ($p['updated_at']>$p['created_at']): ?><span class="remote-badge">edited</span><?php endif ?>
          <?php if (isLoggedIn()): ?>
          <div class="post-owner-actions">
            <button class="btn btn-secondary btn-sm" onclick="openEditModal(<?= $p['id'] ?>,<?= htmlspecialchars(json_encode($p['content']),ENT_QUOTES) ?>)" aria-label="Edit post">✏️</button>
            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this post?')"><input type="hidden" name="action" value="delete_post"><input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>"><input type="hidden" name="post_id" value="<?= $p['id'] ?>"><button class="btn btn-danger btn-sm" aria-label="Delete post">🗑</button></form>
          </div>
          <?php endif ?>
        </div>
        <div class="post-content" itemprop="text"><?= linkify($p['content']) ?></div>
        <?php if ($p['image_path']): ?><div class="post-image"><img src="<?= h($base.'?file=post_img&id='.$p['id']) ?>" loading="lazy" alt="Post image by <?= h($cu['name']??'') ?>" itemprop="image"></div><?php endif ?>
        <?php if ($lastComment): ?>
          <div class="post-last-comment"><span class="post-last-comment-author"><?= h($lastComment['author']) ?></span><div class="post-last-comment-text"><?= h($lastComment['content']) ?></div></div>
        <?php endif ?>
        <div class="post-actions">
          <?php if (isLoggedIn()): ?>
          <form method="POST" style="display:inline"><input type="hidden" name="action" value="<?= $liked?'unlike':'like' ?>"><input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>"><input type="hidden" name="post_id" value="<?= $p['id'] ?>"><button class="action-btn <?= $liked?'liked':'' ?>" aria-label="<?= $liked?'Unlike':'Like' ?> post">❤️ <?= $p['likes'] ?></button></form>
          <?php else: ?><span class="action-stat">❤️ <?= $p['likes'] ?></span><?php endif ?>
          <a class="action-btn" href="<?= h($base.'?page=post&id='.$p['id']) ?>" itemprop="url">💬 <?= $cCount ?></a>
          <button class="action-btn share-btn" onclick="sharePost('<?= h($base.'?page=post&id='.$p['id']) ?>')" aria-label="Share post">🔗</button>
        </div>
      </article>
    <?php endforeach; endif ?>
    <?php if (!isLoggedIn()): ?>
    <div class="guest-cta">🔒 <a href="<?= h($base.'?page=login') ?>">Sign in</a> to like and comment on posts.</div>
    <?php endif ?>
  </div>
</div>

<?php elseif ($page==='followers'): ?>
<div class="mt-12" style="max-width:640px;margin-top:12px">
  <div class="card">
    <div class="section-header"><span>Your Followers (<?= count($followers) ?>)</span><a href="<?= h($base.'?page=profile') ?>" class="btn btn-secondary btn-sm">← Profile</a></div>
    <?php if (!$followers): ?>
      <div class="empty-state"><div class="empty-icon">👥</div>No followers yet.</div>
    <?php else: foreach ($followers as $f):
      $fst=db()->prepare("SELECT COUNT(*) FROM follows WHERE handle=?"); $fst->execute([$f['handle']]); $alreadyFollowing=(bool)$fst->fetchColumn();
    ?>
      <div class="follow-item">
        <?= avatarEl($f['avatar']??'',$f['name']?:$f['handle'],40) ?>
        <div class="follow-item-info"><div class="fw-bold text-sm truncate"><?= $f['name']?h($f['name']):'—' ?></div><div class="follow-handle truncate"><?= h($f['handle']) ?></div></div>
        <div class="follow-item-actions">
          <a class="btn btn-secondary btn-sm" href="<?= h($base.'?page=remote_profile&handle='.urlencode($f['handle'])) ?>">View</a>
          <?php if (!$alreadyFollowing): ?>
            <form method="POST"><input type="hidden" name="action" value="follow"><input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>"><input type="hidden" name="handle" value="<?= h($f['handle']) ?>"><input type="hidden" name="_back" value="<?= h($base.'?page=followers') ?>"><button class="btn btn-primary btn-sm">+ Follow</button></form>
          <?php else: ?><span class="remote-badge">✓ Following</span><?php endif ?>
        </div>
      </div>
    <?php endforeach; endif ?>
  </div>
</div>

<?php elseif ($page==='following'): ?>
<div class="mt-12" style="max-width:640px;margin-top:12px">
  <div class="card p-16" style="margin-bottom:12px">
    <div class="fw-bold" style="margin-bottom:10px">Follow a federation handle</div>
    <div class="alert alert-info" style="font-size:13px">Format: <code>@username@domain.com/path/to/xfedi.php</code></div>
    <form method="POST" style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
      <input type="hidden" name="action" value="follow"><input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
      <input class="form-input" type="text" name="handle" placeholder="@user@example.com/xfedi.php" style="flex:1;min-width:200px" aria-label="Federation handle">
      <button class="btn btn-primary" type="submit">Follow</button>
    </form>
  </div>
  <div class="card">
    <div class="section-header">Following (<?= count($follows) ?>)</div>
    <?php if (!$follows): ?>
      <div class="empty-state"><div class="empty-icon">👥</div>Not following anyone yet.</div>
    <?php else: foreach ($follows as $f): ?>
      <div class="follow-item">
        <?= avatarEl($f['avatar']??'',$f['name']?:$f['handle'],40) ?>
        <div class="follow-item-info"><a class="fw-bold text-sm truncate" href="<?= h($base.'?page=remote_profile&handle='.urlencode($f['handle'])) ?>" style="color:var(--tx);display:block"><?= $f['name']?h($f['name']):'—' ?></a><div class="follow-handle truncate"><?= h($f['handle']) ?></div></div>
        <div class="follow-item-actions">
          <a class="btn btn-secondary btn-sm" href="<?= h($base.'?page=remote_profile&handle='.urlencode($f['handle'])) ?>">View</a>
          <form method="POST"><input type="hidden" name="action" value="unfollow"><input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>"><input type="hidden" name="handle" value="<?= h($f['handle']) ?>"><button class="btn btn-secondary btn-sm">Unfollow</button></form>
        </div>
      </div>
    <?php endforeach; endif ?>
  </div>
</div>

<?php elseif ($page==='notifications'): ?>
<div class="mt-12" style="max-width:640px;margin-top:12px">
  <div class="card">
    <div class="section-header">Notifications</div>
    <?php if (!$notifications): ?>
      <div class="empty-state"><div class="empty-icon">🔔</div>No notifications yet.</div>
    <?php else:
      $icons=['like'=>'❤️','comment'=>'💬','follow'=>'👤'];
      $descs=['like'=>'liked your post','comment'=>'commented on your post','follow'=>'started following you'];
      foreach ($notifications as $n): ?>
        <div class="notif-item <?= !$n['is_read']?'unread':'' ?>">
          <div style="width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0" aria-hidden="true"><?= $icons[$n['type']]??'📣' ?></div>
          <div style="flex:1;min-width:0">
            <?php if ($n['type']==='follow'&&preg_match('/^@[^@\s]+@[^\s]+$/',$n['actor'])): ?>
              <a href="<?= h($base.'?page=remote_profile&handle='.urlencode($n['actor'])) ?>" class="fw-bold"><?= h($n['actor']) ?></a>
            <?php else: ?><span class="fw-bold"><?= h($n['actor']) ?></span><?php endif ?>
            <span class="text-muted"> <?= $descs[$n['type']]??'interacted' ?></span>
            <?php if ($n['post_id']): ?> · <a href="<?= h($base.'?page=post&id='.$n['post_id']) ?>">view post</a><?php endif ?>
            <?php if ($n['content']): ?><div class="text-muted text-sm mt-4" style="font-style:italic">"<?= h(mb_substr($n['content'],0,80)) ?>"</div><?php endif ?>
            <div class="text-muted" style="font-size:11px;margin-top:2px"><?= timeEl($n['created_at']) ?></div>
          </div>
        </div>
    <?php endforeach; endif ?>
  </div>
</div>

<?php elseif ($page==='settings'): ?>
<div class="mt-12" style="max-width:600px;margin-top:12px">
  <div class="card">
    <div class="section-header">Profile Settings</div>
    <div class="settings-section">
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="update_profile"><input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
        <div class="form-group"><label class="form-label" for="s-name">Display Name</label><input class="form-input" id="s-name" type="text" name="name" value="<?= h($cu['name']??'') ?>" maxlength="100" required></div>
        <div class="form-group"><label class="form-label">Username <span style="color:var(--tx2);font-weight:400">(cannot be changed)</span></label><input class="form-input" type="text" value="<?= h($cu['username']??'') ?>" disabled style="background:var(--bg2)"></div>
        <div class="form-group"><label class="form-label" for="s-bio">Bio <span style="color:var(--tx2);font-weight:400">(max 300 chars)</span></label><textarea class="form-input" id="s-bio" name="bio" rows="3" maxlength="300"><?= h($cu['bio']??'') ?></textarea></div>
        <div class="form-group">
          <label class="form-label">Links</label>
          <div class="link-list" id="linkList">
            <?php foreach (json_decode($cu['links']??'[]',true) as $l): ?>
              <div class="link-item"><input class="form-input" type="url" name="links[]" value="<?= h($l) ?>" placeholder="https://…"><button type="button" class="btn btn-secondary btn-sm" onclick="this.parentNode.remove()">✕</button></div>
            <?php endforeach ?>
          </div>
          <button type="button" class="btn btn-secondary btn-sm mt-8" onclick="addLinkField()">+ Add link</button>
        </div>
        <div class="form-group">
          <label class="form-label">Profile Picture</label>
          <?php if ($cu && $cu['avatar']): ?><img src="<?= h($myAvatarUrl) ?>" class="avatar mt-4" width="64" height="64" alt="Current profile picture" style="margin-bottom:8px"><?php endif ?>
          <input class="form-input" type="file" name="avatar" accept="image/*" style="padding:4px" aria-label="Upload profile picture">
          <div class="form-hint">JPEG/PNG/GIF/WebP · max 5 MB · recommended 400×400 px</div>
        </div>
        <div class="form-group">
          <label class="form-label">Cover Photo</label>
          <div class="cover-preview-box">
            <?php if ($cu && $cu['cover']): ?><img src="<?= h($myCoverUrl) ?>" alt="Current cover photo" id="coverPreviewImg"><?php else: ?><img id="coverPreviewImg" style="display:none" alt="Cover photo preview"><?php endif ?>
            <div class="cover-res-badge">Recommended: 1500×500 px</div>
          </div>
          <input class="form-input" type="file" name="cover" accept="image/*" style="padding:4px" onchange="previewCover(this)" aria-label="Upload cover photo">
          <div class="form-hint">JPEG/PNG/GIF/WebP · max 5 MB</div>
        </div>
        <button class="btn btn-primary" type="submit">Save profile</button>
      </form>
    </div>
  </div>
  <div class="card mt-12">
    <div class="section-header">Change Password</div>
    <div class="settings-section">
      <form method="POST">
        <input type="hidden" name="action" value="change_password"><input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
        <div class="form-group"><label class="form-label" for="pw-cur">Current password</label><input class="form-input" id="pw-cur" type="password" name="current" required autocomplete="current-password"></div>
        <div class="form-group"><label class="form-label" for="pw-new">New password <span style="color:var(--tx2);font-weight:400">(min 8 chars)</span></label><input class="form-input" id="pw-new" type="password" name="newpw" required minlength="8" autocomplete="new-password"></div>
        <div class="form-group"><label class="form-label" for="pw-conf">Confirm new password</label><input class="form-input" id="pw-conf" type="password" name="confirm" required autocomplete="new-password"></div>
        <button class="btn btn-primary" type="submit">Update password</button>
      </form>
    </div>
  </div>
  <div class="card mt-12">
    <div class="section-header">Federation Info</div>
    <div class="settings-section">
      <div class="form-group">
        <label class="form-label">Your handle</label>
        <code style="display:block;padding:8px 12px;background:var(--bg2);border:1px solid var(--bd);border-radius:6px;font-size:12px;word-break:break-all"><?= h($handle) ?></code>
        <div class="form-hint">Share this handle so others can follow you.</div>
      </div>
      <div class="form-group">
        <label class="form-label">SEO &amp; Discovery</label>
        <div class="text-sm text-muted" style="display:flex;flex-direction:column;gap:4px">
          <div>Sitemap: <a href="<?= h($base.'?sitemap') ?>" target="_blank" rel="noopener noreferrer"><code>?sitemap</code></a></div>
          <div>Robots: <a href="<?= h($base.'?robots') ?>" target="_blank" rel="noopener noreferrer"><code>?robots</code></a></div>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">API endpoints</label>
        <div class="text-sm text-muted" style="display:flex;flex-direction:column;gap:4px">
          <div>Profile: <a href="<?= h($base.'?api=profile') ?>" target="_blank" rel="noopener noreferrer"><code>?api=profile</code></a></div>
          <div>Posts: <a href="<?= h($base.'?api=posts') ?>" target="_blank" rel="noopener noreferrer"><code>?api=posts</code></a></div>
          <div>Followers: <a href="<?= h($base.'?api=followers') ?>" target="_blank" rel="noopener noreferrer"><code>?api=followers</code></a></div>
          <div>Interact: <code>?api=interact</code> (POST)</div>
        </div>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="logout"><input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
        <button class="btn btn-danger btn-sm" type="submit">Sign out</button>
      </form>
    </div>
  </div>
</div>

<?php elseif ($page==='remote_profile'):
  $activeTab = $_GET['tab']??'posts';
?>
<div class="mt-12">
  <div style="margin-bottom:10px"><a href="<?= h($base) ?>" class="btn btn-secondary btn-sm">← Back</a></div>
  <?php if (!$remoteParsed||!$remoteProfileData): ?>
    <div class="card p-16"><div class="alert alert-err">Could not load remote profile.</div><div class="form-hint"><?= h($remoteHandle) ?></div></div>
  <?php else: $rp=$remoteProfileData; $rpAvatarUrl=$rp['avatar']??'';
    $rpFollowers=(int)($rp['followers_count']??0); $rpFollowing=(int)($rp['following_count']??0);
    $stmtF=db()->prepare("SELECT COUNT(*) FROM follows WHERE handle=?"); $stmtF->execute([$rp['handle']??$remoteHandle]); $isFollowing=(bool)$stmtF->fetchColumn();
  ?>
    <div class="card">
      <div class="profile-banner">
        <div class="profile-cover"><?php if (!empty($rp['cover'])): ?><img src="<?= h($rp['cover']) ?>" alt="Cover photo" onerror="this.style.display='none'"><?php endif ?></div>
        <div class="profile-avatar-row">
          <div class="profile-avatar-ring"><?= avatarEl($rpAvatarUrl,$rp['name']??'?',76) ?></div>
          <div class="profile-actions">
            <span class="remote-indicator">📡 Remote</span>
            <?php if (isLoggedIn()): ?>
              <?php if ($isFollowing): ?>
                <form method="POST"><input type="hidden" name="action" value="unfollow"><input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>"><input type="hidden" name="handle" value="<?= h($rp['handle']??$remoteHandle) ?>"><input type="hidden" name="_back" value="<?= h($base.'?page=remote_profile&handle='.urlencode($remoteHandle)) ?>"><button class="btn btn-secondary btn-sm">✓ Following</button></form>
              <?php else: ?>
                <form method="POST"><input type="hidden" name="action" value="follow"><input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>"><input type="hidden" name="handle" value="<?= h($rp['handle']??$remoteHandle) ?>"><input type="hidden" name="_back" value="<?= h($base.'?page=remote_profile&handle='.urlencode($remoteHandle)) ?>"><button class="btn btn-primary btn-sm">+ Follow</button></form>
              <?php endif ?>
            <?php endif ?>
          </div>
        </div>
      </div>
      <div class="profile-info-section">
        <h1 class="profile-display-name"><?= h($rp['name']??'Unknown') ?></h1>
        <div class="profile-handle-line"><?= h($rp['handle']??$remoteHandle) ?></div>
        <?php if (!empty($rp['bio'])): ?><p class="profile-bio-text"><?= h($rp['bio']) ?></p><?php endif ?>
        <?php if (!empty($rp['links'])): ?>
          <div class="profile-links"><?php foreach ((array)$rp['links'] as $l): ?><a href="<?= h($l) ?>" target="_blank" rel="noopener noreferrer">🔗 <?= h(parse_url($l,PHP_URL_HOST)?:$l) ?></a><?php endforeach ?></div>
        <?php endif ?>
        <div class="profile-stats">
          <a class="stat-item" href="<?= h($base.'?page=remote_profile&handle='.urlencode($remoteHandle).'&tab=followers') ?>"><span class="stat-num"><?= $rpFollowers ?></span><span class="stat-label">Followers</span></a>
          <div class="stat-item"><span class="stat-num"><?= $rpFollowing ?></span><span class="stat-label">Following</span></div>
        </div>
      </div>
      <div class="page-tabs" role="tablist">
        <a class="page-tab <?= $activeTab==='posts'?'active':'' ?>" href="<?= h($base.'?page=remote_profile&handle='.urlencode($remoteHandle).'&tab=posts') ?>" role="tab">Posts (<?= count($remotePosts) ?>)</a>
        <a class="page-tab <?= $activeTab==='followers'?'active':'' ?>" href="<?= h($base.'?page=remote_profile&handle='.urlencode($remoteHandle).'&tab=followers') ?>" role="tab">Followers (<?= $rpFollowers ?>)</a>
      </div>
      <?php if ($activeTab==='posts'): ?>
        <?php if (!$remotePosts): ?>
          <div class="empty-state"><div class="empty-icon">📭</div>No posts.</div>
        <?php else: foreach ($remotePosts as $rpost): ?>
          <article class="post">
            <div class="post-header">
              <?= avatarEl($rpAvatarUrl,$rp['name']??'?',38) ?>
              <div class="post-meta"><a class="post-author-name" href="<?= h($base.'?page=remote_profile&handle='.urlencode($rp['handle']??$remoteHandle)) ?>"><?= h($rp['name']??'Unknown') ?></a><div class="post-author-handle"><?= h($rp['handle']??'') ?></div></div>
              <span class="post-time"><?= timeEl((int)($rpost['created_at']??0)) ?></span>
            </div>
            <div class="post-content"><?= linkify($rpost['content']??'') ?></div>
            <?php if (!empty($rpost['image_url'])): ?><div class="post-image"><img src="<?= h($rpost['image_url']) ?>" loading="lazy" alt="Post image" onerror="this.parentNode.style.display='none'"></div><?php endif ?>
            <div class="post-actions">
              <?php if (isLoggedIn()): ?>
              <button class="action-btn" onclick="remoteInteractBtn(this,'like','<?= h($remoteBase) ?>','<?= (int)$rpost['id'] ?>','<?= h($handle) ?>')">❤️ <span><?= (int)($rpost['likes']??0) ?></span></button>
              <?php else: ?><span class="action-stat">❤️ <?= (int)($rpost['likes']??0) ?></span><?php endif ?>
              <a class="action-btn" href="<?= h($base.'?page=remote_post&handle='.urlencode($rp['handle']??$remoteHandle).'&id='.(int)$rpost['id']) ?>">💬 <?= (int)($rpost['comment_count']??0) ?> · Open</a>
              <button class="action-btn share-btn" onclick="sharePost('<?= h($base.'?page=remote_post&handle='.urlencode($rp['handle']??$remoteHandle).'&id='.(int)$rpost['id']) ?>')" aria-label="Share">🔗</button>
            </div>
          </article>
        <?php endforeach; endif ?>
      <?php elseif ($activeTab==='followers'): ?>
        <?php if (!$remoteFollowers): ?>
          <div class="empty-state"><div class="empty-icon">👥</div>No followers.</div>
        <?php else: foreach ($remoteFollowers as $rf): ?>
          <div class="follow-item">
            <?= avatarEl($rf['avatar']??'',$rf['name']?:$rf['handle'],40) ?>
            <div class="follow-item-info"><div class="fw-bold text-sm truncate"><?= $rf['name']?h($rf['name']):'—' ?></div><div class="follow-handle truncate"><?= h($rf['handle']) ?></div></div>
            <?php if (preg_match('/^@[^@\s]+@[^\s]+$/',$rf['handle'])&&isLoggedIn()): ?>
              <div class="follow-item-actions">
                <a class="btn btn-secondary btn-sm" href="<?= h($base.'?page=remote_profile&handle='.urlencode($rf['handle'])) ?>">View</a>
                <?php $fstR=db()->prepare("SELECT COUNT(*) FROM follows WHERE handle=?"); $fstR->execute([$rf['handle']]); $alrF=(bool)$fstR->fetchColumn(); ?>
                <?php if (!$alrF): ?>
                  <form method="POST"><input type="hidden" name="action" value="follow"><input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>"><input type="hidden" name="handle" value="<?= h($rf['handle']) ?>"><input type="hidden" name="_back" value="<?= h($base.'?page=remote_profile&handle='.urlencode($remoteHandle).'&tab=followers') ?>"><button class="btn btn-primary btn-sm">+ Follow</button></form>
                <?php else: ?><span class="remote-badge">✓ Following</span><?php endif ?>
              </div>
            <?php endif ?>
          </div>
        <?php endforeach; endif ?>
      <?php endif ?>
    </div>
  <?php endif ?>
</div>

<?php elseif ($page==='post' && $singlePost): $p=$singlePost;
  $actor='local:'.($cu['username']??'');
  $liked=false;
  if (isLoggedIn()) { $ls=db()->prepare("SELECT COUNT(*) FROM likes WHERE post_id=? AND actor=?"); $ls->execute([$p['id'],$actor]); $liked=(bool)$ls->fetchColumn(); }
  $cs2=db()->prepare("SELECT COUNT(*) FROM comments WHERE post_id=?"); $cs2->execute([$p['id']]); $cCount=(int)$cs2->fetchColumn();
?>
<div class="mt-12" style="max-width:640px;margin-top:12px">
  <div class="card">
    <article class="post" itemscope itemtype="https://schema.org/SocialMediaPosting">
      <meta itemprop="datePublished" content="<?= date('c', $p['created_at']) ?>">
      <meta itemprop="dateModified" content="<?= date('c', $p['updated_at']) ?>">
      <link itemprop="url" href="<?= h($base.'?page=post&id='.$p['id']) ?>">
      <div class="post-header">
        <?= avatarEl($myAvatarUrl,$cu['name']??'',38) ?>
        <div class="post-meta"><a class="post-author-name" href="<?= h($base.'?page=profile') ?>" itemprop="author"><?= h($cu['name']??'') ?></a><div class="post-author-handle">@<?= h($cu['username']??'') ?> · <?= timeEl($p['created_at']) ?></div></div>
        <?php if (isLoggedIn()): ?>
        <div class="post-owner-actions">
          <button class="btn btn-secondary btn-sm" onclick="openEditModal(<?= $p['id'] ?>,<?= htmlspecialchars(json_encode($p['content']),ENT_QUOTES) ?>)" aria-label="Edit post">✏️ Edit</button>
          <form method="POST" style="display:inline" onsubmit="return confirm('Delete this post?')"><input type="hidden" name="action" value="delete_post"><input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>"><input type="hidden" name="post_id" value="<?= $p['id'] ?>"><button class="btn btn-danger btn-sm" aria-label="Delete post">🗑</button></form>
        </div>
        <?php endif ?>
      </div>
      <div class="post-content" itemprop="text"><?= linkify($p['content']) ?></div>
      <?php if ($p['image_path']): ?><div class="post-image"><img src="<?= h($base.'?file=post_img&id='.$p['id']) ?>" alt="Post image by <?= h($cu['name']??'') ?>" itemprop="image"></div><?php endif ?>
      <div class="post-actions">
        <?php if (isLoggedIn()): ?>
        <form method="POST" style="display:inline"><input type="hidden" name="action" value="<?= $liked?'unlike':'like' ?>"><input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>"><input type="hidden" name="post_id" value="<?= $p['id'] ?>"><button class="action-btn <?= $liked?'liked':'' ?>" aria-label="<?= $liked?'Unlike':'Like' ?> post">❤️ <?= $p['likes'] ?></button></form>
        <?php else: ?><span class="action-stat">❤️ <?= $p['likes'] ?></span><?php endif ?>
        <span class="action-stat">💬 <?= $cCount ?></span>
        <button class="action-btn share-btn" onclick="sharePost('<?= h($base.'?page=post&id='.$p['id']) ?>')" aria-label="Share post">🔗 Share</button>
      </div>
    </article>
    <?php if ($comments): ?>
      <section class="comment-list" aria-label="Comments">
        <?php foreach ($comments as $c): $ca=resolveCommentAuthor($c,$myAvatarUrl,$base); ?>
          <div class="comment-item">
            <?= avatarEl($ca['avatar'],$ca['display'],32) ?>
            <div class="comment-body">
              <?php if ($ca['profile_url']): ?><a class="comment-author" href="<?= h($ca['profile_url']) ?>"><?= h($ca['display']) ?></a>
              <?php else: ?><span class="comment-author"><?= h($ca['display']) ?></span><?php endif ?>
              <?php if ($c['is_remote']): ?><span class="remote-badge">remote</span><?php endif ?>
              <div class="comment-text"><?= linkify($c['content']) ?></div>
              <div class="comment-time"><?= timeEl($c['created_at']) ?></div>
            </div>
          </div>
        <?php endforeach ?>
      </section>
    <?php endif ?>
    <?php if (isLoggedIn()): ?>
    <div class="comment-form">
      <form method="POST">
        <input type="hidden" name="action" value="comment"><input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>"><input type="hidden" name="post_id" value="<?= $singlePost['id'] ?>">
        <div class="flex gap-8"><?= avatarEl($myAvatarUrl,$cu['name']??'',34) ?><textarea class="compose-textarea" name="comment" placeholder="Write a comment…" rows="2" maxlength="500" style="min-height:56px" aria-label="Comment"></textarea></div>
        <div style="text-align:right;margin-top:8px"><button class="btn btn-primary btn-sm">Comment</button></div>
      </form>
    </div>
    <?php else: ?>
    <div class="guest-cta">🔒 <a href="<?= h($base.'?page=login') ?>">Sign in</a> to leave a comment.</div>
    <?php endif ?>
  </div>
</div>

<?php elseif ($page==='remote_post'): ?>
<div class="remote-post-wrap mt-12">
  <div style="margin-bottom:10px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
    <a href="javascript:history.back()" class="btn btn-secondary btn-sm">← Back</a>
    <span class="remote-indicator">📡 <?= h(parse_url($remoteBase,PHP_URL_HOST)?:$remoteHandle) ?></span>
  </div>
  <?php if (!$remotePostData||isset($remotePostData['error'])): ?>
    <div class="card p-16">
      <div class="alert alert-err">Could not load this post.</div>
      <?php if (!empty($remoteBase)&&!empty($_GET['id'])): ?><div class="mt-8"><a href="<?= h($remoteBase.'?page=post&id='.(int)$_GET['id']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-sm">Try on original server ↗</a></div><?php endif ?>
    </div>
  <?php else: $rp2=$remotePostData; $rpAuth=$remoteProfileData??[]; $rpAvatarUrl=$rpAuth['avatar']??''; $rpAuthorUsername=$rpAuth['username']??null; ?>
    <div class="card">
      <article class="post">
        <div class="post-header">
          <?= avatarEl($rpAvatarUrl,$rpAuth['name']??'?',38) ?>
          <div class="post-meta"><a class="post-author-name" href="<?= h($base.'?page=remote_profile&handle='.urlencode($remoteHandle)) ?>"><?= h($rpAuth['name']??'Unknown') ?></a><div class="post-author-handle"><?= h($remoteHandle) ?> · <?= timeEl((int)($rp2['created_at']??0)) ?></div></div>
        </div>
        <div class="post-content"><?= linkify($rp2['content']??'') ?></div>
        <?php if (!empty($rp2['image_url'])): ?><div class="post-image"><img src="<?= h($rp2['image_url']) ?>" alt="Remote post image" onerror="this.parentNode.style.display='none'"></div><?php endif ?>
        <div class="post-actions">
          <?php if (isLoggedIn()): ?>
          <button class="action-btn" id="remLikeBtn" onclick="remotePostLike(this)">❤️ <span id="remLikeCount"><?= (int)($rp2['likes']??0) ?></span></button>
          <?php else: ?><span class="action-stat">❤️ <?= (int)($rp2['likes']??0) ?></span><?php endif ?>
          <span class="action-stat">💬 <span id="remCommentCount"><?= count($rp2['comments']??[]) ?></span></span>
          <button class="action-btn share-btn" onclick="sharePost(window.location.href)" aria-label="Share">🔗 Share</button>
          <a class="action-btn" href="<?= h($remoteBase.'?page=post&id='.(int)($rp2['id']??0)) ?>" target="_blank" rel="noopener noreferrer" style="font-size:11px">Original ↗</a>
        </div>
      </article>
      <section id="remoteCommentList" class="comment-list" aria-label="Comments">
        <?php if (!empty($rp2['comments'])): foreach ($rp2['comments'] as $rc):
          $rcIsAuthor=isset($rpAuthorUsername)&&($rc['author']??'')===$rpAuthorUsername;
          $rcAvatar=$rcIsAuthor?$rpAvatarUrl:'';
          $rcDisplay=$rc['author']??'Unknown';
          $rcProfileUrl=preg_match('/^@[^@\s]+@[^\s]+$/',$rcDisplay)?$base.'?page=remote_profile&handle='.urlencode($rcDisplay):$base.'?page=remote_profile&handle='.urlencode($remoteHandle);
        ?>
          <div class="comment-item">
            <?= avatarEl($rcAvatar,$rcDisplay,30) ?>
            <div class="comment-body">
              <a class="comment-author" href="<?= h($rcProfileUrl) ?>"><?= h($rcDisplay) ?></a>
              <?php if (!empty($rc['is_remote'])): ?><span class="remote-badge">remote</span><?php endif ?>
              <div class="comment-text"><?= linkify($rc['content']??'') ?></div>
              <div class="comment-time"><?= timeEl((int)($rc['created_at']??0)) ?></div>
            </div>
          </div>
        <?php endforeach; endif ?>
      </section>
      <?php if (isLoggedIn()): ?>
      <div class="comment-form">
        <div class="alert alert-info" style="margin-bottom:10px;font-size:12px">💬 Commenting as <code><?= h($handle) ?></code> on the original server</div>
        <div class="flex gap-8"><?= avatarEl($myAvatarUrl,$cu['name']??'',34) ?><textarea class="compose-textarea" id="remoteCommentText" placeholder="Write a comment…" rows="2" maxlength="500" style="min-height:56px" aria-label="Comment"></textarea></div>
        <div style="text-align:right;margin-top:8px"><button class="btn btn-primary btn-sm" id="remoteCommentBtn" onclick="submitRemoteComment()">Post comment</button></div>
        <div id="remoteCommentError" class="alert alert-err mt-8" style="display:none" role="alert"></div>
      </div>
      <?php else: ?>
      <div class="guest-cta">🔒 <a href="<?= h($base.'?page=login') ?>">Sign in</a> to interact with this post.</div>
      <?php endif ?>
    </div>
  <?php endif ?>
</div>

<?php endif; /* page switch */ ?>
</div>
</main>

<!-- Edit modal -->
<div class="modal-overlay" id="editModal" role="dialog" aria-modal="true" aria-label="Edit post">
  <div class="modal">
    <div class="modal-title">Edit Post <button class="modal-close" onclick="closeEditModal()" aria-label="Close">✕</button></div>
    <form method="POST">
      <input type="hidden" name="action" value="edit_post"><input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>"><input type="hidden" name="post_id" id="editPostId">
      <div class="form-group"><textarea class="form-input" name="content" id="editContent" rows="4" maxlength="<?= MAX_CHARS ?>" oninput="updateEditCount()" aria-label="Post content"></textarea><div class="form-hint text-sm" id="editCharCount" aria-live="polite">0 / <?= MAX_CHARS ?></div></div>
      <div style="display:flex;gap:8px;justify-content:flex-end"><button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
    </form>
  </div>
</div>
<div id="toast" role="status" aria-live="polite"></div>

<?php endif; /* not firstRun + not login page */ ?>

<script>
const MAX       = <?= MAX_CHARS ?>;
const BASE      = <?= json_encode($base) ?>;
const CSRF      = <?= json_encode($csrf_token) ?>;
const HNDL      = <?= json_encode($isFirstRun ? '' : ($handle ?? '')) ?>;
const LOGGED_IN = <?= json_encode(!$isFirstRun && isLoggedIn()) ?>;

// ── UI helpers ────────────────────────────────────────────────────────────────
function updateCharCount(){const n=document.getElementById('composeText')?.value.length||0,el=document.getElementById('charCount');if(el){el.textContent=n+' / '+MAX;el.className='char-count'+(n>MAX*.85?' warn':'')+(n>=MAX?' over':'');}}
function switchPostType(t){document.getElementById('postTypeInput').value=t;document.getElementById('tab-text').classList.toggle('active',t==='text');document.getElementById('tab-image').classList.toggle('active',t==='image');document.getElementById('imageUploadZone')?.classList.toggle('show',t==='image');}
function previewImg(inp){if(!inp.files||!inp.files[0])return;const r=new FileReader();r.onload=e=>{const p=document.getElementById('imgPreview');if(p){p.src=e.target.result;p.style.display='block';}};r.readAsDataURL(inp.files[0]);}
function handleImgDrop(e){e.preventDefault();document.getElementById('imageUploadZone')?.classList.remove('drag');if(e.dataTransfer?.files.length){const dt=new DataTransfer();dt.items.add(e.dataTransfer.files[0]);const inp=document.getElementById('imgFile');inp.files=dt.files;previewImg(inp);}}
function previewCover(inp){if(!inp.files||!inp.files[0])return;const r=new FileReader();r.onload=e=>{const img=document.getElementById('coverPreviewImg');if(img){img.src=e.target.result;img.style.display='block';}};r.readAsDataURL(inp.files[0]);}
function openEditModal(id,c){document.getElementById('editPostId').value=id;document.getElementById('editContent').value=c;updateEditCount();document.getElementById('editModal').classList.add('open');}
function closeEditModal(){document.getElementById('editModal').classList.remove('open');}
function updateEditCount(){const n=document.getElementById('editContent')?.value.length||0,el=document.getElementById('editCharCount');if(el)el.textContent=n+' / '+MAX;}
document.getElementById('editModal')?.addEventListener('click',e=>{if(e.target===e.currentTarget)closeEditModal();});
function toast(msg,bg='#1f2328'){const t=document.getElementById('toast');if(!t)return;t.textContent=msg;t.style.background=bg;t.classList.add('show');setTimeout(()=>t.classList.remove('show'),3000);}
function toastOk(m){toast(m,'#1a7f37');}function toastErr(m){toast(m,'#cf222e');}
function sharePost(url){if(navigator.share)navigator.share({url});else navigator.clipboard.writeText(url).then(()=>toast('Link copied!'));}
function copyHandle(){navigator.clipboard.writeText(HNDL).then(()=>toast('Handle copied!'));}
function addLinkField(){const l=document.getElementById('linkList');if(!l)return;const d=document.createElement('div');d.className='link-item';d.innerHTML='<input class="form-input" type="url" name="links[]" placeholder="https://…" style="min-width:0"><button type="button" class="btn btn-secondary btn-sm" onclick="this.parentNode.remove()">✕</button>';l.appendChild(d);}

// ── Rendering utils ───────────────────────────────────────────────────────────
function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
function agoJS(ts){const d=Math.max(0,Math.floor(Date.now()/1000)-ts);if(d<60)return d+'s';if(d<3600)return Math.floor(d/60)+'m';if(d<86400)return Math.floor(d/3600)+'h';if(d<604800)return Math.floor(d/86400)+'d';return new Date(ts*1000).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});}
function timeElJS(ts){const iso=new Date(ts*1000).toISOString(),title=new Date(ts*1000).toLocaleString('en-US',{month:'long',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'});return`<time datetime="${iso}" title="${title}">${agoJS(ts)}</time>`;}
function linkifyJS(t){return String(t).split(/(https?:\/\/\S+)/gi).map((p,i)=>{if(i%2===0)return p.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');const u=p.replace(/[.,;:!?)'"\}>]+$/,''),d=u.length>55?u.slice(0,52)+'…':u;return`<a href="${u.replace(/"/g,'&quot;')}" target="_blank" rel="noopener noreferrer" style="word-break:break-all">${esc(d)}</a>`;}).join('');}
function avatarElJS(url,name,size){const init=esc(((name||'?').charAt(0)).toUpperCase()),fs=Math.floor(size*.4);if(url){const fb=`<div class='avatar-placeholder' style='width:${size}px;height:${size}px;font-size:${fs}px'>${init}</div>`;return`<img src="${esc(url)}" class="avatar" width="${size}" height="${size}" alt="${esc(name)}" loading="lazy" onerror="this.outerHTML='${fb.replace(/'/g,"\\'")}'">`;}return`<div class="avatar-placeholder" style="width:${size}px;height:${size}px;font-size:${fs}px" aria-label="${esc(name)}">${init}</div>`;}

// ── Remote interaction ────────────────────────────────────────────────────────
async function remoteInteractBtn(btn,type,remoteBase,postId,actor){
  btn.classList.add('interaction-loading');const span=btn.querySelector('span');
  for(const s of['https','http']){
    try{const r=await fetch(remoteBase.replace(/^https?/,s)+'?api=interact',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({type,post_id:parseInt(postId),actor})});
      if(r.ok){const d=await r.json();if(d.ok){if(span&&type==='like'&&d.likes!==undefined)span.textContent=d.likes;btn.classList.remove('interaction-loading');toastOk(type==='like'?'Liked! ❤️':'Done!');return;}}
    }catch(e){}
  }
  btn.classList.remove('interaction-loading');toastErr('Could not reach remote server.');
}

<?php if ($page==='remote_post'&&!empty($remoteBase)&&!empty($remotePostData)&&!isset($remotePostData['error'])): ?>
const REMOTE_BASE       = <?= json_encode($remoteBase) ?>;
const REMOTE_POST_ID    = <?= (int)($remotePostData['id']??0) ?>;
const REMOTE_HANDLE     = <?= json_encode($remoteHandle) ?>;
const REMOTE_AVATAR     = <?= json_encode($remoteProfileData['avatar']??'') ?>;
const REMOTE_AUTH_UNAME = <?= json_encode($remoteProfileData['username']??null) ?>;

async function remotePostLike(btn){
  btn.classList.add('interaction-loading');const cnt=document.getElementById('remLikeCount');
  for(const s of['https','http']){
    try{const r=await fetch(REMOTE_BASE.replace(/^https?/,s)+'?api=interact',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({type:'like',post_id:REMOTE_POST_ID,actor:HNDL})});
      if(r.ok){const d=await r.json();if(d.ok){if(cnt&&d.likes!==undefined)cnt.textContent=d.likes;btn.classList.add('liked');btn.classList.remove('interaction-loading');toastOk('Liked! ❤️');return;}}
    }catch(e){}
  }
  btn.classList.remove('interaction-loading');toastErr('Could not reach remote server.');
}

async function submitRemoteComment(){
  const text=document.getElementById('remoteCommentText')?.value.trim();
  const errEl=document.getElementById('remoteCommentError'),btn=document.getElementById('remoteCommentBtn');
  if(errEl)errEl.style.display='none';
  if(!text){if(errEl){errEl.textContent='Comment cannot be empty.';errEl.style.display='block';}return;}
  btn.disabled=true;btn.textContent='Posting…';let ok=false;
  for(const s of['https','http']){
    try{const r=await fetch(REMOTE_BASE.replace(/^https?/,s)+'?api=interact',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({type:'comment',post_id:REMOTE_POST_ID,actor:HNDL,content:text})});
      if(r.ok){const d=await r.json();if(d.ok){ok=true;document.getElementById('remoteCommentText').value='';toastOk('Comment posted! ✓');await refreshRemoteComments();break;}}
    }catch(e){}
  }
  if(!ok&&errEl){errEl.textContent='Failed. Remote server may be unreachable.';errEl.style.display='block';}
  btn.disabled=false;btn.textContent='Post comment';
}

async function refreshRemoteComments(){
  for(const s of['https','http']){
    try{const r=await fetch(REMOTE_BASE.replace(/^https?/,s)+'?api=post&id='+REMOTE_POST_ID);
      if(r.ok){const d=await r.json();if(!d.error){renderRemoteComments(d.comments||[]);const lc=document.getElementById('remLikeCount');if(lc)lc.textContent=d.likes||0;const cc=document.getElementById('remCommentCount');if(cc)cc.textContent=(d.comments||[]).length;return;}}
    }catch(e){}
  }
}

function renderRemoteComments(comments){
  const el=document.getElementById('remoteCommentList');if(!el)return;
  const profileUrl=BASE+'?page=remote_profile&handle='+encodeURIComponent(REMOTE_HANDLE);
  el.innerHTML=comments.map(c=>{
    const isAuthor=REMOTE_AUTH_UNAME&&c.author===REMOTE_AUTH_UNAME;
    const cAvatar=isAuthor?REMOTE_AVATAR:'';const cDisplay=c.author||'Unknown';
    const isFed=/^@[^@\s]+@[^\s]+$/.test(cDisplay);
    const cUrl=isFed?BASE+'?page=remote_profile&handle='+encodeURIComponent(cDisplay):profileUrl;
    return`<div class="comment-item">${avatarElJS(cAvatar,cDisplay,30)}<div class="comment-body"><a class="comment-author" href="${esc(cUrl)}">${esc(cDisplay)}</a>${c.is_remote?'<span class="remote-badge">remote</span>':''}<div class="comment-text">${linkifyJS(c.content||'')}</div><div class="comment-time">${timeElJS(c.created_at||0)}</div></div></div>`;
  }).join('');
}
<?php endif ?>

<?php if ($page==='home'): ?>
// ── Feed state ────────────────────────────────────────────────────────────────
let feedBefore   = 9999999999;
let feedNewest   = 0;
let feedLoading  = false;
let feedHasMore  = true;
const renderedKeys = new Set();

const MY_AVATAR = <?= json_encode($myAvatarUrl) ?>;
const MY_NAME   = <?= json_encode($cu['name'] ?? '') ?>;
const MY_UNAME  = <?= json_encode($cu['username'] ?? '') ?>;

function postKey(p){return p._key||((p._src==='local'?'local':(p._handle||'?'))+':'+p.id);}

function renderPost(p){
  const src    = p._src||'remote';
  const author = p._author||p.author||{};
  const name   = src==='local'?MY_NAME  :(author.name||(p._handle?p._handle.split('@')[1]||'Remote':'Remote'));
  const uname  = src==='local'?MY_UNAME :(author.username||'');
  const avUrl  = src==='local'?MY_AVATAR:(author.avatar||'');
  const profUrl= src==='local'?(BASE+'?page=profile'):(p._profile_url||'');
  const postUrl= p.post_url||(src==='local'?BASE+'?page=post&id='+p.id:'#');
  const imgHtml= p.image_url?`<div class="post-image"><img src="${esc(p.image_url)}" loading="lazy" alt="Post image by ${esc(name)}" onerror="this.parentNode.style.display='none'"></div>`:'';
  const remBadge=src==='remote'?`<a href="${esc(profUrl)}" class="remote-badge" style="margin-bottom:6px;display:inline-flex;text-decoration:none;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">📡 ${esc(p._handle||'remote')}</a>`:'';
  const edited =(p.updated_at&&p.updated_at>p.created_at)?'<span class="remote-badge">edited</span>':'';
  const nameEl =profUrl?`<a class="post-author-name" href="${esc(profUrl)}">${esc(name)}</a>`:`<span class="post-author-name">${esc(name)}</span>`;
  const lc=p._last_comment;
  const commentPreview=lc?`<div class="post-last-comment"><span class="post-last-comment-author">${esc(lc.author)}</span><div class="post-last-comment-text">${esc(lc.content)}</div></div>`:'';
  let likeHtml, commentHtml;
  if(src==='local'&&LOGGED_IN){
    likeHtml=`<form method="POST" style="display:inline"><input type="hidden" name="action" value="${p._liked?'unlike':'like'}"><input type="hidden" name="_csrf" value="${CSRF}"><input type="hidden" name="post_id" value="${p.id}"><button class="action-btn${p._liked?' liked':''}" aria-label="${p._liked?'Unlike':'Like'} post">❤️ ${p.likes||0}</button></form>`;
  }else if(src==='remote'&&LOGGED_IN){
    const rb=p._remote_base||'';
    likeHtml=`<button class="action-btn" onclick="remoteInteractBtn(this,'like','${esc(rb)}','${p.id}','${esc(HNDL)}')" aria-label="Like post">❤️ <span>${p.likes||0}</span></button>`;
  }else{
    likeHtml=`<span class="action-stat">❤️ ${p.likes||0}</span>`;
  }
  commentHtml=`<a class="action-btn" href="${esc(postUrl)}" aria-label="Comments">💬 ${src==='local'?(p._comments||0):(p.comment_count||(p.comments?p.comments.length:0))}${src==='remote'?' · Open':''}</a>`;
  const shareHtml=`<button class="action-btn share-btn" onclick="sharePost('${esc(postUrl)}')" aria-label="Share post">🔗</button>`;
  return`<article class="post" itemscope itemtype="https://schema.org/SocialMediaPosting" data-post-key="${esc(postKey(p))}" data-ts="${p.created_at||0}">
    <meta itemprop="datePublished" content="${new Date((p.created_at||0)*1000).toISOString()}">
    ${remBadge}
    <div class="post-header">${avatarElJS(avUrl,name,38)}<div class="post-meta">${nameEl}<div class="post-author-handle">${uname?'@'+esc(uname):''}</div></div><span class="post-time">${timeElJS(p.created_at||0)}</span>${edited}</div>
    <div class="post-content" itemprop="text">${linkifyJS(p.content||'')}</div>${imgHtml}${commentPreview}
    <div class="post-actions">${likeHtml}${commentHtml}${shareHtml}</div>
  </article>`;
}

async function loadFeed(append=false){
  if(feedLoading)return;
  if(append&&!feedHasMore)return;
  feedLoading=true;
  const spinner=document.getElementById('feedSpinner');
  const lmWrap=document.getElementById('loadMoreWrap');
  const lmBtn=document.getElementById('loadMoreBtn');
  const status=document.getElementById('feedStatus');
  if(spinner)spinner.style.display='block';
  if(lmWrap)lmWrap.style.display='none';
  try{
    const data=await(await fetch(`?ajax=feed&mode=before&before=${feedBefore}`)).json();
    const container=document.getElementById('feedContainer');if(!container){feedLoading=false;return;}
    const posts=data.posts||[];
    if(!posts.length&&!append){
      container.innerHTML='<div class="empty-state"><div class="empty-icon">📭</div>No posts yet.</div>';
      feedHasMore=false;
    }else{
      let added=0;
      posts.forEach(p=>{
        const k=postKey(p);if(renderedKeys.has(k))return;
        renderedKeys.add(k);
        container.insertAdjacentHTML('beforeend',renderPost(p));
        added++;
        const ts=p.created_at||0;
        if(ts<feedBefore)feedBefore=ts-1;
        if(ts>feedNewest)feedNewest=ts;
      });
      feedHasMore=!!data.has_more;
      if(status)status.textContent=renderedKeys.size+' post'+(renderedKeys.size!==1?'s':'')+' loaded'+(added&&append?' ('+added+' new)':'')+(feedHasMore?'':' · all loaded');
    }
    if(lmWrap)lmWrap.style.display=feedHasMore?'block':'none';
    if(lmBtn)lmBtn.textContent='Load 20 more';
  }catch(e){const s=document.getElementById('feedStatus');if(s)s.textContent='Could not load feed.';}
  feedLoading=false;
  if(spinner)spinner.style.display='none';
}

function loadMore(){loadFeed(true);}

async function checkNewPosts(){
  if(feedLoading||!feedNewest)return;
  feedLoading=true;
  const btn=document.getElementById('checkNewBtn');
  if(btn){btn.disabled=true;btn.textContent='⏳ Checking…';}
  try{
    const data=await(await fetch(`?ajax=feed&mode=after&after=${feedNewest}`)).json();
    const posts=data.posts||[];const container=document.getElementById('feedContainer');
    let added=0;
    posts.sort((a,b)=>(a.created_at||0)-(b.created_at||0));
    posts.forEach(p=>{
      const k=postKey(p);if(renderedKeys.has(k))return;
      renderedKeys.add(k);
      container.insertAdjacentHTML('afterbegin',renderPost(p));
      added++;
      const ts=p.created_at||0;if(ts>feedNewest)feedNewest=ts;
    });
    const status=document.getElementById('feedStatus');
    if(status)status.textContent=added?added+' new post'+(added!==1?'s':'')+' added to top.':'Feed is up to date.';
    if(btn)btn.style.display='none';
  }catch(e){console.warn('Refresh error:',e);}
  feedLoading=false;
  if(btn){btn.disabled=false;btn.textContent='⬆ New posts';}
}

async function syncAndRefresh(){
  if(!LOGGED_IN)return;
  const btn=document.getElementById('refreshBtn');const status=document.getElementById('feedStatus');
  if(btn){btn.disabled=true;btn.textContent='⏳ Syncing…';}
  if(status)status.textContent='Syncing followed instances…';
  try{
    await fetch('?',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`action=refresh_feed&_csrf=${CSRF}`});
    await checkNewPosts();
  }catch(e){if(status)status.textContent='Sync failed.';}
  if(btn){btn.disabled=false;btn.textContent='🔄 Sync';}
}

setInterval(async()=>{
  if(feedLoading||!feedNewest)return;
  try{
    const data=await(await fetch(`?ajax=feed&mode=after&after=${feedNewest}`)).json();
    const count=(data.posts||[]).filter(p=>!renderedKeys.has(postKey(p))).length;
    const btn=document.getElementById('checkNewBtn');
    if(btn&&count>0){btn.textContent=`⬆ ${count} new post${count!==1?'s':''}`;btn.style.display='inline-flex';}
  }catch(e){}
},60000);

loadFeed(false);
<?php endif ?>
</script>
</body>
</html>
