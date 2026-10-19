<?php
declare(strict_types=1);
if (!readyConfig()) fail('CONFIGURATION REQUIRED: set XFEDI_URL to the permanent public HTTPS URL of this PHP file.',503);
header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; img-src 'self' https: data: blob:; media-src 'self' https:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; base-uri 'none'; object-src 'none'; frame-ancestors 'none'; form-action 'self'");
// Three supported deployments:
//  1. Cloudflare Tunnel (default): Caddy forwards with HTTPS=on; public side is HTTPS.
//  2. Another TLS-terminating proxy: set XFEDI_TRUST_PROXY=1 and send X-Forwarded-Proto.
//  3. Direct plain-HTTP use with NO proxy (localhost/LAN tests): set
//     XFEDI_ALLOW_INSECURE_HTTP=1, otherwise logins break because Secure cookies
//     are never returned by the browser over http://.
$allowInsecureHttp=(getenv('XFEDI_ALLOW_INSECURE_HTTP')?:'')==='1';
$directTls=in_array(strtolower((string)($_SERVER['HTTPS']??'')),['on','1','true'],true);
$trustedProxy=(getenv('XFEDI_TRUST_PROXY')?:'')==='1' && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))==='https';
$requestSecure=($directTls || $trustedProxy) && !$allowInsecureHttp;
if (!$requestSecure && !$allowInsecureHttp) fail('HTTPS required. Configure the web server / trusted TLS proxy (or set XFEDI_ALLOW_INSECURE_HTTP=1 for local-only HTTP use).',426);
routePretty();
try { initDb(); } catch (Throwable $e) { error_log($e->getMessage()); fail('Database initialization failed; check XFEDI_DATA_DIR permissions',503); }
$me=user();
servePublicEndpoints();

if (isset($_GET['media'])) {
    if (!$me) fail('Not found',404);
    $type=(string)$_GET['media']; $file='';
    if ($type==='avatar' || $type==='cover') $file=$me[$type];
    if ($type==='post') { $p=queryOne('SELECT image FROM posts WHERE id=? AND deleted=0',[(int)($_GET['id']??0)]); $file=$p['image']??''; }
    serveImage($file);
}

