<?php
declare(strict_types=1);

function migrateV2(): void {
    db()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS metadata(key TEXT PRIMARY KEY,value TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS bookmarks(note_id TEXT PRIMARY KEY,created_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS boosts_local(note_id TEXT PRIMARY KEY,activity_id TEXT NOT NULL,created_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS boosts_remote(actor_id TEXT NOT NULL,note_id TEXT NOT NULL,activity_id TEXT NOT NULL,created_at INTEGER NOT NULL,PRIMARY KEY(actor_id,note_id));
CREATE TABLE IF NOT EXISTS remote_tombstones(id TEXT PRIMARY KEY,actor_id TEXT NOT NULL,created_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS tags(note_id TEXT NOT NULL,tag TEXT NOT NULL,PRIMARY KEY(note_id,tag));
CREATE INDEX IF NOT EXISTS tags_name ON tags(tag);
CREATE TABLE IF NOT EXISTS inbox_jobs(id TEXT PRIMARY KEY,actor_id TEXT NOT NULL,json TEXT NOT NULL,attempts INTEGER NOT NULL DEFAULT 0,status TEXT NOT NULL DEFAULT 'pending',run_after INTEGER NOT NULL,last_error TEXT NOT NULL DEFAULT '',created_at INTEGER NOT NULL);
CREATE INDEX IF NOT EXISTS inbox_pending ON inbox_jobs(status,run_after);
SQL);
    // Serialize schema changes across simultaneous FPM/worker startup.
    db()->exec('BEGIN IMMEDIATE');
    try {
        foreach (['users'=>['timezone'=>"TEXT NOT NULL DEFAULT 'UTC'",'website'=>"TEXT NOT NULL DEFAULT ''",'website_verified_at'=>'INTEGER NOT NULL DEFAULT 0','website_checked_at'=>'INTEGER NOT NULL DEFAULT 0','website_error'=>"TEXT NOT NULL DEFAULT ''"],
            'posts'=>['quote_of'=>"TEXT NOT NULL DEFAULT ''"], 'remote_notes'=>['quote_of'=>"TEXT NOT NULL DEFAULT ''",'alt'=>"TEXT NOT NULL DEFAULT ''"],
            'notifications'=>['event_key'=>'TEXT']] as $table=>$fields) {
            $existing=array_column(queryAll('PRAGMA table_info('.$table.')'),'name');
            foreach ($fields as $name=>$type) if (!in_array($name,$existing,true)) db()->exec('ALTER TABLE '.$table.' ADD COLUMN '.$name.' '.$type);
        }
        migrateSocial();
        db()->exec('CREATE UNIQUE INDEX IF NOT EXISTS notification_events ON notifications(event_key)');
        $identity=queryOne("SELECT value FROM metadata WHERE key='identity_url'");
        if ($identity && $identity['value']!==cfgUrl()) throw new RuntimeException('XFEDI_URL differs from stored federation identity. Restore the original URL.');
        executeSql("INSERT OR IGNORE INTO metadata(key,value) VALUES('identity_url',?)",[cfgUrl()]);
        if (!queryOne("SELECT value FROM metadata WHERE key='tags_v2'")) {
            foreach (queryAll('SELECT id,content FROM posts WHERE deleted=0') as $p) syncTags(noteUrl((int)$p['id']),$p['content']);
            foreach (queryAll('SELECT id,content FROM remote_notes WHERE deleted=0') as $p) syncTags($p['id'],$p['content']);
            executeSql("INSERT INTO metadata(key,value) VALUES('tags_v2','1')");
        }
        executeSql("INSERT INTO metadata(key,value) VALUES('schema_version','3') ON CONFLICT(key) DO UPDATE SET value='3'");
        db()->exec('COMMIT');
    } catch (Throwable $e) { db()->exec('ROLLBACK'); throw $e; }
}
function siteOrigin(): string {
    $local=getenv('XFEDI_LOCAL_ORIGIN')?:'';
    if ((getenv('XFEDI_ALLOW_INSECURE_HTTP')?:'')==='1' && preg_match('~^http://(?:127\.0\.0\.1|localhost):[0-9]{4,5}$~D',$local)) return $local;
    return 'https://'.hostName();
}
function uiUrl(string $query=''): string {
    if ($query==='') return siteOrigin().'/';
    parse_str($query,$q);
    if (isset($q['ap']) || isset($q['media'])) return identityUrl($query);
    $page=$q['page']??'home'; unset($q['page']);
    $u=user(); $username=$u['username']??'';
    $path=match($page) {
        'home'=>'/', 'profile'=>'/@'.rawurlencode($username),
        'post'=>'/@'.rawurlencode($username).'/'.(int)($q['id']??0),
        'tag'=>'/tags/'.rawurlencode((string)($q['tag']??'')),
        'remote'=>'/accounts', 'remote_post'=>'/statuses',
        'federated'=>'/public','local'=>'/public/local','instances'=>'/instances',
        'alerts'=>'/notifications', 'find'=>'/search','login'=>'/auth/sign_in',
        default=>'/'.rawurlencode((string)$page)
    };
    if ($page==='post') unset($q['id']);
    if ($page==='tag') unset($q['tag']);
    return siteOrigin().$path.($q?'?'.http_build_query($q,'','&',PHP_QUERY_RFC3986):'');
}
function routePretty(): void {
    $raw=stringField($_SERVER['REQUEST_URI']??'/');
    $path=rawurldecode((string)parse_url($raw,PHP_URL_PATH));
    $identityPath=(string)parse_url(cfgUrl(),PHP_URL_PATH);
    if (in_array($path,['/index.php','/xsukax_fediverse.php',$identityPath],true)) return;
    // Path routing always wins over query parameters, preventing endpoint confusion.
    unset($_GET['ap'],$_GET['media'],$_GET['asset'],$_GET['feed'],$_GET['sitemap']);
    if ($path==='/') { $_GET['page']='home'; return; }
    $fixed=['/.well-known/webfinger'=>['ap'=>'webfinger'],'/.well-known/nodeinfo'=>['ap'=>'nodeinfo-discovery'],
      '/nodeinfo/2.1'=>['ap'=>'nodeinfo'],'/feed.rss'=>['feed'=>'rss'],'/sitemap.xml'=>['sitemap'=>'1'],
      '/robots.txt'=>['robots'=>'1'],'/healthz'=>['health'=>'1'],
      '/auth/sign_in'=>['page'=>'login'],'/notifications'=>['page'=>'alerts'],'/search'=>['page'=>'find'],
      '/public'=>['page'=>'federated'],'/public/local'=>['page'=>'local'],'/instances'=>['page'=>'instances'],
      '/accounts'=>['page'=>'remote'],'/statuses'=>['page'=>'remote_post']];
    if (isset($fixed[$path])) { $_GET=array_merge($_GET,$fixed[$path]); return; }
    if (preg_match('~^/assets/(theme\.css|ui\.js)$~D',$path,$m)) { $_GET['asset']=$m[1]; return; }
    if (preg_match('~^/@([a-z0-9_]+)(?:/(\d+)|(\.rss))?$~D',$path,$m)) {
        // Validated against actual local account after DB initialization.
        $_GET['local_username']=$m[1];
        if (!empty($m[2])) { $_GET['page']='post'; $_GET['id']=$m[2]; }
        elseif (!empty($m[3])) $_GET['feed']='rss';
        else $_GET['page']='profile';
        if (str_contains(incomingHeader('Accept'),'application/activity+json') || str_contains(incomingHeader('Accept'),'application/ld+json')) $_GET['ap']=!empty($m[2])?'note':'actor';
        return;
    }
    if (preg_match('~^/tags/([\pL\pN_][\pL\pN_\pM]*)(\.rss)?$~uD',$path,$m)) {
        $_GET['page']='tag'; $_GET['tag']=$m[1]; if (!empty($m[2])) $_GET['feed']='rss'; return;
    }
    if (in_array($path,['/following','/followers','/settings','/queue','/bookmarks','/boosts','/quote'],true)) { $_GET['page']=substr($path,1); return; }
    fail('Not found',404);
}
function displayTime(int $ts,string $format='M j, Y · H:i T'): string {
    $zone=signedIn()?(user()['timezone']??'UTC'):'UTC';
    try { $tz=new DateTimeZone($zone); } catch (Throwable) { $tz=new DateTimeZone('UTC'); }
    return (new DateTimeImmutable('@'.$ts))->setTimezone($tz)->format($format);
}
function absoluteUrl(string $base,string $relative): string {
    if (preg_match('~^https://~',$relative)) return $relative;
    if (str_starts_with($relative,'//')) return 'https:'.$relative;
    if (preg_match('~^[a-z][a-z0-9+.-]*:~i',$relative)) return $relative; // rejected by request policy
    $p=parse_url($base); $origin='https://'.$p['host'].(isset($p['port'])?':'.$p['port']:'');
    if (str_starts_with($relative,'/')) return $origin.$relative;
    if (str_starts_with($relative,'?')) return $origin.($p['path']??'/').$relative;
    $dir=substr($p['path']??'/',0,strrpos($p['path']??'/','/')+1);
    $segments=[];
    foreach (explode('/',$dir.$relative) as $s) { if ($s==='..') array_pop($segments); elseif ($s!=='.' && $s!=='') $segments[]=$s; }
    return $origin.'/'.implode('/',$segments);
}
function addresses(mixed $value): array {
    if (is_string($value)) return [$value];
    if (!is_array($value)) return [];
    if (isset($value['id'])) return [firstId($value)];
    return array_values(array_filter(array_map('firstId',$value)));
}
function syncTags(string $id,string $text): void {
    executeSql('DELETE FROM tags WHERE note_id=?',[$id]);
    foreach (hashtagNames($text) as $tag) executeSql('INSERT OR IGNORE INTO tags(note_id,tag) VALUES(?,?)',[$id,mb_strtolower(substr($tag,1))]);
}
function knownPost(string $id): ?array {
    $local=localNoteId($id);
    if ($local) { $p=queryOne('SELECT * FROM posts WHERE id=? AND deleted=0',[$local]); if ($p) $p['_remote']=false; return $p; }
    $p=queryOne('SELECT * FROM remote_notes WHERE id=? AND deleted=0',[$id]); if ($p) $p['_remote']=true; return $p;
}
function postUiUrl(string $id): string { $pid=localNoteId($id); return $pid?localUrl('page=post&id='.$pid):localUrl('page=remote_post&id='.rawurlencode($id)); }
function publicPostLink(string $id): string {
    if (localNoteId($id)) return postUiUrl($id);
    $p=queryOne('SELECT url FROM remote_notes WHERE id=?',[$id]);
    return safeBrowserLink($p['url']??'')?$p['url']:$id;
}
function postRecipients(array $p): array {
    $out=array_column(resolvedMentions($p['content']),'id');
    foreach (['in_reply_to','quote_of'] as $key) if (!empty($p[$key])) {
        $parent=queryOne('SELECT author_id FROM remote_notes WHERE id=?',[$p[$key]]);
        if ($parent) $out[]=$parent['author_id'];
    }
    return array_values(array_unique($out));
}
function fetchPublicNote(string $id): ?array {
    if (!isSafePublicUrl($id)) return null;
    $cached=knownPost($id);
    if ($cached && (int)($cached['fetched_at']??0)>time()-300) return $cached;
    $raw=remoteJson($id);
    if (($raw['type']??'')==='Create') $raw=$raw['object']??[];
    if (!is_array($raw) || firstId($raw)!==$id) throw new RuntimeException('Remote object identity mismatch. Use its canonical ActivityPub URL.');
    $author=firstId($raw['attributedTo']??'');
    actorInfo($author);
    noteInbound($raw,$author,true,true);
    return knownPost($id);
}
function enqueueInbox(array $a,array $actor): void {
    $id=firstId($a); if (!isSafePublicUrl($id) || firstId($a['actor']??'')!==$actor['id'] || !actorOwnsNote($actor['id'],$id)) fail('Invalid activity identity',403);
    executeSql('INSERT OR IGNORE INTO inbox_jobs(id,actor_id,json,run_after,created_at) VALUES(?,?,?,?,?)',[$id,$actor['id'],jenc($a),time(),time()]);
}
function processInbox(int $limit=15): array {
    $done=0; $errors=0;
    foreach (queryAll("SELECT * FROM inbox_jobs WHERE status='pending' AND run_after<=? ORDER BY created_at,id LIMIT ".max(1,min(50,$limit)),[time()]) as $job) {
        try {
            $sender=queryOne('SELECT * FROM remote_actors WHERE id=?',[$job['actor_id']]);
            if (!$sender) throw new RuntimeException('Signing actor missing');
            handleInbox(json_decode($job['json'],true,100,JSON_THROW_ON_ERROR),$sender);
            executeSql("UPDATE inbox_jobs SET status='processed',last_error='' WHERE id=?",[$job['id']]); $done++;
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            $attempt=(int)$job['attempts']+1;
            executeSql('UPDATE inbox_jobs SET attempts=?,status=?,run_after=?,last_error=? WHERE id=?',[$attempt,$attempt>=8?'failed':'pending',time()+min(21600,60*(2**$attempt)),clip($e->getMessage(),180),$job['id']]); $errors++;
        }
    }
    // Retain IDs to deduplicate old redeliveries, discard bulky successful payloads.
    executeSql("UPDATE inbox_jobs SET json='{}' WHERE status='processed' AND created_at<?",[time()-7*86400]);
    return ['processed'=>$done,'errors'=>$errors];
}
function websiteProof(string $html,string $base,array $profiles): bool {
    $doc=new DOMDocument(); $prev=libxml_use_internal_errors(true);
    try {
        if (!$doc->loadHTML($html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING)) return false;
        foreach (['a','link'] as $tag) foreach ($doc->getElementsByTagName($tag) as $el) {
            if (!in_array('me',preg_split('/\s+/',strtolower(trim($el->getAttribute('rel')))),true)) continue;
            $href=absoluteUrl($base,trim($el->getAttribute('href')));
            foreach ($profiles as $profile) if ($href===$profile) return true;
        }
        return false;
    } finally { libxml_clear_errors(); libxml_use_internal_errors($prev); }
}
function verifyWebsite(): array {
    $u=user(); if (!$u || !$u['website']) return ['verified'=>false,'reason'=>'no website'];
    $due=(int)(queryOne("SELECT value FROM metadata WHERE key='verify_due'")['value']??0);
    if (!$due && (int)$u['website_checked_at']>time()-86400) return ['skipped'=>true];
    $verified=0;$reachable=0;$method='';$error='';$title='';
    try {
        $res=httpRequest($u['website'],'GET',null,false,0,'text/html, application/xhtml+xml;q=0.9');
        if (!str_contains(strtolower($res['headers']['content-type']??''),'html')) throw new RuntimeException('Website must serve HTML.');
        $reachable=time();
        $method=websiteEvidence($res['body'],$res['url'],[localUrl('page=profile'),identityUrl('page=profile'),actorUrl()]);
        $title=parseSocialMetadata($res['body'],$res['url'])['title'];
        if ($method!=='') $verified=time();
        else $error='HTTPS works. No profile association was found; ownership is unconfirmed.';
    } catch (Throwable $e) {$error=clip($e->getMessage(),180);}
    executeSql('UPDATE users SET website_verified_at=?,website_checked_at=?,website_error=?,website_reachable_at=?,website_method=?,website_title=? WHERE id=1 AND website=?',[$verified,time(),$error,$reachable,$method,$title,$u['website']]);
    if ((user()['website']??'')===$u['website']) executeSql("DELETE FROM metadata WHERE key='verify_due' AND value=?",[(string)$due]);
    return ['verified'=>$verified>0,'reachable'=>$reachable>0,'method'=>$method,'reason'=>$error];
}
function runBackground(bool $refresh=false): array {
    $lock=fopen(dataDir().'/background.lock','c');
    if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) return ['busy'=>true];
    try {
        $result=['inbox'=>processInbox(10),'delivery'=>processQueue(10),'website'=>verifyWebsite(),'enrichment'=>processEnrichment(),'instances'=>pollInstances()];
        if ($refresh && user()) {
            $n=0;
            foreach (queryAll("SELECT a.* FROM remote_actors a JOIN follows f ON a.id=f.actor_id WHERE f.status='accepted' ORDER BY RANDOM() LIMIT 5") as $a) {
                try { $n+=importOutbox($a); } catch (Throwable $e) { error_log('Outbox refresh: '.$e->getMessage()); }
            }
            $result['imported']=$n;
        }
        return $result;
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}
function handleExtraAction(string $action): bool {
    if ($action==='hide_remote') {
        $id=stringField($_POST['note_id']??'');
        if (!localNoteId($id) && queryOne('SELECT id FROM remote_notes WHERE id=?',[$id])) executeSql('INSERT OR IGNORE INTO hidden_posts(note_id,created_at) VALUES(?,?)',[$id,time()]);
        $_SESSION['flash']='Post hidden locally. The original remains on its author’s server.';returnAfterPostAction('federated');
    }
    if ($action==='unhide_posts') {executeSql('DELETE FROM hidden_posts');$_SESSION['flash']='Hidden remote posts are visible again.';redirectTo('settings');}
    if ($action==='subscribe_instance') {
        $domain=subscribeInstance(stringField($_POST['domain']??''));
        $_SESSION['flash']='Added '.$domain.'. Its public posts will appear in Federated after the next refresh.'; redirectTo('instances');
    }
    if ($action==='unsubscribe_instance') {
        executeSql('DELETE FROM instance_subscriptions WHERE domain=?',[stringField($_POST['domain']??'')]);
        $_SESSION['flash']='Instance source removed.'; redirectTo('instances');
    }
    if ($action==='refresh_instances') {
        executeSql('UPDATE instance_subscriptions SET next_poll=0');
        $_SESSION['flash']='Public instance feeds are queued for refresh.'; redirectTo('federated');
    }
    if ($action==='refresh_actor') {
        queueEnrichment('actor',stringField($_POST['actor_id']??''),true);
        $_SESSION['flash']='Profile and header refresh queued.'; redirectQuery('page=remote&actor='.rawurlencode(stringField($_POST['actor_id']??'')));
    }
    if ($action==='notification_read' || $action==='notification_unread') {
        executeSql('UPDATE notifications SET is_read=? WHERE id=?',[$action==='notification_read'?1:0,(int)($_POST['id']??0)]); redirectTo('alerts');
    }
    if ($action==='notifications_read') {
        // Mark only the notifications that existed when this view was rendered.
        executeSql('UPDATE notifications SET is_read=1 WHERE id<=?',[(int)($_POST['through_id']??0)]); redirectTo('alerts');
    }
    if ($action==='verify_website') {
        executeSql("INSERT INTO metadata(key,value) VALUES('verify_due',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",[(string)time()]);
        $_SESSION['flash']='Website verification queued.'; redirectTo('settings');
    }
    if ($action==='lookup') {
        $q=trim(stringField($_POST['query']??''));
        if (isSafePublicUrl($q)) {
            $raw=remoteJson($q);
            if (in_array($raw['type']??'',['Note','Create','Article','Page','Image','Video','Audio','Question'],true)) {
                $id=($raw['type']==='Create')?firstId($raw['object']??''):firstId($raw);
                if (!fetchPublicNote($id)) throw new RuntimeException('Only public Note objects can be imported.');
                redirectQuery('page=remote_post&id='.rawurlencode($id));
            }
            $a=actorInfo(firstId($raw));
        } else $a=discover($q);
        redirectQuery('page=remote&actor='.rawurlencode($a['id']));
    }
    if (in_array($action,['bookmark','unbookmark','boost','unboost','like','unlike'],true)) {
        $id=clip(stringField($_POST['note_id']??''),2048); $p=knownPost($id);
        if (!$p) throw new RuntimeException('Post is no longer available.');
        db()->beginTransaction();
        if ($action==='bookmark') executeSql('INSERT OR IGNORE INTO bookmarks(note_id,created_at) VALUES(?,?)',[$id,time()]);
        elseif ($action==='unbookmark') executeSql('DELETE FROM bookmarks WHERE note_id=?',[$id]);
        else {
            $boost=in_array($action,['boost','unboost'],true); $undo=in_array($action,['unboost','unlike'],true);
            $table=$boost?'boosts_local':'likes_local'; $type=$boost?'Announce':'Like';
            $old=queryOne('SELECT * FROM '.$table.' WHERE note_id=?',[$id]);
            $targets=array_unique(array_merge($boost?followerIds():[],$p['_remote']?[$p['author_id']]:[]));
            if ($undo && $old) {
                $a=newActivity('Undo',['id'=>$old['activity_id'],'type'=>$type,'actor'=>actorUrl(),'object'=>$id],$boost?[AS_PUBLIC]:$targets,$boost?[identityUrl('ap=followers')]:[]);
                executeSql('DELETE FROM '.$table.' WHERE note_id=?',[$id]); queueActivity($a,$targets);
            } elseif (!$undo && !$old) {
                $a=newActivity($type,$id,$boost?[AS_PUBLIC]:$targets,$boost?[identityUrl('ap=followers')]:[]);
                if ($boost) executeSql('INSERT INTO boosts_local(note_id,activity_id,created_at) VALUES(?,?,?)',[$id,$a['id'],time()]);
                else executeSql('INSERT INTO likes_local(note_id,activity_id) VALUES(?,?)',[$id,$a['id']]);
                queueActivity($a,$targets);
            }
        }
        $_SESSION['flash']=match($action){'bookmark'=>'Saved to your private bookmarks.','unbookmark'=>'Bookmark removed.','boost'=>'Boost shared.','unboost'=>'Boost undone.',default=>'Favourite updated.'};
        redirectQuery((localNoteId($id)?'page=post&id='.localNoteId($id):'page=remote_post&id='.rawurlencode($id)));
    }
    return false;
}
function outboxItems(int $offset): array {
    $items=[];
    $refs=queryAll("SELECT CAST(id AS TEXT) ref,'post' kind,created_at at FROM posts WHERE deleted=0 UNION ALL SELECT activity_id ref,'boost' kind,created_at at FROM boosts_local ORDER BY at DESC,ref DESC LIMIT 20 OFFSET ".$offset);
    foreach ($refs as $r) {
        if ($r['kind']==='post') {
            $p=queryOne('SELECT * FROM posts WHERE id=?',[$r['ref']]); $note=noteObject($p);
            $items[]=['id'=>identityUrl('ap=create&id='.$p['id']),'type'=>'Create','actor'=>actorUrl(),'published'=>nowIso((int)$p['created_at']),'object'=>$note,'to'=>$note['to'],'cc'=>$note['cc']];
        } else {
            parse_str((string)parse_url($r['ref'],PHP_URL_QUERY),$q);
            $a=queryOne('SELECT json FROM activities WHERE ref=?',[$q['ref']??'']);
            if ($a) $items[]=json_decode($a['json'],true,128,JSON_THROW_ON_ERROR);
        }
    }
    return $items;
}
function xml(string $s): string { return htmlspecialchars(preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u','',$s)??'',ENT_XML1|ENT_QUOTES,'UTF-8'); }
function servePublicEndpoints(): void {
    $u=user();
    if (isset($_GET['local_username']) && (!$u || $_GET['local_username']!==$u['username'])) fail('Not found',404);
    if (isset($_GET['asset'])) {
        $asset=stringField($_GET['asset']);
        if (!in_array($asset,['theme.css','ui.js'],true)) fail('Not found',404);
        header('Content-Type: '.($asset==='theme.css'?'text/css':'text/javascript').'; charset=utf-8'); header('Cache-Control: public, max-age=3600'); readfile(dirname(__DIR__).'/assets/'.$asset); exit;
    }
    if (isset($_GET['health'])) { header('Cache-Control: no-store'); jsonResponse(['status'=>'ok','application'=>APP_NAME,'version'=>APP_VERSION],200,'application/json'); }
    if (isset($_GET['robots'])) { header('Content-Type: text/plain; charset=utf-8'); echo "User-agent: *\nDisallow: /auth/\nDisallow: /settings\nDisallow: /notifications\nDisallow: /bookmarks\nDisallow: /queue\nSitemap: ".siteOrigin()."/sitemap.xml\n"; exit; }
    if (isset($_GET['feed'])) {
        if (!$u) fail('Not found',404);
        header('Content-Type: application/rss+xml; charset=utf-8'); header('Cache-Control: public, max-age=60');
        $tag=mb_strtolower(clip(stringField($_GET['tag']??''),70));
        $params=[]; $where='p.deleted=0';
        if ($tag!=='') { $where.=' AND EXISTS(SELECT 1 FROM tags t WHERE t.note_id=? || p.id AND t.tag=?)'; $params=[identityUrl('ap=note&id='),$tag]; }
        $posts=queryAll('SELECT p.* FROM posts p WHERE '.$where.' ORDER BY p.id DESC LIMIT 50',$params);
        echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel><title>'.xml($u['name'].($tag?' · #'.$tag:'').' · XFedi').'</title><link>'.xml(localUrl('page=profile')).'</link><description>'.xml($u['bio']?:'Public posts from '.localHandle()).'</description><language>en</language><atom:link href="'.xml(siteOrigin().($tag?'/tags/'.rawurlencode($tag).'.rss':'/feed.rss')).'" rel="self" type="application/rss+xml"/>';
        if ($posts) echo '<lastBuildDate>'.gmdate(DATE_RSS,max(array_column($posts,'updated_at'))).'</lastBuildDate>';
        foreach ($posts as $p) {
            $content=nl2br(escape($p['content']),false);
            if ($p['quote_of']) $content.='<p>Quote: <a href="'.escape(publicPostLink($p['quote_of'])).'">'.escape(publicPostLink($p['quote_of'])).'</a></p>';
            if ($p['image']) $content.='<p><img src="'.escape(identityUrl('media=post&id='.$p['id'])).'" alt="'.escape($p['alt']).'"></p>';
            echo '<item><title>'.xml(mb_substr($p['content'],0,100)).'</title><link>'.xml(localUrl('page=post&id='.$p['id'])).'</link><guid isPermaLink="false">'.xml(noteUrl((int)$p['id'])).'</guid><pubDate>'.gmdate(DATE_RSS,(int)$p['created_at']).'</pubDate><description>'.xml($content).'</description></item>';
        }
        echo '</channel></rss>'; exit;
    }
    if (isset($_GET['sitemap'])) {
        header('Content-Type: application/xml; charset=utf-8'); header('Cache-Control: public, max-age=60');
        $page=max(0,(int)($_GET['p']??0)); $count=(int)(queryOne('SELECT COUNT(*) n FROM posts WHERE deleted=0')['n']??0);
        echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        if ($page===0) {
            echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            for ($n=1;$n<=max(1,(int)ceil($count/1000));$n++) echo '<sitemap><loc>'.xml(siteOrigin().'/sitemap.xml?p='.$n).'</loc></sitemap>';
            echo '</sitemapindex>';
        } else {
            echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            if ($page===1 && $u) foreach ([siteOrigin().'/',localUrl('page=profile')] as $url) echo '<url><loc>'.xml($url).'</loc></url>';
            foreach (queryAll('SELECT id,updated_at FROM posts WHERE deleted=0 ORDER BY id LIMIT 1000 OFFSET '.(($page-1)*1000)) as $p) echo '<url><loc>'.xml(localUrl('page=post&id='.$p['id'])).'</loc><lastmod>'.nowIso((int)$p['updated_at']).'</lastmod></url>';
            echo '</urlset>';
        }
        exit;
    }
}

require_once __DIR__.'/social.php';
