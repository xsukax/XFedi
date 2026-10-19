<?php
declare(strict_types=1);

/** Additive 2.1 migration: remote presentation and optional public instance sources. */
function migrateSocial(): void {
    foreach (['remote_actors'=>['cover'=>"TEXT NOT NULL DEFAULT ''"],
        'remote_notes'=>['media_json'=>"TEXT NOT NULL DEFAULT '[]'",'sensitive'=>'INTEGER NOT NULL DEFAULT 0','fetched_at'=>'INTEGER NOT NULL DEFAULT 0','object_type'=>"TEXT NOT NULL DEFAULT 'Note'"],
        'users'=>['website_reachable_at'=>'INTEGER NOT NULL DEFAULT 0','website_method'=>"TEXT NOT NULL DEFAULT ''",'website_title'=>"TEXT NOT NULL DEFAULT ''"]] as $table=>$fields) {
        $columns=array_column(queryAll('PRAGMA table_info('.$table.')'),'name');
        foreach ($fields as $name=>$type) if (!in_array($name,$columns,true)) db()->exec('ALTER TABLE '.$table.' ADD COLUMN '.$name.' '.$type);
    }
    db()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS enrichment_jobs(kind TEXT NOT NULL,ref TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'pending',attempts INTEGER NOT NULL DEFAULT 0,run_after INTEGER NOT NULL,last_error TEXT NOT NULL DEFAULT '',updated_at INTEGER NOT NULL,PRIMARY KEY(kind,ref));
CREATE INDEX IF NOT EXISTS enrichment_pending ON enrichment_jobs(status,run_after);
CREATE TABLE IF NOT EXISTS hidden_posts(note_id TEXT PRIMARY KEY,created_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS link_previews(url TEXT PRIMARY KEY,title TEXT NOT NULL DEFAULT '',description TEXT NOT NULL DEFAULT '',image TEXT NOT NULL DEFAULT '',site_name TEXT NOT NULL DEFAULT '',fetched_at INTEGER NOT NULL,last_error TEXT NOT NULL DEFAULT '');
CREATE TABLE IF NOT EXISTS instance_subscriptions(domain TEXT PRIMARY KEY,created_at INTEGER NOT NULL,last_checked_at INTEGER NOT NULL DEFAULT 0,last_error TEXT NOT NULL DEFAULT '',next_poll INTEGER NOT NULL DEFAULT 0);
CREATE TABLE IF NOT EXISTS instance_posts(domain TEXT NOT NULL,note_id TEXT NOT NULL,boost_actor TEXT NOT NULL DEFAULT '',published_at INTEGER NOT NULL,PRIMARY KEY(domain,note_id));
CREATE INDEX IF NOT EXISTS instance_posts_note ON instance_posts(note_id);
SQL);
    if (!in_array('boost_label',array_column(queryAll('PRAGMA table_info(instance_posts)'),'name'),true)) db()->exec("ALTER TABLE instance_posts ADD COLUMN boost_label TEXT NOT NULL DEFAULT ''");
    if (!queryOne("SELECT value FROM metadata WHERE key='social_v21'")) {
        executeSql('UPDATE remote_actors SET fetched_at=0'); // backfill previously unrecorded headers
        foreach (queryAll('SELECT a.id FROM remote_actors a JOIN follows f ON f.actor_id=a.id') as $actor) subscribeInstance((string)parse_url($actor['id'],PHP_URL_HOST));
        // Backfill metadata/quote work in bounded batches, without network during migration.
        foreach (queryAll('SELECT id,content,quote_of FROM remote_notes WHERE deleted=0 ORDER BY created_at DESC LIMIT 100') as $p) {queueNoteEnrichment($p);queueEnrichment('note',$p['id']);}
        foreach (queryAll('SELECT id,content,quote_of FROM posts WHERE deleted=0 ORDER BY id DESC LIMIT 100') as $p) queueNoteEnrichment($p);
        executeSql("INSERT INTO metadata(key,value) VALUES('verify_due',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",[(string)time()]);
        executeSql("INSERT INTO metadata(key,value) VALUES('social_v21','1')");
    }
}
function objectList(mixed $value): array {
    if (!is_array($value)) return is_string($value)?[$value]:[];
    return array_is_list($value)?$value:[$value];
}
function remoteUrlValue(mixed $value): string {
    if (is_string($value)) return isSafePublicUrl($value)?$value:'';
    if (!is_array($value)) return '';
    if (array_is_list($value)) { foreach ($value as $item) if ($url=remoteUrlValue($item)) return $url; return ''; }
    foreach (['url','href','id'] as $key) if (isset($value[$key]) && ($url=remoteUrlValue($value[$key]))) return $url;
    return '';
}
function actorVisuals(array $actor): array {
    return ['avatar'=>remoteUrlValue($actor['icon']??''),'cover'=>remoteUrlValue($actor['image']??'')];
}
function noteMedia(array $note): array {
    $out=[];
    $values=objectList($note['attachment']??[]);
    foreach (objectList($note['image']??[]) as $image) $values[]=is_string($image)?['type'=>'Image','url'=>$image]:array_merge(['type'=>'Image'],$image);
    if (in_array($note['type']??'',['Image','Video','Audio'],true)) $values[]=$note;
    foreach ($values as $att) {
        if (is_string($att)) $att=['url'=>$att];
        if (!is_array($att)) continue;
        $url=remoteUrlValue($att['url']??$att); if (!$url) continue;
        $mime=strtolower(stringField($att['mediaType']??''));
        $type=match(true) { str_starts_with($mime,'image/')=>'image',str_starts_with($mime,'video/')=>'video',str_starts_with($mime,'audio/')=>'audio',default=>'' };
        if (!$type) $type=match(strtolower((string)($att['type']??''))) { 'image'=>'image','video'=>'video','audio'=>'audio',default=>'' };
        if (!$type) {
            $ext=strtolower(pathinfo((string)parse_url($url,PHP_URL_PATH),PATHINFO_EXTENSION));
            $type=match($ext) { 'jpg','jpeg','png','webp','gif','avif','svg'=>'image','mp4','webm','m4v','mov'=>'video','mp3','m4a','ogg','wav','flac'=>'audio',default=>'' };
        }
        if (!$type) continue;
        $out[$url]=['url'=>$url,'type'=>$type,'mime'=>$mime,'alt'=>clip(remotePlain($att['name']??$att['summary']??''),500),'poster'=>remoteUrlValue($att['icon']??$att['image']??'')];
        if (count($out)>=8) break;
    }
    return array_values($out);
}
function quoteTarget(array $note): string {
    foreach (['quote','quoteUrl','quoteUri','_misskey_quote'] as $key) foreach (objectList($note[$key]??[]) as $value) {
        $id=firstId($value); if (isSafePublicUrl($id)) return $id;
    }
    return '';
}
function queueEnrichment(string $kind,string $ref,bool $force=false): void {
    if (!in_array($kind,['actor','note','preview'],true) || !isSafePublicUrl($ref)) return;
    $old=queryOne('SELECT * FROM enrichment_jobs WHERE kind=? AND ref=?',[$kind,$ref]);
    if (!$old) executeSql('INSERT INTO enrichment_jobs(kind,ref,run_after,updated_at) VALUES(?,?,?,?)',[$kind,$ref,time(),time()]);
    elseif ($force || ($old['status']!=='pending' && (int)$old['updated_at']<time()-21600)) executeSql("UPDATE enrichment_jobs SET status='pending',attempts=0,run_after=?,updated_at=? WHERE kind=? AND ref=?",[time(),time(),$kind,$ref]);
}
function contentLinks(string $content): array {
    $urls=[];
    foreach (socialTokens($content) as $token) if ($token['kind']==='link') {
        $url=str_starts_with($token['text'],'www.')?'https://'.$token['text']:$token['text'];
        if (isSafePublicUrl($url)) $urls[$url]=$url;
    }
    return array_values(array_slice($urls,0,2));
}
function noteLinks(array $p): array {
    $urls=contentLinks($p['content']??'');
    $html=$p['content_html']??'';
    if ($html!=='') {
        $doc=new DOMDocument();$previous=libxml_use_internal_errors(true);
        try {
            $doc->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
            foreach ($doc->getElementsByTagName('a') as $a) {
                if (preg_match('/(?:mention|hashtag)/',$a->getAttribute('class'))) continue;
                $url=$a->getAttribute('href');if (!isSafePublicUrl($url)) continue;
                $path=(string)parse_url($url,PHP_URL_PATH);
                if (preg_match('~^/(?:@[^/]+|users/[^/]+|tags/[^/]+)$~',$path)) continue;
                if ($url===($p['quote_of']??'')) continue;
                $urls[]=$url;
            }
        } finally {libxml_clear_errors();libxml_use_internal_errors($previous);}
    }
    if (in_array($p['object_type']??'',['Article','Page'],true) && isSafePublicUrl($p['url']??'')) $urls[]=$p['url'];
    return array_values(array_slice(array_unique($urls),0,2));
}
function queueNoteEnrichment(array $p): void {
    foreach (noteLinks($p) as $url) queueEnrichment('preview',$url);
    $quote=$p['quote_of']??'';
    if ($quote && !localNoteId($quote) && !queryOne('SELECT id FROM remote_tombstones WHERE id=?',[$quote]) && !queryOne('SELECT id FROM remote_notes WHERE id=? AND deleted=0',[$quote])) queueEnrichment('note',$quote);
}
/** Extract metadata only. No oEmbed HTML, arbitrary scripts, or frames are rendered. */
function parseSocialMetadata(string $html,string $url): array {
    $doc=new DOMDocument();$previous=libxml_use_internal_errors(true);
    try {
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
        $meta=[];
        foreach ($doc->getElementsByTagName('meta') as $el) {
            $key=strtolower($el->getAttribute('property')?:($el->getAttribute('name')?:$el->getAttribute('itemprop')));
            if ($key!=='' && !isset($meta[$key])) $meta[$key]=trim($el->getAttribute('content'));
        }
        $image='';
        foreach (['og:image:secure_url','og:image','og:image:url','twitter:image','twitter:image:src','image','thumbnailurl'] as $key) if (!empty($meta[$key])) {
            $candidate=absoluteUrl($url,$meta[$key]);if (isSafePublicUrl($candidate)) {$image=$candidate;break;}
        }
        $oembed='';
        foreach ($doc->getElementsByTagName('link') as $el) {
            $rel=preg_split('/\s+/',strtolower($el->getAttribute('rel')));
            if (!$image && in_array('image_src',$rel,true)) $image=remoteUrlValue(absoluteUrl($url,$el->getAttribute('href')));
            if (!$oembed && in_array('alternate',$rel,true) && str_contains(strtolower($el->getAttribute('type')),'json+oembed')) $oembed=remoteUrlValue(absoluteUrl($url,$el->getAttribute('href')));
        }
        if (!$image) foreach ($doc->getElementsByTagName('script') as $el) {
            if (strtolower($el->getAttribute('type'))!=='application/ld+json') continue;
            $data=json_decode($el->textContent,true,24);
            $find=static function(mixed $item,int $depth=0) use (&$find,$url): string {
                if (!is_array($item) || $depth>5) return '';
                foreach (['image','thumbnailUrl'] as $key) if (isset($item[$key])) {
                    foreach (objectList($item[$key]) as $v) {
                        $raw=is_string($v)?$v:stringField($v['url']??$v['contentUrl']??'');
                        $candidate=$raw?absoluteUrl($url,$raw):'';if (isSafePublicUrl($candidate)) return $candidate;
                    }
                }
                foreach ($item as $v) if (is_array($v) && ($found=$find($v,$depth+1))) return $found;
                return '';
            };
            if ($image=$find($data)) break;
        }
        // WordPress and other article themes sometimes omit social metadata.
        if (!$image) foreach ($doc->getElementsByTagName('img') as $img) {
            $class=' '.$img->getAttribute('class').' ';
            if (preg_match('/wp-post-image|featured-image|attachment-post-thumbnail/',$class)) {
                $raw=$img->getAttribute('src')?:$img->getAttribute('data-src');
                if ($image=remoteUrlValue(absoluteUrl($url,$raw))) break;
            }
        }
        $title=$meta['og:title']??$meta['twitter:title']??($doc->getElementsByTagName('title')->item(0)?->textContent??'');
        return ['title'=>clip(remotePlain($title),180),'description'=>clip(remotePlain($meta['og:description']??$meta['twitter:description']??$meta['description']??''),400),'image'=>$image,'site_name'=>clip(remotePlain($meta['og:site_name']??(string)parse_url($url,PHP_URL_HOST)),100),'oembed'=>$oembed];
    } finally {libxml_clear_errors();libxml_use_internal_errors($previous);}
}
function refreshLinkPreview(string $url): void {
    $result=httpRequest($url,'GET',null,false,0,'text/html,application/xhtml+xml;q=0.9');
    $type=strtolower($result['headers']['content-type']??'');
    if (str_starts_with($type,'image/')) $card=['title'=>(string)parse_url($url,PHP_URL_HOST),'description'=>'','image'=>$url,'site_name'=>(string)parse_url($url,PHP_URL_HOST),'oembed'=>''];
    elseif (str_contains($type,'html')) $card=parseSocialMetadata($result['body'],$result['url']);
    else throw new RuntimeException('No HTML or image preview available');
    if (!$card['image'] && $card['oembed']) {
        try {
            $json=remoteJson($card['oembed'],false);
            $card['image']=remoteUrlValue($json['thumbnail_url']??'');
            if (!$card['title']) $card['title']=clip(remotePlain($json['title']??''),180);
        } catch (Throwable) { /* a text card is still useful */ }
    }
    executeSql('INSERT INTO link_previews(url,title,description,image,site_name,fetched_at) VALUES(?,?,?,?,?,?) ON CONFLICT(url) DO UPDATE SET title=excluded.title,description=excluded.description,image=excluded.image,site_name=excluded.site_name,fetched_at=excluded.fetched_at,last_error=\'\'',[$url,$card['title'],$card['description'],$card['image'],$card['site_name'],time()]);
}
function processEnrichment(int $limit=3): array {
    $done=0;$errors=0;
    foreach (queryAll("SELECT * FROM enrichment_jobs WHERE status='pending' AND run_after<=? ORDER BY run_after LIMIT ".max(1,min(10,$limit)),[time()]) as $job) {
        try {
            if ($job['kind']==='actor') actorInfo($job['ref'],true);
            elseif ($job['kind']==='note') fetchPublicNote($job['ref']);
            else refreshLinkPreview($job['ref']);
            executeSql("UPDATE enrichment_jobs SET status='done',updated_at=?,last_error='' WHERE kind=? AND ref=?",[time(),$job['kind'],$job['ref']]);$done++;
        } catch (Throwable $e) {
            $n=(int)$job['attempts']+1;
            executeSql('UPDATE enrichment_jobs SET attempts=?,status=?,run_after=?,updated_at=?,last_error=? WHERE kind=? AND ref=?',[$n,$n>=4?'failed':'pending',time()+min(21600,300*2**$n),time(),clip($e->getMessage(),180),$job['kind'],$job['ref']]);$errors++;
        }
    }
    return ['completed'=>$done,'errors'=>$errors];
}
function subscribeInstance(string $value): string {
    $value=trim(strtolower($value));
    $domain=str_contains($value,'://')?(string)parse_url($value,PHP_URL_HOST):$value;
    if (!validHost($domain) || $domain===hostName()) throw new RuntimeException('Enter a remote server hostname, such as mastodon.social.');
    executeSql('INSERT OR IGNORE INTO instance_subscriptions(domain,created_at) VALUES(?,?)',[$domain,time()]);
    return $domain;
}
/** API listings identify candidates; public originals are independently fetched via ActivityPub. */
function importInstanceListing(string $domain,array $statuses,?callable $fetchNote=null): int {
    $fetchNote??=static fn(string $id)=>fetchPublicNote($id);
    $count=0;
    foreach (array_slice($statuses,0,6) as $wrapper) {
        if (!is_array($wrapper) || ($wrapper['visibility']??'')!=='public') continue;
        $boost=is_array($wrapper['reblog']??null);$status=$boost?$wrapper['reblog']:$wrapper;
        if (($status['visibility']??'')!=='public') continue;
        $id=remoteUrlValue($status['uri']??'');if (!$id) continue;
        try {
            $note=$fetchNote($id);if (!$note) continue;
            $booster='';$boostLabel='';
            if ($boost) {
                $candidate=remoteUrlValue($wrapper['account']['uri']??$wrapper['account']['url']??'');
                if (strtolower((string)parse_url($candidate,PHP_URL_HOST))===$domain) {
                    $booster=$candidate;$boostLabel=clip(remotePlain($wrapper['account']['display_name']??''),100)?:('@'.clip(stringField($wrapper['account']['username']??''),60).'@'.$domain);
                    if (!empty($wrapper['account']['uri']) && !queryOne('SELECT id FROM remote_actors WHERE id=?',[$booster])) queueEnrichment('actor',$booster);
                }
            }
            $at=min(time()+60,fromIso(stringField($wrapper['created_at']??'')));
            executeSql('INSERT INTO instance_posts(domain,note_id,boost_actor,boost_label,published_at) VALUES(?,?,?,?,?) ON CONFLICT(domain,note_id) DO UPDATE SET boost_actor=excluded.boost_actor,boost_label=excluded.boost_label,published_at=MAX(instance_posts.published_at,excluded.published_at)',[$domain,$id,$booster,$boostLabel,$at]);
            $count++;
        } catch (Throwable $e) {error_log('Instance object import: '.$e->getMessage());}
    }
    return $count;
}
function pollInstances(int $limit=1): array {
    $added=0;$errors=0;
    foreach (queryAll('SELECT * FROM instance_subscriptions WHERE next_poll<=? ORDER BY next_poll,domain LIMIT '.max(1,min(3,$limit)),[time()]) as $instance) {
        try {
            $reply=httpRequest('https://'.$instance['domain'].'/api/v1/timelines/public?local=true&limit=10','GET',null,false);
            $list=json_decode($reply['body'],true,100,JSON_THROW_ON_ERROR);
            if (!is_array($list) || !array_is_list($list)) throw new RuntimeException('Server did not provide a public timeline');
            $added+=importInstanceListing($instance['domain'],$list);
            executeSql('UPDATE instance_subscriptions SET last_checked_at=?,next_poll=?,last_error=\'\' WHERE domain=?',[time(),time()+300,$instance['domain']]);
        } catch (Throwable $e) {
            executeSql('UPDATE instance_subscriptions SET last_checked_at=?,next_poll=?,last_error=? WHERE domain=?',[time(),time()+1800,clip($e->getMessage(),180),$instance['domain']]);$errors++;
        }
    }
    return ['imported'=>$added,'errors'=>$errors];
}
function websiteEvidence(string $html,string $url,array $profiles): string {
    if (websiteProof($html,$url,$profiles)) return 'rel-me';
    $site=parse_url($url);
    if (($site['scheme']??'')==='https' && strtolower($site['host']??'')===hostName() && (int)($site['port']??443)===443) return 'same-domain';
    $doc=new DOMDocument();$previous=libxml_use_internal_errors(true);
    try {
        $doc->loadHTML($html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
        foreach ($doc->getElementsByTagName('a') as $el) if (in_array(absoluteUrl($url,trim($el->getAttribute('href'))),$profiles,true)) return 'profile-link';
        return '';
    } finally {libxml_clear_errors();libxml_use_internal_errors($previous);}
}
function returnAfterPostAction(string $fallback='profile'): never {
    // Only accept our known timeline slugs, never an arbitrary redirect destination.
    $page=stringField($_POST['return_page']??$fallback);
    if (!in_array($page,['home','local','federated','profile','bookmarks','boosts','remote'],true)) $page=$fallback;
    $query='page='.$page;
    if ($page==='remote') $query.='&actor='.rawurlencode(stringField($_POST['return_actor']??''));
    redirectQuery($query);
}
function websiteStatus(array $u): array {
    $fresh=(int)$u['website_verified_at']>time()-172800;
    $method=$u['website_method']??'';
    if ($fresh && $method==='rel-me') return ['label'=>'Verified website','confirmed'=>true];
    if ($fresh && $method==='same-domain') return ['label'=>'Same domain as this profile','confirmed'=>true];
    if ($fresh && $method==='profile-link') return ['label'=>'Profile link confirmed','confirmed'=>true];
    if ((int)($u['website_reachable_at']??0)>time()-172800) return ['label'=>'HTTPS checked · Ownership unconfirmed','confirmed'=>false];
    return ['label'=>'Website · Check pending','confirmed'=>false];
}
function profileMediaUrl(string $kind): string {
    $u=user();$file=$u[$kind]??'';
    return identityUrl('media='.rawurlencode($kind).($file?'&v='.substr(hash('sha256',$file),0,16):''));
}
function pageSocialMetadata(string $page): string {
    $u=user();if (!$u) return '';
    $url=localUrl('page=profile');$title=$u['name'].' on XFedi';$description=$u['bio'];
    $image=$u['cover']?profileMediaUrl('cover'):($u['avatar']?profileMediaUrl('avatar'):'');$alt='Profile image';
    if ($page==='post') {
        $p=knownPost(noteUrl((int)($_GET['id']??0)));
        if (!$p) return '';
        $url=postUiUrl(noteUrl((int)$p['id']));$title=mb_substr($p['content'],0,100);$description=mb_substr($p['content'],0,300);
        if ($p['image']) {$image=identityUrl('media=post&id='.$p['id']);$alt=$p['alt']?:'Post image';}
    }
    $meta=['og:type'=>$page==='post'?'article':'profile','og:title'=>$title,'og:description'=>$description,'og:url'=>$url,'og:site_name'=>'XFedi','twitter:card'=>$image?'summary_large_image':'summary','twitter:title'=>$title,'twitter:description'=>$description];
    if ($image) {$meta['og:image']=$image;$meta['og:image:alt']=$alt;$meta['twitter:image']=$image;}
    $html='';foreach ($meta as $key=>$value) $html.='<meta '.(str_starts_with($key,'og:')?'property':'name').'="'.escape($key).'" content="'.escape($value).'">';
    return $html;
}