if (isset($_GET['ap'])) {
    $ap=(string)$_GET['ap']; $method=$_SERVER['REQUEST_METHOD']??'GET';
    if ($method==='HEAD') $method='GET'; // federation software may probe with HEAD
    if ($ap==='webfinger') {
        if ($method!=='GET') fail('GET required',405);
        if (!$me) fail('Not found',404);
        $resource=trim((string)($_GET['resource']??''));
        // Tolerate queries without the acct: scheme prefix.
        if ($resource!=='' && !str_starts_with(strtolower($resource),'acct:')) $resource='acct:'.$resource;
        $expected='acct:'.$me['username'].'@'.hostName();
        if (strtolower($resource)!==strtolower($expected)) fail('Not found',404);
        jsonResponse(['subject'=>$expected,'aliases'=>[actorUrl(),localUrl('page=profile')],
          'links'=>[['rel'=>'self','type'=>'application/activity+json','href'=>actorUrl()],
                    ['rel'=>'http://webfinger.net/rel/profile-page','type'=>'text/html','href'=>localUrl('page=profile')]]],200,'application/jrd+json');
    }
    if ($ap==='nodeinfo-discovery') {
        jsonResponse(['links'=>[['rel'=>'http://nodeinfo.diaspora.software/ns/schema/2.1',
          'href'=>identityUrl('ap=nodeinfo')]]],200,'application/json');
    }
    if ($ap==='nodeinfo') {
        jsonResponse(['version'=>'2.1','software'=>['name'=>'xfedi','version'=>APP_VERSION],
          'protocols'=>['activitypub'],'services'=>['inbound'=>[],'outbound'=>[]],
          'openRegistrations'=>false,'usage'=>['users'=>['total'=>$me?1:0],'localPosts'=>(int)(queryOne('SELECT COUNT(*) AS n FROM posts WHERE deleted=0')['n']??0)],
          'metadata'=>[]],200,'application/json');
    }
    if (!$me) fail('Not found',404);
    if ($ap==='actor' && $method==='GET') jsonResponse(actorObject());
    if ($ap==='note' && $method==='GET') {
        $p=queryOne('SELECT * FROM posts WHERE id=?',[(int)($_GET['id']??0)]);
        if (!$p) fail('Not found',404);
        if ($p['deleted']) jsonResponse(['@context'=>AS_CTX,'id'=>noteUrl((int)$p['id']),'type'=>'Tombstone','formerType'=>'Note'],410);
        jsonResponse(['@context'=>AS_CTX,...noteObject($p)]);
    }
    if ($ap==='create' && $method==='GET') {
        $p=queryOne('SELECT * FROM posts WHERE id=? AND deleted=0',[(int)($_GET['id']??0)]);
        if (!$p) fail('Not found',404);
        $note=noteObject($p);
        jsonResponse(['@context'=>AS_CTX,'id'=>identityUrl('ap=create&id='.$p['id']), 'type'=>'Create',
          'actor'=>actorUrl(),'published'=>nowIso((int)$p['created_at']),
          'object'=>$note,'to'=>$note['to'],'cc'=>$note['cc']]);
    }
    if ($ap==='activity' && $method==='GET') {
        $ref=stringField($_GET['ref']??'');
        $a=preg_match('/^[0-9a-f]{32}$/D',$ref) ? queryOne('SELECT json FROM activities WHERE ref=?',[$ref]) : null;
        if (!$a) fail('Not found',404);
        jsonResponse(json_decode($a['json'],true));
    }
    if (($ap==='followers' || $ap==='following' || $ap==='outbox') && $method==='GET') {
        $total=($ap==='outbox')?(int)(queryOne('SELECT (SELECT COUNT(*) FROM posts WHERE deleted=0)+(SELECT COUNT(*) FROM boosts_local) AS n')['n']??0):
             ($ap==='followers'?(int)(queryOne('SELECT COUNT(*) AS n FROM followers')['n']??0):(int)(queryOne("SELECT COUNT(*) AS n FROM follows WHERE status='accepted'")['n']??0));
        $uri=identityUrl('ap='.$ap);
        if (!isset($_GET['page'])) jsonResponse(['@context'=>AS_CTX,'id'=>$uri,'type'=>'OrderedCollection',
          'totalItems'=>$total,'first'=>$uri.'&page=true']);
        $offset=min(100000,max(0,(int)($_GET['offset']??0)));
        if ($ap==='outbox') {
            $items=outboxItems($offset);
        } else {
            $col=$ap==='followers'?'followers':'follows';
            $where=$ap==='following'?" WHERE status='accepted'":'';
            $items=array_column(queryAll('SELECT actor_id FROM '.$col.$where.' ORDER BY created_at DESC LIMIT 20 OFFSET '.$offset),'actor_id');
        }
        $out=['@context'=>AS_CTX,'id'=>$uri.'&page=true&offset='.$offset,'type'=>'OrderedCollectionPage',
            'partOf'=>$uri,'orderedItems'=>$items];
        if ($offset+count($items)<$total) $out['next']=$uri.'&page=true&offset='.($offset+20);
        jsonResponse($out);
    }
    if ($ap==='inbox' && $method==='GET')
        jsonResponse(['@context'=>AS_CTX,'id'=>inboxUrl(),'type'=>'OrderedCollection','totalItems'=>0,'orderedItems'=>[]]);
    if ($ap==='inbox' && $method==='POST') {
        if ((int)($_SERVER['CONTENT_LENGTH']??0)>MAX_REMOTE_BYTES) fail('Payload too large',413);
        $input=fopen('php://input','rb');
        $raw=$input ? stream_get_contents($input,MAX_REMOTE_BYTES+1) : false;
        if ($input) fclose($input);
        if ($raw===false || strlen($raw)>MAX_REMOTE_BYTES) fail('Payload too large',413);
        $actor=verifyInbox($raw);
        $body=json_decode($raw,true,100);
        if (!is_array($body) || array_is_list($body)) fail('Invalid ActivityPub JSON');
        try { enqueueInbox($body,$actor); } catch (Throwable $e) { error_log('Inbox: '.$e->getMessage()); fail('Inbox processing failed',500); }
        http_response_code(202); exit;
    }
    fail('Not found',404);
}

