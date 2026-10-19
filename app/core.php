<?php
/**
 * XFedi — personal ActivityPub server. See docs/ARCHITECTURE.md.
 * PHP 8.2+ / curl / openssl / pdo_sqlite / mbstring / fileinfo.
 * Configuration: set XFEDI_URL to the FINAL public HTTPS URL of this file,
 * e.g. https://social.example/index.php (not a URL ending in a slash).
 * Optionally set XFEDI_DATA_DIR to a writable directory OUTSIDE web root.
 * Run: php index.php worker  (from cron once per minute).
 * Do not change XFEDI_URL or the account username after federation begins.
 * Copyright (c) 2026. MIT license. No external PHP libraries. PHP web and protocol code; Python job supervisor.
 */
declare(strict_types=1);

// Files in the private Docker volume (SQLite, images and lockfiles) default to owner-only.
umask(0077);

const APP_NAME = 'XFedi';
const APP_VERSION = '2.1.0';
const AS_CTX = 'https://www.w3.org/ns/activitystreams';
const AS_PUBLIC = 'https://www.w3.org/ns/activitystreams#Public';
const POST_LIMIT = 500;
const MAX_REMOTE_BYTES = 1048576;

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

function cfgUrl(): string { return rtrim((string)(getenv('XFEDI_URL') ?: 'https://CHANGE-ME.example/index.php'), '/'); }
function dataDir(): string { return rtrim((string)(getenv('XFEDI_DATA_DIR') ?: dirname(__DIR__,2).'/xsukax-fediverse-private'), '/'); }
function identityUrl(string $query=''): string { return cfgUrl().($query !== '' ? '?'.$query : ''); }
function localUrl(string $query=''): string { return uiUrl($query); }
function actorUrl(): string { return identityUrl('ap=actor'); }
function inboxUrl(): string { return identityUrl('ap=inbox'); }
function noteUrl(int $id): string { return identityUrl('ap=note&id='.$id); }
function activityUrl(string $ref): string { return identityUrl('ap=activity&ref='.rawurlencode($ref)); }
function hostName(): string { return strtolower((string)parse_url(cfgUrl(), PHP_URL_HOST)); }
function readyConfig(): bool {
    return (bool)preg_match('~^https://[a-z0-9.-]+(?:/[^?#]+)\.php$~i', cfgUrl()) &&
           !str_contains(cfgUrl(), 'CHANGE-ME') && parse_url(cfgUrl(), PHP_URL_PORT) === null;
}
function escape(string $s): string { return htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function jenc(mixed $v): string { return json_encode($v, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR); }
function nowIso(?int $time=null): string { return gmdate('Y-m-d\TH:i:s\Z', $time ?? time()); }
function fromIso(string $s): int { return strtotime($s) ?: time(); }
function clip(string $s, int $len): string { return mb_substr(trim($s), 0, $len); }
function stringField(mixed $val): string { return is_string($val) ? $val : ''; }
function firstId(mixed $x): string {
    if (is_string($x)) return $x;
    if (!is_array($x)) return '';
    if (array_is_list($x)) {foreach ($x as $item) if ($id=firstId($item)) return $id;return '';}
    return stringField($x['id']??$x['href']??'');
}
function fail(string $message, int $status=400): never { http_response_code($status); header('Content-Type: text/plain; charset=utf-8'); echo $message; exit; }
function jsonResponse(mixed $data, int $status=200, string $type='application/activity+json'): never {
    http_response_code($status); header('Content-Type: '.$type.'; charset=utf-8');
    header('Cache-Control: no-store'); echo jenc($data); exit;
}
function redirectQuery(string $query): never { if (db()->inTransaction()) db()->commit(); header('Location: '.localUrl($query), true, 303); exit; }
function redirectTo(string $page='home'): never { redirectQuery($page === 'home' ? '' : 'page='.rawurlencode($page)); }
/** Best-effort client IP: when REMOTE_ADDR is a private/loopback proxy (Caddy,
 *  cloudflared, another container), trust the first X-Forwarded-For entry it added. */
function clientIp(): string {
    $remote=(string)($_SERVER['REMOTE_ADDR']??'unknown');
    // Cloudflare sets this header; enable only when the origin is tunnel-only.
    if ((getenv('XFEDI_TRUST_PROXY')?:'0')==='1') {
        $forward=trim((string)($_SERVER['HTTP_CF_CONNECTING_IP']??''));
        if (filter_var($forward,FILTER_VALIDATE_IP)) return $forward;
    }
    return $remote;
}
function db(): PDO {
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $dir=dataDir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Cannot create data directory');
    $pdo=new PDO('sqlite:'.$dir.'/social.sqlite', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT=>5]);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON');
    return $pdo;
}
function initDb(): void {
    db()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
 id INTEGER PRIMARY KEY CHECK(id=1), username TEXT NOT NULL UNIQUE,
 name TEXT NOT NULL, bio TEXT NOT NULL DEFAULT '', password_hash TEXT NOT NULL,
 private_key TEXT NOT NULL, public_key TEXT NOT NULL, avatar TEXT NOT NULL DEFAULT '',
 cover TEXT NOT NULL DEFAULT '', created_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS posts (
 id INTEGER PRIMARY KEY AUTOINCREMENT, content TEXT NOT NULL, image TEXT NOT NULL DEFAULT '',
 alt TEXT NOT NULL DEFAULT '', in_reply_to TEXT NOT NULL DEFAULT '',
 created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, deleted INTEGER NOT NULL DEFAULT 0);
CREATE TABLE IF NOT EXISTS remote_actors (
 id TEXT PRIMARY KEY, handle TEXT NOT NULL DEFAULT '', username TEXT NOT NULL DEFAULT '',
 name TEXT NOT NULL DEFAULT '', bio TEXT NOT NULL DEFAULT '', inbox TEXT NOT NULL,
 outbox TEXT NOT NULL DEFAULT '', followers TEXT NOT NULL DEFAULT '', shared_inbox TEXT NOT NULL DEFAULT '',
 avatar TEXT NOT NULL DEFAULT '', key_id TEXT NOT NULL, public_key TEXT NOT NULL,
 fetched_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS follows (
 actor_id TEXT PRIMARY KEY, follow_id TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending', created_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS followers (
 actor_id TEXT PRIMARY KEY, follow_id TEXT NOT NULL, created_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS remote_notes (
 id TEXT PRIMARY KEY, author_id TEXT NOT NULL, content TEXT NOT NULL DEFAULT '',
 content_html TEXT NOT NULL DEFAULT '', summary TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '', image TEXT NOT NULL DEFAULT '',
 in_reply_to TEXT NOT NULL DEFAULT '', created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
 deleted INTEGER NOT NULL DEFAULT 0);
CREATE INDEX IF NOT EXISTS idx_remote_notes_created ON remote_notes(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_remote_notes_author ON remote_notes(author_id);
CREATE TABLE IF NOT EXISTS likes_remote (
 actor_id TEXT NOT NULL, post_id INTEGER NOT NULL, activity_id TEXT NOT NULL,
 PRIMARY KEY (actor_id,post_id));
CREATE TABLE IF NOT EXISTS likes_local (
 note_id TEXT PRIMARY KEY, activity_id TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS activities (ref TEXT PRIMARY KEY, json TEXT NOT NULL, created_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS deliveries (
 id INTEGER PRIMARY KEY AUTOINCREMENT, destination TEXT NOT NULL, activity_id TEXT NOT NULL,
 json TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, run_after INTEGER NOT NULL,
 status TEXT NOT NULL DEFAULT 'pending', last_error TEXT NOT NULL DEFAULT '',
 UNIQUE(destination, activity_id));
CREATE INDEX IF NOT EXISTS idx_deliveries_next ON deliveries(status,run_after);
CREATE TABLE IF NOT EXISTS seen_activities (id TEXT PRIMARY KEY, received_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS login_attempts (ip_hash TEXT PRIMARY KEY, attempts INTEGER NOT NULL, window_start INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS notifications (
 id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, actor_id TEXT NOT NULL,
 object_id TEXT NOT NULL DEFAULT '', created_at INTEGER NOT NULL, is_read INTEGER NOT NULL DEFAULT 0);
CREATE INDEX IF NOT EXISTS idx_notifications_read ON notifications(is_read);
SQL);
    migrateV2();
    // Existing installations can upgrade without dropping posts or identities.
    $columns=array_column(db()->query('PRAGMA table_info(remote_notes)')->fetchAll(),'name');
    if (!in_array('content_html',$columns,true)) db()->exec("ALTER TABLE remote_notes ADD COLUMN content_html TEXT NOT NULL DEFAULT ''");
}
function queryOne(string $sql, array $args=[]): ?array { $q=db()->prepare($sql); $q->execute($args); return $q->fetch() ?: null; }
function queryAll(string $sql, array $args=[]): array { $q=db()->prepare($sql); $q->execute($args); return $q->fetchAll(); }
function executeSql(string $sql, array $args=[]): void { $q=db()->prepare($sql); $q->execute($args); }
function user(): ?array { return queryOne('SELECT * FROM users WHERE id=1'); }
function csrf(): string { if (!isset($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(24)); return $_SESSION['csrf']; }
function mustPost(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !hash_equals(csrf(), (string)($_POST['_csrf'] ?? ''))) fail('Invalid CSRF token', 403);
}
function signedIn(): bool {
    if (!isset($_SESSION['uid']) || $_SESSION['uid']!==1 || !($u=user())) return false;
    $stamp=hash('sha256',$u['password_hash']);
    if (!isset($_SESSION['auth_stamp'])) $_SESSION['auth_stamp']=$stamp; // migrate v1.2 sessions
    return hash_equals($stamp,(string)$_SESSION['auth_stamp']);
}
function requireLogin(): void { if (!signedIn()) fail('Please sign in', 403); }
function humanTime(int $v): string { $n=max(0,time()-$v); return $n<60 ? 'just now' : ($n<3600 ? floor($n/60).'m' : ($n<86400 ? floor($n/3600).'h' : displayTime($v,'M j, Y'))); }
function urlText(string $url): string { return isSafePublicUrl($url) ? $url : ''; }
function remotePlain(mixed $html): string {
    if (!is_string($html)) return '';
    $html=preg_replace('~<\s*(br|/p|/div)\b[^>]*>~i', "\n", $html) ?? $html;
    return clip(html_entity_decode(strip_tags($html), ENT_QUOTES|ENT_HTML5, 'UTF-8'), 6000);
}
/**
 * Render user-written plain text, safely turning web URLs, #hashtags and full
 * @user@domain mentions into links. Raw HTML is never trusted.
 * The same markup is used inside outbound ActivityPub Notes and our local UI.
 */
function socialTokens(string $text): array {
    $pattern = '~(?:https?://|(?<![\pL\pN_@.])www\.)[^\s<>"\x27]+|(?<![\pL\pN_@])@[a-z0-9_][a-z0-9_.-]{0,63}@[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?)+|(?<![\pL\pN_])#[\pL\pN_][\pL\pN_\pM]*~iu';
    preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
    $tokens=[];
    foreach ($matches[0] ?? [] as [$raw,$at]) {
        $kind = str_starts_with($raw,'#') ? 'tag' : (str_starts_with($raw,'@') ? 'mention' : 'link');
        // A sentence-ending punctuation mark is not usually part of a URL.
        $token = $kind==='link' ? rtrim($raw, '.,;:!?)]}') : $raw;
        if ($token==='') continue;
        $tokens[]=['kind'=>$kind, 'text'=>$token, 'offset'=>$at];
    }
    return $tokens;
}
function safeBrowserLink(string $url): bool {
    if (strlen($url)>2048 || str_contains($url, chr(92)) || preg_match('/[\x00-\x20\x7f]/', $url)) return false;
    $p=parse_url($url);
    return is_array($p) && in_array(strtolower((string)($p['scheme']??'')),['http','https'],true)
        && isset($p['host']) && preg_match('/^[a-z0-9.-]+$/iD',(string)$p['host'])
        && !isset($p['user']) && !isset($p['pass']);
}
function socialMarkup(string $text, array $mentionMap=[],bool $federated=false): string {
    $out=''; $pos=0;
    foreach (socialTokens($text) as $tok) {
        $at=$tok['offset']; $word=$tok['text'];
        if ($at<$pos) continue;
        $out.=escape(substr($text,$pos,$at-$pos));
        $safeText=escape($word); $href=''; $cls=''; $newTab=false;
        if ($tok['kind']==='link' && safeBrowserLink(str_starts_with($word,'www.')?'https://'.$word:$word)) {
            $href=str_starts_with($word,'www.')?'https://'.$word:$word; $newTab=true;
        } elseif ($tok['kind']==='tag') {
            $href=localUrl('page=tag&tag='.rawurlencode(substr($word,1)));
            $cls=' class="hashtag"';
        } elseif ($tok['kind']==='mention') {
            $actor=$mentionMap[strtolower($word)]??null;
            if (!is_array($actor)) {
                $actor=queryOne('SELECT id FROM remote_actors WHERE handle = ? COLLATE NOCASE',[$word]);
            }
            if (is_array($actor) && !empty($actor['id'])) {
                $href=$federated ? $actor['id'] : localUrl('page=remote&actor='.rawurlencode($actor['id']));
            } elseif (strcasecmp($word,localHandle())===0) {
                $href=localUrl('page=profile');
            } else {
                // Unknown remote platform profile paths differ. Link to our
                // internal lookup form rather than inventing a Mastodon URL.
                $href=$federated ? '' : localUrl('page=find&handle='.rawurlencode($word));
            }
            $cls=' class="mention"';
        }
        if ($href!=='') {
            $out.='<a'.$cls.' href="'.escape($href).'"'.($newTab?' target="_blank"':'').' rel="noopener noreferrer nofollow ugc">'.$safeText.'</a>';
        } else $out.=$safeText;
        $pos=$at+strlen($word);
    }
    return $out.escape(substr($text,$pos));
}
function visibleText(string $s): string { return nl2br(socialMarkup($s), false); }
function hashtagNames(string $text): array {
    $tags=[];
    foreach (socialTokens($text) as $tok) if ($tok['kind']==='tag') $tags[mb_strtolower($tok['text'])]=$tok['text'];
    return array_values(array_slice($tags,0,30));
}
function mentionHandles(string $text): array {
    $handles=[];
    foreach (socialTokens($text) as $tok) if ($tok['kind']==='mention') $handles[strtolower($tok['text'])]=$tok['text'];
    return array_values(array_slice($handles,0,8));
}
function resolvedMentions(string $text,bool $discoverMissing=false): array {
    $out=[]; $lookups=0;
    foreach (mentionHandles($text) as $handle) {
        if (strcasecmp($handle,localHandle())===0) continue;
        $actor=queryOne('SELECT * FROM remote_actors WHERE handle = ? COLLATE NOCASE',[$handle]);
        if (!$actor && $discoverMissing && $lookups++<3) {
            try { $actor=discover($handle); }
            catch (Throwable $e) { error_log('Mention not resolved '.$handle.': '.$e->getMessage()); }
        }
        if ($actor && !empty($actor['id'])) $out[strtolower($handle)]=$actor;
    }
    return $out;
}
/** Sanitize HTML received from other federated servers; preserve real anchor hrefs. */
function sanitizeRemoteHtml(string $html): string {
    if ($html==='') return '';
    if (strlen($html)>65536) $html=mb_strcut($html,0,65536);
    $doc=new DOMDocument();
    $old=libxml_use_internal_errors(true);
    try {
        $ok=$doc->loadHTML('<?xml encoding="UTF-8"><div id="xfedi-content">'.$html.'</div>',
            LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
        if (!$ok) return nl2br(escape(remotePlain($html)),false);
        $container=$doc->getElementById('xfedi-content');
        if (!$container) return nl2br(escape(remotePlain($html)),false);
        $visit=static function (DOMNode $node,int $depth=0,bool $inLink=false,bool $literal=false) use (&$visit): string {
            if ($depth>24) return '';
            if ($node instanceof DOMText) return ($inLink || $literal) ? escape($node->textContent) : socialMarkup($node->textContent);
            if (!($node instanceof DOMElement)) return '';
            $tag=strtolower($node->tagName);
            if (in_array($tag,['script','style','iframe','svg','math','object','embed','form','input','button','video','audio'],true)) return '';
            if ($tag==='br') return '<br>';
            $children='';
            foreach ($node->childNodes as $child) $children.=$visit($child,$depth+1,$inLink || $tag==='a',$literal || in_array($tag,['pre','code'],true));
            if ($tag==='a') {
                $href=$node->getAttribute('href');
                return safeBrowserLink($href)
                    ? '<a href="'.escape($href).'" target="_blank" rel="noopener noreferrer nofollow ugc">'.$children.'</a>'
                    : $children;
            }
            if (in_array($tag,['p','strong','em','b','i','u','code','pre','blockquote','ul','ol','li'],true))
                return '<'.$tag.'>'.$children.'</'.$tag.'>';
            return $children;
        };
        $out=''; foreach ($container->childNodes as $child) $out.=$visit($child);
        return $out;
    } finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
}
function localHandle(): string { $u=user(); return $u ? '@'.$u['username'].'@'.hostName() : ''; }
function noteObject(array $p): array {
    $id=noteUrl((int)$p['id']); $u=user();
    $mentions=resolvedMentions($p['content']);
    $recipients=array_values(array_unique(array_merge(array_map(static fn($a)=>$a['id'],$mentions),postRecipients($p))));
    if ($p['in_reply_to']) {
        $parent=queryOne('SELECT author_id FROM remote_notes WHERE id=?',[$p['in_reply_to']]);
        if ($parent && !in_array($parent['author_id'],$recipients,true)) $recipients[]=$parent['author_id'];
    }
    $tags=[];
    foreach (hashtagNames($p['content']) as $hashtag) $tags[]=['type'=>'Hashtag','name'=>$hashtag,
        'href'=>localUrl('page=tag&tag='.rawurlencode(mb_substr($hashtag,1)))];
    foreach (mentionHandles($p['content']) as $handle) {
        $actor=$mentions[strtolower($handle)]??null;
        if ($actor) $tags[]=['type'=>'Mention','name'=>$handle,'href'=>$actor['id']];
    }
    $item=['@context'=>[AS_CTX,['Hashtag'=>'as:Hashtag']], 'id'=>$id,'type'=>'Note','attributedTo'=>actorUrl(),
        'published'=>nowIso((int)$p['created_at']),'updated'=>nowIso((int)$p['updated_at']),
        'content'=>'<p>'.nl2br(socialMarkup($p['content'],$mentions,true), false).'</p>',
        'tag'=>$tags,'to'=>array_merge([AS_PUBLIC],$recipients), 'cc'=>[identityUrl('ap=followers')],
        'inReplyTo'=>$p['in_reply_to'] ?: null, 'url'=>localUrl('page=post&id='.$p['id'])];
    if (!empty($p['image'])) $item['attachment']=[['type'=>'Document','mediaType'=>imageMime($p['image']),
        'url'=>localUrl('media=post&id='.$p['id']), 'name'=>$p['alt'] ?: 'Image']];
    if (!empty($p['quote_of'])) {
        // Link quotes remain useful on peers without quote extensions.
        $item['quoteUrl']=$p['quote_of'];
        $item['content'].='<p>↪ <a rel="nofollow noopener" href="'.escape(publicPostLink($p['quote_of'])).'">'.escape(publicPostLink($p['quote_of'])).'</a></p>';
        $item['@context'][]=['quoteUrl'=>'as:quoteUrl'];
    }
    $item['likes']=['type'=>'Collection','totalItems'=>(int)(queryOne('SELECT COUNT(*) n FROM likes_remote WHERE post_id=?',[$p['id']])['n']??0)];
    $item['shares']=['type'=>'Collection','totalItems'=>(int)(queryOne('SELECT COUNT(*) n FROM boosts_remote WHERE note_id=?',[$id])['n']??0)];
    return $item;
}
function actorObject(): array {
    $u=user(); if (!$u) throw new RuntimeException('No user');
    return ['@context'=>[AS_CTX,'https://w3id.org/security/v1',['schema'=>'http://schema.org/','PropertyValue'=>'schema:PropertyValue','value'=>'schema:value','discoverable'=>'http://joinmastodon.org/ns#discoverable']], 'id'=>actorUrl(),
      'type'=>'Person', 'preferredUsername'=>$u['username'], 'name'=>$u['name'],
      'summary'=>'<p>'.nl2br(escape($u['bio']), false).'</p>', 'url'=>localUrl('page=profile'),
      'inbox'=>inboxUrl(), 'outbox'=>identityUrl('ap=outbox'),
      'followers'=>identityUrl('ap=followers'), 'following'=>identityUrl('ap=following'),
      'endpoints'=>['sharedInbox'=>inboxUrl()],
      'published'=>nowIso((int)$u['created_at']),
      'attachment'=>empty($u['website'])?[]:[['type'=>'PropertyValue','name'=>'Website','value'=>'<a href="'.escape($u['website']).'" rel="me nofollow noopener noreferrer">'.escape($u['website']).'</a>']],
      'manuallyApprovesFollowers'=>false, 'discoverable'=>true,
      'publicKey'=>['id'=>actorUrl().'#main-key','owner'=>actorUrl(),'publicKeyPem'=>$u['public_key']],
      ...($u['avatar'] ? ['icon'=>['type'=>'Image','mediaType'=>imageMime($u['avatar']),'url'=>profileMediaUrl('avatar')]] : []),
      ...($u['cover'] ? ['image'=>['type'=>'Image','mediaType'=>imageMime($u['cover']),'url'=>profileMediaUrl('cover')]] : [])];
}
function newActivity(string $type, mixed $object, array $to=[], array $cc=[], ?string $fixedId=null): array {
    $ref=bin2hex(random_bytes(16));
    $a=['@context'=>AS_CTX,'id'=>$fixedId ?: activityUrl($ref),'type'=>$type,
        'actor'=>actorUrl(),'object'=>$object,'to'=>$to,'cc'=>$cc];
    executeSql('INSERT INTO activities(ref,json,created_at) VALUES (?,?,?)', [$ref,jenc($a),time()]);
    return $a;
}
function queueActivity(array $activity, array $actorIds): void {
    $seen=[];
    foreach ($actorIds as $id) {
        if (!is_string($id) || $id==='' || isset($seen[$id])) continue;
        $seen[$id]=true;
        $actor=queryOne('SELECT * FROM remote_actors WHERE id=?',[$id]);
        if (!$actor) continue;
        $dest=$actor['shared_inbox'] ?: $actor['inbox'];
        if (!isSafePublicUrl($dest)) continue;
        executeSql('INSERT OR IGNORE INTO deliveries(destination,activity_id,json,run_after) VALUES(?,?,?,?)',
             [$dest,$activity['id'],jenc($activity),time()]);
    }
}
function followerIds(): array { return array_column(queryAll('SELECT actor_id FROM followers'), 'actor_id'); }
function postActivity(array $post, string $type='Create'): void {
    $obj=noteObject($post);
    $to=$obj['to']; $cc=$obj['cc'];
    $a=newActivity($type,$obj,$to,$cc,$type==='Create'?identityUrl('ap=create&id='.$post['id']):null);
    $targets=array_merge(followerIds(),array_values(array_filter($to,static fn($id)=>$id!==AS_PUBLIC)));
    queueActivity($a,$targets);
}
function deleteActivity(int $id): void {
    $a=newActivity('Delete',['id'=>noteUrl($id),'type'=>'Tombstone'],[AS_PUBLIC],[identityUrl('ap=followers')]);
    $p=queryOne('SELECT * FROM posts WHERE id=?',[$id]);
    queueActivity($a,array_unique(array_merge(followerIds(),$p?postRecipients($p):[])));
}

/* A single HTTPS request implementation used for both signed federation and discovery.
   DNS addresses are pinned to CURLOPT_RESOLVE and redirection is disabled to prevent SSRF. */
function validHost(string $host): bool {
    return strlen($host)<=253 && strlen($host)>3 && !str_ends_with($host,'.') &&
           !filter_var($host,FILTER_VALIDATE_IP) &&
           (bool)preg_match('/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{2,59})$/iD',$host);
}
function ipInCidr(string $ip,string $cidr): bool {
    [$network,$bits]=explode('/',$cidr,2); $raw=@inet_pton($ip); $base=@inet_pton($network);
    if ($raw===false || $base===false || strlen($raw)!==strlen($base)) return false;
    $whole=intdiv((int)$bits,8); $rest=(int)$bits%8;
    if (substr($raw,0,$whole)!==substr($base,0,$whole)) return false;
    return $rest===0 || ((ord($raw[$whole]) & (255 << (8-$rest)))===(ord($base[$whole]) & (255 << (8-$rest))));
}
function publicIp(string $ip): bool {
    if (!filter_var($ip,FILTER_VALIDATE_IP)) return false;
    // PHP's NO_RES_RANGE does not cover CGNAT, multicast, or documentation ranges.
    if (!str_contains($ip,':')) {
        foreach (['0.0.0.0/8','10.0.0.0/8','100.64.0.0/10','127.0.0.0/8','169.254.0.0/16','172.16.0.0/12','192.0.0.0/24','192.0.2.0/24','192.88.99.0/24','192.168.0.0/16','198.18.0.0/15','198.51.100.0/24','203.0.113.0/24','224.0.0.0/4','240.0.0.0/4'] as $range) if (ipInCidr($ip,$range)) return false;
        return true;
    }
    if (!ipInCidr($ip,'2000::/3')) return false;
    foreach (['2001::/23','2001:db8::/32','2002::/16','3fff::/20'] as $range) if (ipInCidr($ip,$range)) return false;
    return true;
}
function isSafePublicUrl(string $url): bool {
    if (strlen($url)>2048 || str_contains($url, chr(92)) || preg_match('/[\x00-\x20\x7f]/', $url)) return false;
    $p=parse_url($url);
    if (!is_array($p) || ($p['scheme']??'')!=='https' || isset($p['user']) || isset($p['pass'])) return false;
    // Non-default ports are common on small self-hosted Fediverse servers;
    // only unprivileged ports are allowed so an actor cannot target admin services.
    if (isset($p['port']) && ($p['port']<1024 || $p['port']>65535)) return false;
    return validHost(strtolower($p['host']??''));
}
function resolvePinned(string $host): string {
    if (!validHost($host)) throw new RuntimeException('Unsafe remote hostname');
    $records=@dns_get_record($host,DNS_A|DNS_AAAA);
    if (!$records) throw new RuntimeException('DNS resolution failed');
    $addresses=[];
    foreach ($records as $r) {
        $ip=$r['ip']??$r['ipv6']??'';
        if ($ip==='') continue;
        if (!publicIp($ip)) throw new RuntimeException('Private DNS address blocked');
        $addresses[]=$ip;
    }
    if ($addresses) { $ip=$addresses[0]; return str_contains($ip,':')?'['.$ip.']':$ip; }
    throw new RuntimeException('No allowed public IP for hostname');
}
function httpRequest(string $url, string $method='GET', ?string $body=null, bool $sign=false, int $redirects=0, string $accept=''): array {
    if (!isSafePublicUrl($url)) throw new RuntimeException('Unsafe remote URL');
    $p=parse_url($url); $host=strtolower($p['host']); $port=(int)($p['port']??443); $pin=resolvePinned($host);
    $hostHeader=$port===443?$host:$host.':'.$port;
    $path=($p['path']??'/') ?: '/'; if (isset($p['query'])) $path.='?'.$p['query'];
    $date=gmdate('D, d M Y H:i:s').' GMT';
    $headers=['Accept: '.($accept?:'application/activity+json, application/ld+json; profile="'.AS_CTX.'", application/jrd+json, application/json'),
        'User-Agent: XFedi/'.APP_VERSION.' (+'.cfgUrl().')','Date: '.$date];
    if ($body!==null) $headers[]='Content-Type: application/activity+json';
    if ($sign) {
        $u=user(); if (!$u) throw new RuntimeException('Signing unavailable before setup');
        $fields=['(request-target)'=>'str', 'host'=>$hostHeader, 'date'=>$date];
        $fields['(request-target)']=strtolower($method).' '.$path;
        if ($body!==null) {
            $digest='SHA-256='.base64_encode(hash('sha256',$body,true));
            $headers[]='Digest: '.$digest; $fields['digest']=$digest;
        }
        $lines=[]; foreach ($fields as $k=>$v) $lines[]=$k.': '.$v;
        if (!openssl_sign(implode("\n",$lines),$rawSig,$u['private_key'],OPENSSL_ALGO_SHA256)) throw new RuntimeException('Signature failure');
        $headers[]='Signature: keyId="'.actorUrl().'#main-key",algorithm="rsa-sha256",headers="'.implode(' ',array_keys($fields)).'",signature="'.base64_encode($rawSig).'"';
    }
    $ch=curl_init($url); if ($ch===false) throw new RuntimeException('curl failed');
    $reply=''; $tooLarge=false; $responseHeaders=[];
    curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>false,
        CURLOPT_HEADERFUNCTION=>static function($c,string $line) use (&$responseHeaders): int { if (str_contains($line,':')) { [$k,$v]=explode(':',$line,2); $responseHeaders[strtolower(trim($k))]=trim($v); } return strlen($line); },
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_ENCODING=>'',CURLOPT_RESOLVE=>[$host.':'.$port.':'.$pin],CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*',CURLOPT_USERAGENT=>'XFedi/'.APP_VERSION,
        CURLOPT_WRITEFUNCTION=>static function ($c,string $chunk) use (&$reply,&$tooLarge): int {
            if (strlen($reply)+strlen($chunk)>MAX_REMOTE_BYTES) { $tooLarge=true; return 0; }
            $reply.=$chunk; return strlen($chunk);
        }]);
    if ($body!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,$body);
    $ok=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); $err=curl_error($ch);
    curl_close($ch);
    if ($tooLarge) throw new RuntimeException('Remote response exceeded limit');
    if ($ok===false) throw new RuntimeException('Remote request failed: '.$err);
    if ($method==='GET' && in_array($code,[301,302,303,307,308],true) && $redirects<3 && isset($responseHeaders['location'])) {
        $next=absoluteUrl($url,$responseHeaders['location']);
        return httpRequest($next,'GET',null,$sign,$redirects+1,$accept);
    }
    if ($code<200 || $code>=300) throw new RuntimeException('Remote HTTP status '.$code);
    return ['status'=>$code,'body'=>$reply,'url'=>$url,'headers'=>$responseHeaders];
}
function remoteJson(string $url, bool $signed=true): array {
    $reply=httpRequest($url,'GET',null,$signed); $data=json_decode($reply['body'],true,128);
    if (!is_array($data) || array_is_list($data)) throw new RuntimeException('Invalid remote JSON object');
    return $data;
}
function actorInfo(string $id, bool $force=false): array {
    if (!isSafePublicUrl($id)) throw new RuntimeException('Invalid actor ID');
    $cached=queryOne('SELECT * FROM remote_actors WHERE id=?',[$id]);
    if (!$force && $cached && (int)$cached['fetched_at'] > time()-43200) return $cached;
    $raw=remoteJson($id, user()!==null);
    if (($raw['id']??'')!==$id || !in_array($raw['type']??'', ['Person','Service','Application','Group','Organization'],true))
        throw new RuntimeException('Remote actor identity mismatch');
    $pk=$raw['publicKey']??[];
    if (is_array($pk) && array_is_list($pk)) $pk=$pk[0]??[]; // some servers wrap publicKey in a list
    $pkId=stringField($pk['id']??''); $pkOwner=stringField($pk['owner']??'');
    $pem=stringField($pk['publicKeyPem']??'');
    // Key IDs are not universally actorId#fragment: GoToSocial and others use
    // actorId/main-key style. Require same-host keys owned by the actor instead.
    $pkHost=strtolower((string)parse_url($pkId,PHP_URL_HOST));
    if ($pkOwner!==$id || !isSafePublicUrl($pkId) || $pkHost!==strtolower((string)parse_url($id,PHP_URL_HOST))
        || !$pem || strlen($pem)>8192 || openssl_pkey_get_public($pem)===false)
        throw new RuntimeException('Remote actor public key invalid');
    $inbox=stringField($raw['inbox']??''); $outbox=stringField($raw['outbox']??'');
    $shared=stringField($raw['endpoints']['sharedInbox']??'');
    $followers=stringField($raw['followers']??'');
    if (!isSafePublicUrl($inbox)) throw new RuntimeException('Remote actor inbox invalid');
    if ($outbox && !isSafePublicUrl($outbox)) $outbox='';
    if ($shared && !isSafePublicUrl($shared)) $shared='';
    if ($followers && !isSafePublicUrl($followers)) $followers='';
    $username=clip(stringField($raw['preferredUsername']??''),60);
    $handle=($cached['handle']??'') ?: ($username!=='' ? '@'.$username.'@'.strtolower(parse_url($id,PHP_URL_HOST)) : '');
    $visuals=actorVisuals($raw);
    executeSql('INSERT INTO remote_actors(id,handle,username,name,bio,inbox,outbox,followers,shared_inbox,avatar,cover,key_id,public_key,fetched_at)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET
        handle=excluded.handle,username=excluded.username,name=excluded.name,bio=excluded.bio,
        inbox=excluded.inbox,outbox=excluded.outbox,followers=excluded.followers,shared_inbox=excluded.shared_inbox,
        avatar=excluded.avatar,cover=excluded.cover,key_id=excluded.key_id,public_key=excluded.public_key,fetched_at=excluded.fetched_at',
        [$id,$handle,$username,clip(remotePlain($raw['name']??''),120),clip(remotePlain($raw['summary']??''),600),
         $inbox,$outbox,$followers,$shared,$visuals['avatar'],$visuals['cover'],$pkId,$pem,time()]);
    return queryOne('SELECT * FROM remote_actors WHERE id=?',[$id]);
}
function discover(string $handle): array {
    if (!preg_match('/^@?([a-z0-9_\.\-]{1,60})@([a-z0-9.\-]+)$/iD',trim($handle),$m) || !validHost(strtolower($m[2])))
        throw new RuntimeException('Use @username@server.example');
    $domain=strtolower($m[2]);
    $wf='https://'.$domain.'/.well-known/webfinger?resource='.rawurlencode('acct:'.$m[1].'@'.$domain);
    $j=remoteJson($wf,false);
    if (strtolower(stringField($j['subject']??'')) !== strtolower('acct:'.$m[1].'@'.$domain))
        throw new RuntimeException('WebFinger subject did not match');
    foreach (($j['links']??[]) as $lnk) {
        if (is_array($lnk) && ($lnk['rel']??'')==='self' &&
          (str_contains(stringField($lnk['type']??''),'application/activity+json') ||
           str_contains(stringField($lnk['type']??''),'application/ld+json')) &&
          isSafePublicUrl(stringField($lnk['href']??''))) {
            $actor=actorInfo($lnk['href']);
            // Preserve the WebFinger handle when actor hosting domain differs.
            $resolvedHandle='@'.$m[1].'@'.$domain;
            executeSql('UPDATE remote_actors SET handle=? WHERE id=?',[$resolvedHandle,$actor['id']]);
            $actor['handle']=$resolvedHandle;
            return $actor;
        }
    }
    throw new RuntimeException('ActivityPub actor not present in WebFinger');
}

/* HTTP Signatures (Cavage draft), intentionally restrictive; not RFC 9421. */
function incomingHeader(string $name): string {
    $key='HTTP_'.strtoupper(str_replace('-','_',$name));
    return trim(stringField($_SERVER[$key]??($name==='Content-Type'?($_SERVER['CONTENT_TYPE']??''):'')));
}
function verifyInbox(string $body): array {
    $sig=incomingHeader('Signature');
    if ($sig==='' && isset($_SERVER['REDIRECT_HTTP_SIGNATURE'])) $sig=(string)$_SERVER['REDIRECT_HTTP_SIGNATURE'];
    $args=[];
    if (preg_match_all('/([A-Za-z][A-Za-z0-9_-]*)="([^"\r\n]*)"/', $sig, $matches, PREG_SET_ORDER))
        foreach ($matches as $m) { if (isset($args[$m[1]])) fail('Repeated signature field',401); $args[$m[1]]=$m[2]; }
    $keyId=$args['keyId']??''; $head=strtolower(trim($args['headers']??'')); $signature=base64_decode($args['signature']??'',true);
    if (!in_array(strtolower($args['algorithm']??'rsa-sha256'),['rsa-sha256','hs2019'],true) || !isSafePublicUrl(explode('#',$keyId)[0]) || $signature===false || !$head || strlen($sig)>4096) fail('Signature required',401);
    $names=preg_split('/\s+/', $head); if (!$names || count($names)>12 || count($names)!==count(array_unique($names))) fail('Invalid signed headers',401);
    foreach (['(request-target)','host','date','digest'] as $required) if (!in_array($required,$names,true)) fail('Missing signed headers',401);
    $date=incomingHeader('Date'); $parsed=strtotime($date);
    if (!$parsed || abs(time()-$parsed)>900) fail('Signature timestamp outside 15-minute window',401);
    $digest=incomingHeader('Digest');
    if (!preg_match('/^SHA-256=([A-Za-z0-9+\/=]+)$/iD',$digest,$m) ||
        !hash_equals(base64_encode(hash('sha256',$body,true)),$m[1])) fail('Digest mismatch',401);
    $host=incomingHeader('Host'); if (!in_array(strtolower($host),[hostName(),hostName().':443'],true)) fail('Host mismatch',401);
    // REQUEST_URI is the original target even after FastCGI's internal rewrite.
    $uri=stringField($_SERVER['REQUEST_URI']??'');
    if (!$uri || $uri[0]!=='/') fail('Invalid request target',401);
    $lines=[];
    foreach ($names as $key) {
        if ($key==='(request-target)') $value='post '.$uri;
        elseif ($key==='host') $value=$host;
        elseif ($key==='date') $value=$date;
        elseif ($key==='digest') $value=$digest;
        elseif (preg_match('/^[a-z][a-z0-9-]*$/D',$key)) $value=incomingHeader($key);
        else fail('Unknown signature component',401);
        if ($value==='') fail('Signed header absent',401);
        $lines[]=$key.': '.$value;
    }
    $payload=json_decode($body,true,100);
    $actorId=is_array($payload)?firstId($payload['actor']??''):'';
    if (!isSafePublicUrl($actorId)) fail('Invalid signing actor',401);
    try {
        $actor=actorInfo($actorId);
        if ($actor['key_id']!==$keyId) $actor=actorInfo($actorId,true);
    } catch (Throwable $e) { error_log('Actor lookup failed: '.$e->getMessage()); fail('Cannot resolve signer',401); }
    if ($actor['key_id']!==$keyId || openssl_verify(implode("\n",$lines),$signature,$actor['public_key'],OPENSSL_ALGO_SHA256)!==1) {
        // A legitimate key rotation may have occurred.
        try { $actor=actorInfo($actorId,true); } catch (Throwable $e) { fail('Bad signature',401); }
        if ($actor['key_id']!==$keyId || openssl_verify(implode("\n",$lines),$signature,$actor['public_key'],OPENSSL_ALGO_SHA256)!==1)
            fail('Bad signature',401);
    }
    return $actor;
}
function localNoteId(string $id): int {
    $p=parse_url($id); if (!$p || ($p['scheme']??'')!==parse_url(cfgUrl(),PHP_URL_SCHEME) ||
       ($p['host']??'')!==hostName() || ($p['path']??'')!==parse_url(cfgUrl(),PHP_URL_PATH)) return 0;
    parse_str($p['query']??'', $q);
    return ($q['ap']??'')==='note' && ctype_digit((string)($q['id']??'')) ? (int)$q['id'] : 0;
}
function actorOwnsNote(string $actor, string $id): bool {
    return isSafePublicUrl($id) && strtolower((string)parse_url($id,PHP_URL_HOST))===strtolower((string)parse_url($actor,PHP_URL_HOST));
}
function noteInbound(array $note, string $actor, bool $allowInsert, bool $publicImport=false): void {
    $id=stringField($note['id']??'');
    if (!actorOwnsNote($actor,$id) || firstId($note['attributedTo']??'')!==$actor || !in_array($note['type']??'',['Note','Article','Page','Image','Video','Audio','Question'],true)) return;
    if (queryOne('SELECT id FROM remote_tombstones WHERE id=?',[$id])) return;
    $existing=queryOne('SELECT * FROM remote_notes WHERE id=?',[$id]); if (!$existing && !$allowInsert) return;
    if ($existing && $existing['author_id']!==$actor) return;
    $address=array_merge(addresses($note['to']??[]),addresses($note['cc']??[]));
    $parent=clip(stringField($note['inReplyTo']??''),2048);
    if ($parent && !isSafePublicUrl($parent)) $parent='';
    $localParent=$parent!=='' ? localNoteId($parent) : 0;
    // This application has no private-message UI: never display non-public activities.
    $targeted=in_array(AS_PUBLIC,$address,true) && (in_array(actorUrl(),$address,true) || $localParent>0 ||
        queryOne("SELECT actor_id FROM follows WHERE actor_id=? AND status='accepted'",[$actor])!==null || $publicImport);
    if (!$targeted) return;
    $content=remotePlain($note['content']??$note['name']??'');
    $contentHtml=sanitizeRemoteHtml(stringField($note['content']??$note['name']??''));
    $summary=remotePlain($note['summary']??'');
    $mediaItems=noteMedia($note); $firstImage=current(array_filter($mediaItems,static fn($v)=>$v['type']==='image'));
    $media=$firstImage['url']??''; $mediaAlt=$firstImage['alt']??'';
    $ts=min(time()+3600,fromIso(stringField($note['published']??'')));
    $updated=fromIso(stringField($note['updated']??($note['published']??'')));
    if ($existing && ($existing['deleted'] || $updated<(int)$existing['updated_at'])) return;
    executeSql('INSERT INTO remote_notes(id,author_id,content,content_html,summary,url,image,in_reply_to,created_at,updated_at,deleted)
        VALUES(?,?,?,?,?,?,?,?,?,?,0) ON CONFLICT(id) DO UPDATE SET content=excluded.content,content_html=excluded.content_html,summary=excluded.summary,
        image=excluded.image,updated_at=excluded.updated_at,deleted=0',
        [$id,$actor,$content,$contentHtml,$summary,remoteUrlValue($note['url']??'')?:$id,$media,$parent,$ts,$updated]);
    $quote=quoteTarget($note);
    executeSql('UPDATE remote_notes SET quote_of=?,alt=?,media_json=?,sensitive=?,fetched_at=?,object_type=? WHERE id=?',[$quote,$mediaAlt,jenc($mediaItems),empty($note['sensitive'])?0:1,time(),$note['type'],$id]);
    queueNoteEnrichment(['content'=>$content,'content_html'=>stringField($note['content']??''),'quote_of'=>$quote,'object_type'=>$note['type'],'url'=>remoteUrlValue($note['url']??'')]);
    syncTags($id,$content);
    if (!$existing && in_array(actorUrl(),$address,true)) notifyUser('mention',$actor,$id);
    if (!$existing && localNoteId($quote)) notifyUser('quote',$actor,$id);
    if ($localParent && !$existing && queryOne('SELECT id FROM posts WHERE id=? AND deleted=0',[$localParent]))
        notifyUser('reply',$actor,$id);
}
function notifyUser(string $type,string $actor,string $obj='',string $event=''): void {
    executeSql('INSERT OR IGNORE INTO notifications(kind,actor_id,object_id,created_at,event_key) VALUES(?,?,?,?,?)',[$type,$actor,$obj,time(),hash('sha256',$type.'|'.$actor.'|'.$obj.'|'.$event)]);
}
function handleInbox(array $a, array $sender): void {
    $id=stringField($a['id']??''); $actor=firstId($a['actor']??''); $type=stringField($a['type']??'');
    if (!isSafePublicUrl($id) || $actor!==$sender['id'] || !actorOwnsNote($actor,$id)) throw new RuntimeException('Activity identity mismatch');
    if (queryOne('SELECT id FROM seen_activities WHERE id=?',[$id])) return;
    if (in_array($type,['Create','Update'],true) && is_string($a['object']??null)) {
        $ref=$a['object'];
        if (!actorOwnsNote($actor,$ref)) throw new RuntimeException('Object origin mismatch');
        $resolved=remoteJson($ref);
        if (firstId($resolved)!==$ref) throw new RuntimeException('Dereferenced object identity mismatch');
        $a['object']=$resolved;
    }
    db()->beginTransaction();
    try {
        // Atomically claim the activity ID first: concurrent FPM workers cannot
        // process the same delivery twice (the previous pre-check raced).
        $claim=db()->prepare('INSERT OR IGNORE INTO seen_activities(id,received_at) VALUES(?,?)');
        $claim->execute([$id,time()]);
        if ($claim->rowCount()===0) { db()->commit(); return; }
        $object=$a['object']??null; $objId=firstId($object);
        if ($type==='Follow' && $objId===actorUrl()) {
            $wasFollowing=queryOne('SELECT actor_id FROM followers WHERE actor_id=?',[$actor]);
            executeSql('INSERT INTO followers(actor_id,follow_id,created_at) VALUES(?,?,?) ON CONFLICT(actor_id) DO UPDATE SET follow_id=excluded.follow_id',[$actor,$id,time()]);
            $reply=newActivity('Accept',$a,[$actor]); queueActivity($reply,[$actor]); if (!$wasFollowing) notifyUser('follow',$actor,'',$id);
        } elseif ($type==='Accept' || $type==='Reject') {
            // The embedded original Follow must be one we really sent.
            $fid=is_array($object) ? firstId($object['id']??'') : $objId;
            $row=queryOne('SELECT actor_id FROM follows WHERE follow_id=?',[$fid]);
            if ($row && $row['actor_id']===$actor) {
                executeSql('UPDATE follows SET status=? WHERE follow_id=?',[$type==='Accept'?'accepted':'rejected',$fid]);
                notifyUser(strtolower($type),$actor);
            }
        } elseif ($type==='Create' && is_array($object)) {
            noteInbound($object,$actor,true);
        } elseif ($type==='Update' && is_array($object) && in_array($object['type']??'',['Note','Article','Page','Image','Video','Audio','Question'],true)) {
            noteInbound($object,$actor,false);
        } elseif ($type==='Update' && is_array($object) && firstId($object)===$actor
                 && in_array($object['type']??'',['Person','Service','Application','Group','Organization'],true)) {
            // The remote actor edited their profile: refresh our cached copy.
            try { actorInfo($actor,true); } catch (Throwable $e) { error_log('Actor refresh: '.$e->getMessage()); }
        } elseif ($type==='Delete') {
            if ($objId===$actor) {
                // Actor deleted itself: drop the follow relationships.
                executeSql('DELETE FROM followers WHERE actor_id=?',[$actor]);
                executeSql('DELETE FROM follows WHERE actor_id=?',[$actor]);
                executeSql('UPDATE remote_notes SET deleted=1 WHERE author_id=?',[$actor]);
                executeSql('INSERT OR IGNORE INTO remote_tombstones(id,actor_id,created_at) SELECT id,author_id,? FROM remote_notes WHERE author_id=?',[time(),$actor]);
            } elseif ($objId && actorOwnsNote($actor,$objId)) {
                $known=queryOne('SELECT author_id FROM remote_notes WHERE id=?',[$objId]);
                if (!$known || $known['author_id']===$actor) {
                    executeSql('INSERT OR IGNORE INTO remote_tombstones(id,actor_id,created_at) VALUES(?,?,?)',[$objId,$actor,time()]);
                    executeSql('UPDATE remote_notes SET deleted=1 WHERE id=? AND author_id=?',[$objId,$actor]);
                    executeSql('DELETE FROM boosts_local WHERE note_id=?',[$objId]);
                    executeSql('DELETE FROM tags WHERE note_id=?',[$objId]);
                }
            }
        } elseif ($type==='Like') {
            $pid=localNoteId($objId);
            if ($pid && queryOne('SELECT id FROM posts WHERE id=? AND deleted=0',[$pid])) {
                if (!queryOne('SELECT actor_id FROM likes_remote WHERE actor_id=? AND post_id=?',[$actor,$pid])) {
                    executeSql('INSERT OR IGNORE INTO likes_remote(actor_id,post_id,activity_id) VALUES(?,?,?)',[$actor,$pid,$id]);
                    notifyUser('like',$actor,$objId,$id);
                }
            }
        } elseif ($type==='Announce') {
            $audience=array_merge(addresses($a['to']??[]),addresses($a['cc']??[]));
            if (in_array(AS_PUBLIC,$audience,true) && isSafePublicUrl($objId)) {
                $pid=localNoteId($objId);
                $note=$pid?queryOne('SELECT id FROM posts WHERE id=? AND deleted=0',[$pid]):queryOne('SELECT id FROM remote_notes WHERE id=? AND deleted=0',[$objId]);
                if (!$note && !$pid && queryOne("SELECT actor_id FROM follows WHERE actor_id=? AND status='accepted'",[$actor])) $note=fetchPublicNote($objId);
                if ($note) {
                    $alreadyBoosted=queryOne('SELECT activity_id FROM boosts_remote WHERE actor_id=? AND note_id=?',[$actor,$objId]);
                    executeSql('INSERT OR IGNORE INTO boosts_remote(actor_id,note_id,activity_id,created_at) VALUES(?,?,?,?)',[$actor,$objId,$id,time()]);
                    if ($pid && !$alreadyBoosted) notifyUser('boost',$actor,$objId,$id);
                }
            }
        } elseif ($type==='Undo') {
            $original=is_array($object)?$object:[];
            $oid=firstId(is_array($object) ? ($object['id']??'') : $object);
            $originalType=stringField($original['type']??'');
            if ($originalType==='Follow' || ($oid && queryOne('SELECT follow_id FROM followers WHERE actor_id=? AND follow_id=?',[$actor,$oid]))) {
                executeSql('DELETE FROM followers WHERE actor_id=? AND follow_id=?',[$actor,$oid]);
            }
            // Match stored actor AND activity ID; embedded claims alone grant no authority.
            if ($oid) {
                executeSql('DELETE FROM likes_remote WHERE actor_id=? AND activity_id=?',[$actor,$oid]);
                executeSql('DELETE FROM boosts_remote WHERE actor_id=? AND activity_id=?',[$actor,$oid]);
            }
        }
        // Unsupported activities are acknowledged to avoid endless retry storms.
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
}
function importOutbox(array $actor,?callable $fetch=null): int {
    $fetch??=static fn(string $url)=>remoteJson($url);
    if (!$actor['outbox']) return 0;
    $obj=$fetch($actor['outbox']);
    if (in_array($obj['type']??'',['OrderedCollection','Collection'],true) && !empty($obj['first'])) {
        if (is_array($obj['first']) && isset($obj['first']['orderedItems'])) $obj=$obj['first'];
        else {
            $first=firstId($obj['first']);
            if ($first && isSafePublicUrl($first) && parse_url($first,PHP_URL_HOST)===parse_url($actor['outbox'],PHP_URL_HOST)) $obj=$fetch($first);
        }
    }
    $items=(array)($obj['orderedItems']??$obj['items']??[]); $added=0;
    foreach (array_slice($items,0,15) as $item) {
        if (is_string($item) && actorOwnsNote($actor['id'],$item)) {
            try { $item=$fetch($item); } catch (Throwable) { continue; }
        }
        if (!is_array($item)) continue;
        if (($item['type']??'')==='Announce' && firstId($item['actor']??'')===$actor['id']) {
            try { handleInbox($item,$actor); $added++; } catch (Throwable $e) { error_log('Outbox boost: '.$e->getMessage()); }
            continue;
        }
        if (in_array($item['type']??'',['Note','Article','Page','Image','Video','Audio','Question'],true)) $note=$item;
        elseif (($item['type']??'')==='Create' && firstId($item['actor']??'')===$actor['id']) $note=$item['object']??null;
        else continue;
        if (is_string($note) && actorOwnsNote($actor['id'],$note)) {
            try { $note=$fetch($note); } catch (Throwable) { continue; }
        }
        if (is_array($note) && firstId($note['attributedTo']??'')===$actor['id']) {
            noteInbound($note,$actor['id'],true,true); $added++;
        }
    }
    return $added;
}
function processQueue(int $max=25): array {
    $lock=@fopen(dataDir().'/worker.lock','c');
    if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) return ['processed'=>0,'detail'=>'Another worker is running'];
    $done=0; $fails=0;
    $jobs=queryAll("SELECT * FROM deliveries WHERE status='pending' AND run_after<=? ORDER BY id LIMIT ".max(1,min(50,$max)),[time()]);
    foreach ($jobs as $job) {
        try {
            httpRequest($job['destination'],'POST',$job['json'],true);
            executeSql("UPDATE deliveries SET status='delivered',attempts=attempts+1,last_error='' WHERE id=?",[$job['id']]); $done++;
        } catch (Throwable $e) {
            $attempt=(int)$job['attempts']+1; $status=$attempt>=8?'failed':'pending';
            $delay=min(21600,60*(2**min($attempt,8)));
            executeSql('UPDATE deliveries SET attempts=?,status=?,run_after=?,last_error=? WHERE id=?',
                [$attempt,$status,time()+$delay,clip($e->getMessage(),180),$job['id']]); $fails++;
        }
    }
    // Expire replay keys and old delivered items; preserve failed jobs for inspection.
    executeSql('DELETE FROM seen_activities WHERE received_at<?',[time()-30*86400]);
    executeSql('DELETE FROM login_attempts WHERE window_start<?',[time()-86400]);
    executeSql("DELETE FROM deliveries WHERE status='delivered' AND run_after<?",[time()-14*86400]);
    flock($lock,LOCK_UN); fclose($lock);
    return ['delivered'=>$done,'errors'=>$fails,'examined'=>count($jobs)];
}
function imageMime(string $name): string {
    $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
    return ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp'][$ext]??'application/octet-stream';
}
function uploadImage(string $key, int $max=5242880): string {
    if (!isset($_FILES[$key]) || ($_FILES[$key]['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) return '';
    $f=$_FILES[$key];
    if ($f['error']!==UPLOAD_ERR_OK || $f['size']<=0 || $f['size']>$max || !is_uploaded_file($f['tmp_name'])) throw new RuntimeException('Invalid image upload or image too large');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
    $dimensions=@getimagesize($f['tmp_name']);
    if (!isset($allowed[$mime]) || $dimensions===false || $dimensions[0]*$dimensions[1]>24000000)
        throw new RuntimeException('Only valid JPEG, PNG, GIF or WebP images up to 24 megapixels supported');
    $name=bin2hex(random_bytes(16)).'.'.$allowed[$mime];
    if (!move_uploaded_file($f['tmp_name'],dataDir().'/'.$name)) throw new RuntimeException('Cannot save image');
    @chmod(dataDir().'/'.$name,0600);
    return $name;
}
function serveImage(string $name): never {
    $file=dataDir().'/'.basename($name);
    if (!$name || !preg_match('/^[0-9a-f]{32}\.(jpg|png|gif|webp)$/D',$name) || !is_file($file)) fail('Not found',404);
    header('Content-Type: '.imageMime($name)); header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=3600'); header('Content-Length: '.filesize($file));
    readfile($file); exit;
}

