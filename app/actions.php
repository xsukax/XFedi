<?php
declare(strict_types=1);
// The local web UI uses cookie sessions. Fediverse endpoints above are stateless.
ini_set('session.use_strict_mode','1');
ini_set('session.use_only_cookies','1');
ini_set('session.cookie_httponly','1');
ini_set('session.cookie_secure',$requestSecure?'1':'0');
ini_set('session.cookie_samesite','Lax');
session_start();
$flash=stringField($_SESSION['flash']??''); unset($_SESSION['flash']);
$error='';
$draftContent='';
$page=stringField($_GET['page']??'home');
$pages=['home','profile','post','remote_post','remote','following','followers','alerts','settings','login','queue','tag','find','bookmarks','boosts','quote','local','federated','instances'];
if (!in_array($page,$pages,true)) $page='home';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=stringField($_POST['action']??'');
    mustPost();
    try {
        if (!$me && $action==='setup') {
            $setup=getenv('XFEDI_SETUP_TOKEN')?:'';
            if (strlen($setup)<24 || !hash_equals($setup,stringField($_POST['setup_token']??''))) throw new RuntimeException('A valid installation setup token is required. See the private .env file.');
            $username=strtolower(clip(stringField($_POST['username']??''),30));
            $password=stringField($_POST['password']??'');
            if (!preg_match('/^[a-z][a-z0-9_]{2,29}$/D',$username)) throw new RuntimeException('Username: 3–30 lowercase letters, numbers, underscores; start with a letter.');
            if (strlen($password)<12 || strlen($password)>200) throw new RuntimeException('Use a password of at least 12 characters.');
            $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
            if (!$key || !openssl_pkey_export($key,$private)) throw new RuntimeException('Cannot generate RSA key');
            $public=openssl_pkey_get_details($key)['key'];
            executeSql('INSERT INTO users(id,username,name,password_hash,private_key,public_key,created_at) VALUES(1,?,?,?,?,?,?)',
                [$username,$username,password_hash($password,PASSWORD_DEFAULT),$private,$public,time()]);
            session_regenerate_id(true); $_SESSION['uid']=1; $_SESSION['auth_stamp']=hash('sha256',user()['password_hash']); redirectTo('profile');
        }
        if ($action==='login') {
            $ipHash=hash('sha256',clientIp());
            $limits=queryOne('SELECT * FROM login_attempts WHERE ip_hash=?',[$ipHash]);
            if ($limits && $limits['window_start']>time()-900 && $limits['attempts']>=8)
                throw new RuntimeException('Too many login attempts. Try again in 15 minutes.');
            $u=user();
            if (!$u || !hash_equals($u['username'],stringField($_POST['username']??'')) ||
                !password_verify(stringField($_POST['password']??''),$u['password_hash'])) {
                executeSql('INSERT INTO login_attempts(ip_hash,attempts,window_start) VALUES(?,1,?)
                    ON CONFLICT(ip_hash) DO UPDATE SET attempts=CASE WHEN window_start<? THEN 1 ELSE attempts+1 END,
                    window_start=CASE WHEN window_start<? THEN excluded.window_start ELSE window_start END',
                    [$ipHash,time(),time()-900,time()-900]);
                throw new RuntimeException('Invalid username or password.');
            }
            executeSql('DELETE FROM login_attempts WHERE ip_hash=?',[$ipHash]);
            session_regenerate_id(true); $_SESSION['uid']=1; $_SESSION['auth_stamp']=hash('sha256',user()['password_hash']); redirectTo();
        }
        requireLogin();
        if (handleExtraAction($action)) exit;
        if ($action==='logout') { $_SESSION=[]; session_destroy(); redirectTo(); }
        if ($action==='post' || $action==='reply' || $action==='quote') {
            $content=trim(stringField($_POST['content']??''));
            if ($content==='' || mb_strlen($content)>POST_LIMIT) throw new RuntimeException('Post must contain 1–500 characters.');
            $target=clip(stringField($_POST['in_reply_to']??''),2048);
            if ($target!=='' && localNoteId($target)===0) {
                $known=queryOne('SELECT id FROM remote_notes WHERE id=? AND deleted=0',[$target]);
                if (!$known) throw new RuntimeException('Reply target is not a known post.');
            }
            $quote=clip(stringField($_POST['quote_of']??''),2048);
            if ($quote!=='' && !knownPost($quote)) throw new RuntimeException('Quote target is not available.');
            // Resolve explicitly addressed remote users for ActivityPub Mention tags and delivery.
            resolvedMentions($content,true);
            $image=uploadImage('image',8388608);
            db()->beginTransaction();
            executeSql('INSERT INTO posts(content,image,alt,in_reply_to,created_at,updated_at,quote_of) VALUES(?,?,?,?,?,?,?)',
                [$content,$image,clip(stringField($_POST['alt']??''),160),$target,time(),time(),$quote]);
            $p=queryOne('SELECT * FROM posts WHERE id=?',[(int)db()->lastInsertId()]);
            syncTags(noteUrl((int)$p['id']),$content);
            queueNoteEnrichment($p);
            postActivity($p); $_SESSION['flash']='Post published. Federation delivery has been queued.';
            redirectTo($target!==''?'home':'profile');
        }
        if ($action==='edit') {
            $id=(int)($_POST['id']??0); $content=trim(stringField($_POST['content']??''));
            if ($content==='' || mb_strlen($content)>POST_LIMIT) throw new RuntimeException('Post must contain 1–500 characters.');
            resolvedMentions($content,true);
            db()->beginTransaction();
            syncTags(noteUrl($id),$content);
            executeSql('UPDATE posts SET content=?,updated_at=? WHERE id=? AND deleted=0',[$content,time(),$id]);
            $p=queryOne('SELECT * FROM posts WHERE id=? AND deleted=0',[$id]);
            if ($p) {queueNoteEnrichment($p);postActivity($p,'Update');}
            $_SESSION['flash']='Post edited and an Update activity was queued.'; redirectTo('profile');
        }
        if ($action==='delete') {
            $id=(int)($_POST['id']??0);
            $p=queryOne('SELECT id FROM posts WHERE id=? AND deleted=0',[$id]);
            if ($p) { db()->beginTransaction(); executeSql('DELETE FROM tags WHERE note_id=?',[noteUrl($id)]); executeSql('DELETE FROM boosts_local WHERE note_id=?',[noteUrl($id)]); executeSql('UPDATE posts SET deleted=1,updated_at=? WHERE id=?',[time(),$id]); deleteActivity($id); }
            $_SESSION['flash']='Post deleted locally. Remote deletion was queued.'; returnAfterPostAction();
        }
        if ($action==='follow') {
            $handle=clip(stringField($_POST['handle']??''),250);
            $actor=isSafePublicUrl($handle)?actorInfo($handle):discover($handle);
            if ($actor['id']===actorUrl()) throw new RuntimeException('Cannot follow yourself');
            $old=queryOne('SELECT * FROM follows WHERE actor_id=?',[$actor['id']]);
            if (!$old || $old['status']==='rejected') {
                $a=newActivity('Follow',$actor['id'],[$actor['id']]);
                executeSql("INSERT INTO follows(actor_id,follow_id,status,created_at) VALUES(?,?,'pending',?)
                    ON CONFLICT(actor_id) DO UPDATE SET follow_id=excluded.follow_id,status='pending'",[$actor['id'],$a['id'],time()]);
                queueActivity($a,[$actor['id']]);
            }
            subscribeInstance((string)parse_url($actor['id'],PHP_URL_HOST));
            try { importOutbox($actor); } catch (Throwable $e) { error_log('Initial outbox import: '.$e->getMessage()); }
            $_SESSION['flash']='Follow request queued for '.$actor['handle'].'.'; redirectTo('following');
        }
        if ($action==='unfollow') {
            $id=clip(stringField($_POST['actor_id']??''),2048);
            $f=queryOne('SELECT * FROM follows WHERE actor_id=?',[$id]);
            if ($f) {
                $original=['type'=>'Follow','id'=>$f['follow_id'],'actor'=>actorUrl(),'object'=>$id];
                queueActivity(newActivity('Undo',$original,[$id]),[$id]);
                executeSql('DELETE FROM follows WHERE actor_id=?',[$id]);
            }
            $_SESSION['flash']='Unfollow activity queued.'; redirectTo('following');
        }
        if ($action==='like_remote' || $action==='unlike_remote') {
            $noteId=clip(stringField($_POST['note_id']??''),2048);
            $note=queryOne('SELECT * FROM remote_notes WHERE id=? AND deleted=0',[$noteId]);
            if (!$note) throw new RuntimeException('Remote post not found');
            $liked=queryOne('SELECT activity_id FROM likes_local WHERE note_id=?',[$noteId]);
            if ($action==='like_remote' && !$liked) {
                $a=newActivity('Like',$noteId,[$note['author_id']]);
                executeSql('INSERT INTO likes_local(note_id,activity_id) VALUES(?,?)',[$noteId,$a['id']]);
                queueActivity($a,[$note['author_id']]);
            } elseif ($action==='unlike_remote' && $liked) {
                $original=['type'=>'Like','id'=>$liked['activity_id'],'actor'=>actorUrl(),'object'=>$noteId];
                queueActivity(newActivity('Undo',$original,[$note['author_id']]),[$note['author_id']]);
                executeSql('DELETE FROM likes_local WHERE note_id=?',[$noteId]);
            }
            $_SESSION['flash']='Like activity queued.'; redirectQuery('page=remote_post&id='.rawurlencode($noteId));
        }
        if ($action==='profile') {
            $name=clip(stringField($_POST['name']??''),100);
            $bio=clip(stringField($_POST['bio']??''),500);
            if ($name==='') throw new RuntimeException('Display name required');
            $avatar=uploadImage('avatar'); $cover=uploadImage('cover');
            $timezone=stringField($_POST['timezone']??'UTC');
            if (!in_array($timezone,DateTimeZone::listIdentifiers(),true) && $timezone!=='UTC') throw new RuntimeException('Choose a valid timezone.');
            $website=trim(stringField($_POST['website']??''));
            if ($website!=='' && !isSafePublicUrl($website)) throw new RuntimeException('Website must be a public HTTPS URL.');
            $fields=['name=?','bio=?','timezone=?','website=?'];$values=[$name,$bio,$timezone,$website];
            if ($website!==$me['website']) { $fields[]='website_verified_at=0'; $fields[]='website_checked_at=0'; $fields[]='website_reachable_at=0'; $fields[]="website_method=''"; $fields[]="website_title=''"; $fields[]="website_error=''"; }
            db()->beginTransaction();
            if ($avatar) {$fields[]='avatar=?'; $values[]=$avatar;}
            if ($cover) {$fields[]='cover=?'; $values[]=$cover;}
            executeSql('UPDATE users SET '.implode(',',$fields).' WHERE id=1',$values);
            executeSql("INSERT INTO metadata(key,value) VALUES('verify_due',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",[(string)time()]);
            queueActivity(newActivity('Update',actorObject(),[AS_PUBLIC],[localUrl('ap=followers')]),followerIds());
            $_SESSION['flash']='Profile saved. Website verification and federation updates are queued.'; redirectTo('settings');
        }
        if ($action==='password') {
            $old=stringField($_POST['old_password']??''); $new=stringField($_POST['new_password']??'');
            if (!password_verify($old,user()['password_hash'])) throw new RuntimeException('Current password incorrect');
            if (strlen($new)<12 || strlen($new)>200) throw new RuntimeException('New password must be at least 12 characters');
            executeSql('UPDATE users SET password_hash=? WHERE id=1',[password_hash($new,PASSWORD_DEFAULT)]);
            session_regenerate_id(true); $_SESSION['auth_stamp']=hash('sha256',user()['password_hash']);
            $_SESSION['flash']='Password changed.'; redirectTo('settings');
        }
        if ($action==='worker') {
            $r=runBackground(); $_SESSION['flash']='Delivery results: '.jenc($r); redirectTo('queue');
        }
        if ($action==='refresh') {
            $actors=queryAll('SELECT a.* FROM remote_actors a JOIN follows f ON a.id=f.actor_id ORDER BY RANDOM() LIMIT 8');
            $count=0;
            foreach ($actors as $a) { try { $count+=importOutbox($a); } catch (Throwable $e) { error_log('Refresh: '.$e->getMessage()); } }
            $_SESSION['flash']='Feed refresh checked '.count($actors).' accounts and read '.$count.' activities.'; redirectTo();
        }
        if ($action==='retry_failed') {
            executeSql("UPDATE enrichment_jobs SET status='pending',attempts=0,run_after=?,last_error='' WHERE status='failed'",[time()]);
            executeSql('UPDATE instance_subscriptions SET next_poll=0 WHERE last_error<>\'\'');
            executeSql("UPDATE inbox_jobs SET status='pending',attempts=0,run_after=?,last_error='' WHERE status='failed'",[time()]);
            executeSql("UPDATE deliveries SET status='pending',attempts=0,run_after=?,last_error='' WHERE status='failed'",[time()]);
            $_SESSION['flash']='Failed deliveries returned to the queue.'; redirectTo('queue');
        }
        throw new RuntimeException('Unrecognized action.');
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('UI action '.$action.': '.$e->getMessage());
        $error=$e->getMessage();
        if ($action==='post') $draftContent=stringField($_POST['content']??'');
        if (!$me) $page='setup';
    }
}
$me=user(); $auth=signedIn();
if (!$auth && in_array($page,['settings','alerts','queue','bookmarks','boosts','quote','find','instances'],true)) $page='login';
header('Cache-Control: private, no-store');
if (in_array($page,['post','remote_post'],true)) { $object=$page==='post'?noteUrl((int)($_GET['id']??0)):stringField($_GET['id']??''); if (!knownPost($object)) http_response_code(404); }

