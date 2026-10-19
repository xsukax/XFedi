<?php
declare(strict_types=1);
function hidden(string $name,string|int $value): void { echo '<input type="hidden" name="'.escape($name).'" value="'.escape((string)$value).'">'; }
function formStart(string $action,string $class=''): void { echo '<form method="post" class="'.escape($class).'">'; hidden('_csrf',csrf()); hidden('action',$action); }
function emptyState(string $title,string $body=''): void { echo '<div class="empty"><span class="empty-mark" aria-hidden="true">✦</span><h2>'.escape($title).'</h2><p>'.escape($body).'</p></div>'; }
function interaction(string $action,string $id,string $label,bool $active=false): void {
    formStart($action); hidden('note_id',$id); echo '<button class="post-action'.($active?' selected':'').'" type="submit" aria-pressed="'.($active?'true':'false').'">'.escape($label).'</button></form>';
}
function renderMedia(array $items): void {
    if (!$items) return;
    echo '<div class="media-grid media-count-'.min(4,count($items)).'">';
    foreach ($items as $item) {
        $url=$item['url']??'';if (!safeBrowserLink($url)) continue;
        $alt=($item['alt']??'')?:'Media attachment';
        if (($item['type']??'image')==='image') echo '<a class="media-tile" href="'.escape($url).'" target="_blank" rel="noopener noreferrer"><img loading="lazy" decoding="async" src="'.escape($url).'" alt="'.escape($alt).'" referrerpolicy="no-referrer"></a>';
        elseif (in_array($item['type']??'',['video','audio'],true)) {
            $tag=$item['type'];$poster=($tag==='video' && isSafePublicUrl($item['poster']??''))?' poster="'.escape($item['poster']).'"':'';
            echo '<div class="media-player"><'.$tag.' controls preload="none"'.$poster.' aria-label="'.escape($alt).'" src="'.escape($url).'"></'.$tag.'><a href="'.escape($url).'" rel="noopener noreferrer" target="_blank">Open '.escape($tag).' ↗</a></div>';
        }
    }
    echo '</div>';
}
function renderQuotePreview(string $id): void {
    $quoted=knownPost($id);
    echo '<a class="quote-preview" href="'.escape($quoted?postUiUrl($id):publicPostLink($id)).'"><span class="eyebrow">❞ Quoted post</span>';
    if ($quoted) {
        $a=$quoted['_remote']?queryOne('SELECT * FROM remote_actors WHERE id=?',[$quoted['author_id']]):user();
        if ($a) echo '<strong class="quote-author">'.escape($a['name']?:$a['username']).'</strong>';
        if (!empty($quoted['summary'])) echo '<p>Content warning: '.escape($quoted['summary']).'</p>';
        else {
            echo '<p>'.escape(mb_substr($quoted['content'],0,280)).'</p>';
            $image=$quoted['_remote']?$quoted['image']:($quoted['image']?identityUrl('media=post&id='.$quoted['id']):'');
            if (empty($quoted['sensitive']) && safeBrowserLink($image)) echo '<img class="quote-image" loading="lazy" src="'.escape($image).'" alt="'.escape($quoted['alt']??'Quoted image').'" referrerpolicy="no-referrer">';
        }
    } else echo '<p>The original is being retrieved. Open its source ↗</p>';
    echo '</a>';
}
function renderLinkPreview(array $p): void {
    foreach (noteLinks($p) as $url) {
        if ($url===($p['quote_of']??'')) continue;
        $card=queryOne('SELECT * FROM link_previews WHERE url=?',[$url]);if (!$card || (!$card['title'] && !$card['image'])) continue;
        echo '<a class="link-card" href="'.escape($url).'" target="_blank" rel="noopener noreferrer">';
        if (safeBrowserLink($card['image'])) echo '<img loading="lazy" decoding="async" src="'.escape($card['image']).'" alt="" referrerpolicy="no-referrer">';
        echo '<span class="link-card-body"><small>'.escape($card['site_name']).'</small><strong>'.escape($card['title']?:$url).'</strong>';
        if ($card['description']) echo '<span>'.escape($card['description']).'</span>';
        echo '</span></a>';break;
    }
}
function postCard(array $p,bool $remote,?string $boostedBy=null): void {
    $id=$remote?$p['id']:noteUrl((int)$p['id']);
    $a=$remote?(queryOne('SELECT * FROM remote_actors WHERE id=?',[$p['author_id']])?:[]):user();
    if (!$a) return;
    $a['avatar']=$remote?($a['avatar']??''):($a['avatar']?profileMediaUrl('avatar'):'');
    $profile=$remote?localUrl('page=remote&actor='.rawurlencode($p['author_id'])):localUrl('page=profile');
    $handle=$remote?($a['handle']?:$p['author_id']):localHandle();
    $img=$remote?$p['image']:($p['image']?identityUrl('media=post&id='.$p['id']):'');
    $local=localNoteId($id);
    $likes=(int)(queryOne('SELECT COUNT(*) n FROM likes_local WHERE note_id=?',[$id])['n']??0)+($local?(int)(queryOne('SELECT COUNT(*) n FROM likes_remote WHERE post_id=?',[$local])['n']??0):0);
    $boosts=(int)(queryOne('SELECT COUNT(*) n FROM boosts_remote WHERE note_id=?',[$id])['n']??0)+(int)(queryOne('SELECT COUNT(*) n FROM boosts_local WHERE note_id=?',[$id])['n']??0);
    ?>
    <article class="post" id="<?= escape('p-'.substr(hash('sha256',$id),0,12)) ?>">
    <?php if ($boostedBy): ?><div class="boost-label">↻ <?= escape($boostedBy) ?> boosted</div><?php endif ?>
    <div class="post-header"><a href="<?= escape($profile) ?>" aria-label="<?= escape($a['name']??$handle) ?> profile"><?= pillAvatar($a,44) ?></a><div class="post-meta"><a class="author" href="<?= escape($profile) ?>"><?= escape(($a['name']??'')?:($a['username']??'Account')) ?></a><span class="handle"><?= escape($handle) ?></span></div><a class="post-time" href="<?= escape(postUiUrl($id)) ?>"><time datetime="<?= nowIso((int)$p['created_at']) ?>" title="<?= escape(displayTime((int)$p['created_at'])) ?>"><?= escape(humanTime((int)$p['created_at'])) ?></time></a></div>
    <?php if (!empty($p['in_reply_to'])): ?><a class="reply-context" href="<?= escape(postUiUrl($p['in_reply_to'])) ?>">↳ In reply to a post</a><?php endif ?>
    <?php if ($remote && $p['summary']): ?><details class="content-warning"><summary><?= escape($p['summary']) ?> <small>Show content</small></summary><?php endif ?>
    <div class="post-content"><?= $remote?sanitizeRemoteHtml($p['content_html']?:escape($p['content'])):visibleText($p['content']) ?></div>
    <?php if (!empty($p['quote_of'])) renderQuotePreview($p['quote_of']); ?>
    <?php
    $media=$remote?(json_decode($p['media_json']??'[]',true)?:[]):[];
    if (!$media && $img) $media=[['url'=>$img,'type'=>'image','alt'=>($p['alt']??'')?:'Image attachment']];
    if ($remote && !empty($p['sensitive']) && !$p['summary'] && $media) echo '<details class="sensitive-media"><summary>Sensitive media · Show</summary>';
    renderMedia($media);renderLinkPreview($p);
    if ($remote && !empty($p['sensitive']) && !$p['summary'] && $media) echo '</details>';
    ?>
    <?php if ($remote && $p['summary']): ?></details><?php endif ?>
    <div class="post-actions"><a class="post-action" href="<?= escape(postUiUrl($id)) ?>">↩ Reply</a>
    <?php if (signedIn()):
        $liked=queryOne('SELECT note_id FROM likes_local WHERE note_id=?',[$id]);
        $boosted=queryOne('SELECT note_id FROM boosts_local WHERE note_id=?',[$id]);
        $saved=queryOne('SELECT note_id FROM bookmarks WHERE note_id=?',[$id]);
        interaction($boosted?'unboost':'boost',$id,'↻ '.($boosted?'Boosted':'Boost').($boosts?' '.$boosts:''),(bool)$boosted);
        interaction($liked?'unlike':'like',$id,($liked?'♥':'♡').' '.($likes?:'Favourite'),(bool)$liked);
        ?><a class="post-action" href="<?= escape(localUrl('page=quote&id='.rawurlencode($id))) ?>">❞ Quote</a><?php
        interaction($saved?'unbookmark':'bookmark',$id,$saved?'▣ Saved':'▢ Save',(bool)$saved);
    else: ?><span class="post-action stat">♡ <?= $likes ?> · ↻ <?= $boosts ?></span><?php endif ?>
    <?php if (signedIn()): formStart($remote?'hide_remote':'delete','post-delete confirm-delete');hidden($remote?'note_id':'id',$remote?$id:$p['id']);hidden('return_page',in_array($GLOBALS['page']??'home',['home','local','federated','profile','bookmarks','boosts','remote'],true)?$GLOBALS['page']:'profile');hidden('return_actor',stringField($_GET['actor']??'')); ?><button class="post-action <?= $remote?'':'delete-action' ?>" type="submit" aria-label="<?= $remote?'Hide this remote post locally':'Delete your post' ?>"><?= $remote?'Hide locally':'Delete' ?></button></form><?php endif ?>
    <a class="post-action original" href="<?= escape(publicPostLink($id)) ?>" target="_blank" rel="noopener noreferrer">↗ <span>Original</span></a></div>
    </article><?php
}
function composer(string $reply='',string $quote='',string $draft=''): void { ?>
    <section class="composer panel"><div class="compose-heading"><h2><?= $reply?'Write a reply':($quote?'Quote with a link':'Share something') ?></h2><span class="visibility">◎ Public</span></div>
    <form method="post" enctype="multipart/form-data" id="postComposer"><?php hidden('_csrf',csrf()); hidden('action',$quote?'quote':($reply?'reply':'post')); hidden('in_reply_to',$reply); hidden('quote_of',$quote); ?>
    <?php if ($quote): ?><p class="hint quote-help">Your commentary and a link to the original are shared publicly. Rich quote previews and approval controls depend on the receiving server.</p><a class="quote-preview" href="<?= escape(postUiUrl($quote)) ?>"><?= escape(mb_substr(knownPost($quote)['content']??'',0,200)) ?></a><?php endif ?>
    <div class="compose-editor"><label for="composeText" class="compose-label"><?= $reply?'Reply text':'Post text' ?></label><textarea id="composeText" name="content" rows="4" class="compose-input" required placeholder="What’s on your mind?" aria-describedby="composeHint"><?= escape($draft) ?></textarea><p id="composeHint" class="hint">Add a #hashtag or mention @someone@their.server. Everyone can read this post.</p></div>
    <div id="attachmentPanel" class="attachment-panel" hidden><img id="attachmentPreview" alt="Attachment preview"><div><strong id="attachmentName"></strong><label for="altText">Image description</label><input id="altText" name="alt" maxlength="160"></div><button id="removeAttachment" type="button" aria-label="Remove attachment">×</button></div>
    <div class="compose-bottom"><label class="attach-button" for="uploadPost">▧ Add photo</label><input class="visually-hidden" id="uploadPost" type="file" name="image" accept="image/png,image/jpeg,image/gif,image/webp"><span id="composeCounter" aria-live="polite">500 left</span><button id="publishButton" type="submit" class="btn primary"><?= $reply?'Reply':'Publish' ?> ↗</button></div></form></section>
<?php }
function entries(string $mode='home',string $arg='',int $page=1): array {
    $offset=(max(1,min(100000,$page))-1)*30;
    $params=[]; $localWhere='p.deleted=0'; $remoteWhere='r.deleted=0';
    if ($mode==='home') $remoteWhere.=signedIn()?" AND (EXISTS(SELECT 1 FROM follows f WHERE f.actor_id=r.author_id AND f.status='accepted') OR EXISTS(SELECT 1 FROM boosts_remote b JOIN follows f ON f.actor_id=b.actor_id WHERE b.note_id=r.id AND f.status='accepted') OR EXISTS(SELECT 1 FROM boosts_local b WHERE b.note_id=r.id))":' AND 0';
    if ($mode==='local') $remoteWhere='0';
    if ($mode==='federated' && $arg!=='') {$localWhere='0';$remoteWhere.=' AND EXISTS(SELECT 1 FROM instance_posts ip WHERE ip.note_id=r.id AND ip.domain=?)';$params[]=$arg;}
    $localWhere.=' AND NOT EXISTS(SELECT 1 FROM hidden_posts h WHERE h.note_id='.db()->quote(identityUrl('ap=note&id=')).' || p.id)';
    $remoteWhere.=' AND NOT EXISTS(SELECT 1 FROM hidden_posts h WHERE h.note_id=r.id)';
    if ($mode==='profile') $remoteWhere.=' AND EXISTS(SELECT 1 FROM boosts_local b WHERE b.note_id=r.id)';
    if ($mode==='remote') { $localWhere='0'; $remoteWhere.=' AND r.author_id=?'; $params[]=$arg; }
    if ($mode==='tag') {
        $localWhere.=' AND EXISTS(SELECT 1 FROM tags t WHERE t.note_id=? || p.id AND t.tag=?)';
        $remoteWhere.=' AND EXISTS(SELECT 1 FROM tags t WHERE t.note_id=r.id AND t.tag=?)';
        $params=[identityUrl('ap=note&id='),mb_strtolower($arg),mb_strtolower($arg)];
    }
    if ($mode==='bookmarks' || $mode==='boosts') {
        $table=$mode==='bookmarks'?'bookmarks':'boosts_local';
        $localWhere.=' AND EXISTS(SELECT 1 FROM '.$table.' b WHERE b.note_id=? || p.id)';
        $remoteWhere.=' AND EXISTS(SELECT 1 FROM '.$table.' b WHERE b.note_id=r.id)';
        $params=[identityUrl('ap=note&id=')];
    }
    $prefix=db()->quote(identityUrl('ap=note&id='));
    $localAt='p.created_at'; $remoteAt='r.created_at';
    if (in_array($mode,['home','profile','boosts','federated'],true)) {
        $localAt='MAX(p.created_at,COALESCE((SELECT MAX(b.created_at) FROM boosts_local b WHERE b.note_id='.$prefix.' || p.id),0))';
        $remoteAt="MAX(r.created_at,COALESCE((SELECT MAX(b.created_at) FROM boosts_local b WHERE b.note_id=r.id),0),COALESCE((SELECT MAX(b.created_at) FROM boosts_remote b JOIN follows f ON f.actor_id=b.actor_id WHERE b.note_id=r.id AND f.status='accepted'),0),COALESCE((SELECT MAX(ip.published_at) FROM instance_posts ip WHERE ip.note_id=r.id),0))";
    } elseif ($mode==='bookmarks') {
        $localAt='(SELECT b.created_at FROM bookmarks b WHERE b.note_id='.$prefix.' || p.id)';
        $remoteAt='(SELECT b.created_at FROM bookmarks b WHERE b.note_id=r.id)';
    }
    $refs=queryAll('SELECT CAST(p.id AS TEXT) id,0 remote,'.$localAt.' at FROM posts p WHERE '.$localWhere.' UNION ALL SELECT r.id,1 remote,'.$remoteAt.' at FROM remote_notes r WHERE '.$remoteWhere.' ORDER BY at DESC,remote,id DESC LIMIT 31 OFFSET '.$offset,$params);
    return $refs;
}
function renderEntries(string $mode='home',string $arg=''): void {
    $n=max(1,(int)($_GET['p']??1)); $refs=entries($mode,$arg,$n); $more=count($refs)>30;
    if (!$refs) emptyState('A little quiet here','New posts and interactions will appear here.');
    foreach (array_slice($refs,0,30) as $r) {
        $p=queryOne('SELECT * FROM '.($r['remote']?'remote_notes':'posts').' WHERE id=?',[$r['id']]);
        $id=$r['remote']?$r['id']:noteUrl((int)$r['id']);
        $boost=queryOne('SELECT note_id FROM boosts_local WHERE note_id=?',[$id]);
        $remoteBoost=queryOne("SELECT b.actor_id FROM boosts_remote b JOIN follows f ON f.actor_id=b.actor_id WHERE f.status='accepted' AND b.note_id=? ORDER BY b.created_at DESC LIMIT 1",[$id]);
        $instanceBoost=$mode==='federated'?queryOne("SELECT boost_actor,boost_label FROM instance_posts WHERE note_id=? AND boost_actor<>'' ORDER BY published_at DESC LIMIT 1",[$id]):null;
        postCard($p,(bool)$r['remote'],$boost?'You':($remoteBoost?actorLabel($remoteBoost['actor_id']):($instanceBoost?($instanceBoost['boost_label']?:actorLabel($instanceBoost['boost_actor'])):null)));
    }
    if ($n>1 || $more) {
        $query=$_GET; unset($query['local_username']);
        echo '<nav class="pagination" aria-label="Posts pages">';
        if ($n>1) { $query['p']=$n-1; echo '<a class="btn" href="'.escape(localUrl(http_build_query($query))).'">← Newer</a>'; }
        if ($more) { $query['p']=$n+1; echo '<a class="btn" href="'.escape(localUrl(http_build_query($query))).'">Older →</a>'; }
        echo '</nav>';
    }
}
$stats=['posts'=>(int)queryOne('SELECT COUNT(*) n FROM posts WHERE deleted=0')['n'],'following'=>(int)queryOne("SELECT COUNT(*) n FROM follows WHERE status='accepted'")['n'],'followers'=>(int)queryOne('SELECT COUNT(*) n FROM followers')['n'],'unread'=>(int)queryOne('SELECT COUNT(*) n FROM notifications WHERE is_read=0')['n'],'queued'=>(int)queryOne("SELECT COUNT(*) n FROM deliveries WHERE status='pending'")['n']];
$title=match($page){'profile'=>'Profile','post','remote_post'=>'Conversation','remote'=>'Remote profile','alerts'=>'Notifications','find'=>'Search','queue'=>'Delivery status','federated'=>'Federated','local'=>'Local','instances'=>'Instances','tag'=>'#'.stringField($_GET['tag']??''),default=>ucfirst($page)};
$navigation=['home'=>['⌂','Home'],'federated'=>['◎','Federated'],'instances'=>['▦','Instances'],'find'=>['⌕','Search'],'alerts'=>['♧','Notifications'],'bookmarks'=>['▢','Bookmarks'],'boosts'=>['↻','Your boosts'],'profile'=>['◉','Profile'],'following'=>['☷','Following'],'settings'=>['⚙','Settings'],'queue'=>['↗','Delivery status']];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><?= pageSocialMetadata($page) ?><meta name="theme-color" content="#151823"><title><?= escape($title.' · XFedi') ?></title><meta name="description" content="<?= escape($me?$me['name'].' on XFedi':'XFedi · Your place in the Fediverse') ?>"><link rel="stylesheet" href="<?= siteOrigin() ?>/assets/theme.css?v=<?= APP_VERSION ?>"><script defer src="<?= siteOrigin() ?>/assets/ui.js?v=<?= APP_VERSION ?>"></script><link rel="alternate" type="application/rss+xml" title="Public posts RSS" href="<?= siteOrigin() ?>/feed.rss"><link rel="sitemap" type="application/xml" href="<?= siteOrigin() ?>/sitemap.xml"><?php if ($me): ?><link rel="alternate" type="application/activity+json" href="<?= escape($page==='post'?noteUrl((int)($_GET['id']??0)):actorUrl()) ?>"><?php endif ?><link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect rx='16' width='64' height='64' fill='%237566ee'/%3E%3Ctext x='32' y='45' text-anchor='middle' font-family='sans-serif' font-weight='bold' font-size='40' fill='white'%3EX%3C/text%3E%3C/svg%3E"></head><body>
<a class="skip" href="#main">Skip to content</a>
<header class="topbar"><a class="brand" href="<?= siteOrigin() ?>"><span class="brand-mark">X</span> XFedi<span class="brand-caption">Your place in the Fediverse</span></a><div class="top-actions"><?php if ($auth): ?><a href="<?= escape(localUrl('page=alerts')) ?>" class="btn">Notifications<?= $stats['unread']?' · '.$stats['unread']:'' ?></a><?php formStart('logout'); ?><button class="btn" type="submit">Sign out</button></form><?php elseif ($me): ?><a class="btn primary signin" href="<?= escape(localUrl('page=login')) ?>">Sign In</a><?php endif ?></div></header>
<?php if (!$me || ($page==='login' && !$auth)): ?>
<main class="auth-layout" id="main"><section class="auth-intro"><span class="eyebrow">INDEPENDENT. CONNECTED. YOURS.</span><h1>A small home.<br>A wider world.</h1><p>Share ideas, find your people, and join the conversation across the Fediverse.</p><div class="orbit" aria-hidden="true">✦<span>✧</span></div></section><section class="panel auth-card"><span class="eyebrow">WELCOME TO XFEDI</span><h2><?= $me?'Sign in':'Create your account' ?></h2><p class="muted"><?= $me?'Make yourself at home.':'One personal account. Your own corner of the network.' ?></p>
<?php if ($error): ?><p class="notice error" role="alert"><?= escape($error) ?></p><?php endif ?>
<form method="post"><?php hidden('_csrf',csrf()); hidden('action',$me?'login':'setup'); ?>
<label for="username">Username</label><input id="username" name="username" autocomplete="username" required pattern="[a-z][a-z0-9_]{2,29}" maxlength="30" value="<?= escape($me['username']??'') ?>">
<label for="password">Password</label><input id="password" type="password" name="password" autocomplete="<?= $me?'current-password':'new-password' ?>" <?= $me?'':'minlength="12"' ?> required>
<?php if (!$me): ?><label for="setup_token">Installation setup token</label><input id="setup_token" type="password" name="setup_token" autocomplete="off" required><p class="hint">Find XFEDI_SETUP_TOKEN in your private .env file. Use at least 12 characters for your password. Your username and federation address will be permanent.</p><?php endif ?>
<button class="btn primary full" type="submit"><?= $me?'Sign In →':'Create account →' ?></button></form><?php if ($me): ?><a class="subtle-link" href="<?= siteOrigin() ?>">← Back to public posts</a><?php endif ?></section></main>
<?php else: ?>
<div class="shell"><aside class="left-column"><nav class="primary-nav" aria-label="Main navigation"><?php foreach ($navigation as $slug=>[$icon,$label]): if (!$auth && !in_array($slug,['home','profile','federated'],true)) continue; ?><a class="nav-item <?= $page===$slug?'active':'' ?>" href="<?= escape(localUrl($slug==='home'?'':'page='.$slug)) ?>" <?= $page===$slug?'aria-current="page"':'' ?>><span aria-hidden="true"><?= $icon ?></span><?= escape($label) ?><?php if ($slug==='alerts' && $stats['unread']): ?><b class="badge"><?= $stats['unread'] ?></b><?php endif ?></a><?php endforeach ?></nav>
<div class="instance-card"><span class="online-dot"></span> A personal, connected space<p><?= escape(hostName()) ?></p><a href="<?= siteOrigin() ?>/feed.rss">RSS feed ↗</a><a href="<?= siteOrigin() ?>/sitemap.xml">Sitemap ↗</a></div><a class="identity-chip" href="<?= escape(localUrl('page=profile')) ?>"><?= pillAvatar(['name'=>$me['name'],'avatar'=>$me['avatar']?profileMediaUrl('avatar'):''],36) ?><span><strong><?= escape($me['name']) ?></strong><small>@<?= escape($me['username']) ?></small></span></a></aside>
<main class="main-column" id="main"><div class="page-heading"><div><span class="eyebrow"><?= $page==='home'?'YOUR CORNER OF THE NETWORK':'XFEDI' ?></span><h1><?= escape($title) ?></h1></div><span class="live-label"><span class="online-dot"></span> ActivityPub</span></div>
<?php if ($flash): ?><p class="notice success" role="status"><?= escape($flash) ?></p><?php endif ?><?php if ($error): ?><p class="notice error" role="alert"><?= escape($error) ?></p><?php endif ?>
<?php if (in_array($page,['home','local','federated'],true)): ?>
<?php if ($auth && $page==='home'): composer('','',$draftContent); elseif (!$auth): ?><div class="panel welcome"><span class="eyebrow">HELLO, FEDIVERSE</span><h2>Good conversations travel.</h2><p>Public updates from <?= escape($me['name']) ?> and this corner of the network.</p><a class="btn primary" href="<?= escape(localUrl('page=login')) ?>">Sign In to your account</a></div><?php endif ?>
<nav class="feed-tabs" aria-label="Timeline"><a class="<?= $page==='home'?'active':'' ?>" href="<?= escape(localUrl()) ?>">Home</a><a class="<?= $page==='local'?'active':'' ?>" href="<?= escape(localUrl('page=local')) ?>">Local</a><a class="<?= $page==='federated'?'active':'' ?>" href="<?= escape(localUrl('page=federated')) ?>">Federated</a><?php if ($auth): ?><a href="<?= escape(localUrl('page=instances')) ?>">Manage instances</a><?php endif ?></nav>
<?php if ($page==='federated'): ?><p class="section-description">Public posts known to this server, including selected instances.<?php if ($auth): ?> <?php formStart('refresh_instances','inline-form'); ?><button class="text-button" type="submit">Refresh feeds</button></form><?php endif ?></p><?php endif ?>
<div class="timeline panel"><?php renderEntries($page,stringField($_GET['server']??'')); ?></div>
<?php elseif (in_array($page,['bookmarks','boosts','tag'],true)): ?>
<p class="section-description"><?= $page==='bookmarks'?'Your bookmarks are private. Saving a post sends no notification.':($page==='boosts'?'Posts you have shared with your followers.':'Public posts with this hashtag.') ?></p><section class="timeline panel"><?php renderEntries($page,stringField($_GET['tag']??'')); ?></section>
<?php elseif ($page==='profile' || $page==='remote'): $isRemote=$page==='remote'; $a=$isRemote?queryOne('SELECT * FROM remote_actors WHERE id=?',[stringField($_GET['actor']??'')]):$me; ?>
<?php if (!$a): http_response_code(404); emptyState('Account not found'); else: if ($isRemote && (int)$a['fetched_at']<time()-43200) queueEnrichment('actor',$a['id']); ?>
<section class="panel profile"><div class="profile-cover"><?php if ($a['cover']??''): ?><img src="<?= escape($isRemote?$a['cover']:profileMediaUrl('cover')) ?>" referrerpolicy="no-referrer" alt="Profile banner"><?php else: ?><span aria-hidden="true">✦</span><?php endif ?></div><div class="profile-body"><div class="profile-top"><?= pillAvatar(['name'=>$a['name'],'avatar'=>$isRemote?$a['avatar']:($a['avatar']?profileMediaUrl('avatar'):'')],80) ?><?php if ($auth && !$isRemote): ?><a class="btn" href="<?= escape(localUrl('page=settings')) ?>">Edit profile</a><?php elseif ($auth): $f=queryOne('SELECT * FROM follows WHERE actor_id=?',[$a['id']]); formStart($f?'unfollow':'follow'); hidden($f?'actor_id':'handle',$a['id']); ?><button class="btn primary" type="submit"><?= $f?'Unfollow · '.escape($f['status']):'Follow' ?></button></form><?php endif ?></div><h2><?= escape($a['name']?:$a['username']) ?></h2><p class="handle"><?= escape($isRemote?$a['handle']:localHandle()) ?></p><p class="bio"><?= visibleText($a['bio']) ?></p>
<?php if (!$isRemote && $a['website']): $websiteState=websiteStatus($a); ?><a class="website <?= $websiteState['confirmed']?'verified':'' ?>" href="<?= escape($a['website']) ?>" rel="me noopener noreferrer" target="_blank"><span><?= $websiteState['confirmed']?'✓':'↗' ?> <?= escape($websiteState['label']) ?></span><strong><?= escape($a['website']) ?></strong><?php if ($a['website_checked_at']): ?><small>Checked <?= escape(displayTime((int)$a['website_checked_at'])) ?></small><?php endif ?></a><?php endif ?>
<?php if (!$isRemote): ?><div class="profile-stats"><span><b><?= $stats['posts'] ?></b> posts</span><a href="<?= escape(localUrl('page=following')) ?>"><b><?= $stats['following'] ?></b> following</a><a href="<?= escape(localUrl('page=followers')) ?>"><b><?= $stats['followers'] ?></b> followers</a><span>Joined <?= escape(displayTime((int)$a['created_at'],'M Y')) ?></span></div><?php else: ?><a class="subtle-link" href="<?= escape($a['id']) ?>" target="_blank" rel="noopener noreferrer">Open remote profile ↗</a><?php if ($auth): formStart('refresh_actor');hidden('actor_id',$a['id']); ?><button class="text-button" type="submit">Refresh profile and header</button></form><?php endif ?><?php endif ?></div><div class="tabs"><span class="active">Posts</span><?php if (!$isRemote): ?><a href="<?= siteOrigin().'/@'.rawurlencode($me['username']) ?>.rss">RSS feed ↗</a><?php endif ?></div><?php renderEntries($isRemote?'remote':'profile',$isRemote?$a['id']:''); ?></section><?php endif ?>
<?php elseif ($page==='post' || $page==='remote_post'): $id=$page==='post'?noteUrl((int)($_GET['id']??0)):stringField($_GET['id']??''); $p=knownPost($id); ?>
<?php if (!$p): emptyState('Post unavailable','It may have been deleted or is not in the local cache.'); else: ?><section class="timeline panel"><?php postCard($p,(bool)$p['_remote']); ?><p class="timestamp"><?= escape(displayTime((int)$p['created_at'])) ?><?= $p['updated_at']>$p['created_at']?' · Edited '.escape(displayTime((int)$p['updated_at'])):'' ?></p>
<?php if ($auth && !$p['_remote']): ?><details class="edit-post"><summary>Edit or delete this post</summary><?php formStart('edit'); hidden('id',$p['id']); ?><label for="editContent">Post text</label><textarea id="editContent" name="content" maxlength="500" rows="4" required><?= escape($p['content']) ?></textarea><button class="btn" type="submit">Save changes</button></form><?php formStart('delete','confirm-delete'); hidden('id',$p['id']); ?><button class="btn danger" type="submit">Delete post</button></form></details><?php endif ?>
<div class="section-bar">Replies</div><?php $own=queryAll('SELECT * FROM posts WHERE deleted=0 AND in_reply_to=? ORDER BY created_at LIMIT 100',[$id]); $others=queryAll('SELECT * FROM remote_notes WHERE deleted=0 AND in_reply_to=? ORDER BY created_at LIMIT 100',[$id]); $replies=[]; foreach ($own as $r) { $r['_remote']=false; $replies[]=$r; } foreach ($others as $r) { $r['_remote']=true; $replies[]=$r; } usort($replies,fn($x,$y)=>$x['created_at']<=>$y['created_at']); foreach ($replies as $r) postCard($r,$r['_remote']); if (!$replies) echo '<p class="section-description">No replies here yet.</p>'; ?></section><?php if ($auth) composer($id); endif ?>
<?php elseif ($page==='quote'): $quote=stringField($_GET['id']??''); if (!knownPost($quote)) emptyState('Post unavailable'); else composer('',$quote); ?>
<?php elseif ($page==='instances'): ?>
<section class="panel padded"><h2>Follow public instance feeds</h2><p class="muted">See public local posts from a server without following every account. Servers that require login for public timelines cannot be imported.</p><?php formStart('subscribe_instance'); ?><label for="instanceDomain">Server hostname</label><div class="input-row"><input id="instanceDomain" name="domain" placeholder="mastodon.social" required maxlength="253" autocomplete="off"><button class="btn primary" type="submit">Add instance</button></div></form></section>
<section class="panel"><?php $sources=queryAll('SELECT * FROM instance_subscriptions ORDER BY domain');if (!$sources) emptyState('Choose a server','Add a public instance above. Its posts appear in the Federated timeline.');foreach ($sources as $source): ?><article class="instance-source"><div><a class="author" href="<?= escape(localUrl('page=federated&server='.rawurlencode($source['domain']))) ?>"><?= escape($source['domain']) ?></a><p class="hint"><?= $source['last_checked_at']?'Last checked '.escape(displayTime((int)$source['last_checked_at'])):'First refresh queued' ?></p><?php if ($source['last_error']): ?><p class="hint source-error"><?= escape($source['last_error']) ?>. This server may disable public previews.</p><?php endif ?></div><?php formStart('unsubscribe_instance');hidden('domain',$source['domain']); ?><button class="btn" type="submit">Remove</button></form></article><?php endforeach ?></section>
<?php elseif ($page==='find'): ?>
<section class="panel padded"><h2>Find your people</h2><p class="muted">Enter a full Fediverse handle, a profile URL, or a public post URL.</p><?php formStart('lookup'); ?><label for="searchQuery">Account or post</label><div class="input-row"><input id="searchQuery" name="query" placeholder="@alice@mastodon.social" value="<?= escape(stringField($_GET['handle']??'')) ?>" required maxlength="2048"><button class="btn primary" type="submit">Search →</button></div></form></section>
<?php elseif ($page==='following' || $page==='followers'): ?>
<section class="panel"><?php $items=$page==='following'?queryAll('SELECT a.*,f.status FROM follows f JOIN remote_actors a ON a.id=f.actor_id ORDER BY f.created_at DESC'):queryAll('SELECT a.* FROM followers f JOIN remote_actors a ON a.id=f.actor_id ORDER BY f.created_at DESC'); if (!$items) emptyState('No accounts yet','Connections start with a conversation.'); foreach ($items as $a): ?><div class="account-row"><?= pillAvatar($a,44) ?><div><a class="author" href="<?= escape(localUrl('page=remote&actor='.rawurlencode($a['id']))) ?>"><?= escape($a['name']?:$a['username']) ?></a><span class="handle"><?= escape($a['handle']) ?></span><?php if (isset($a['status'])): ?><small class="muted"><?= escape(ucfirst($a['status'])) ?></small><?php endif ?></div><?php if ($auth && $page==='following'): formStart('unfollow'); hidden('actor_id',$a['id']); ?><button class="btn" type="submit">Unfollow</button></form><?php endif ?></div><?php endforeach ?></section>
<?php elseif ($page==='alerts'): ?>
<section class="panel"><div class="tabs"><a class="<?= empty($_GET['unread'])?'active':'' ?>" href="<?= escape(localUrl('page=alerts')) ?>">All</a><a class="<?= !empty($_GET['unread'])?'active':'' ?>" href="<?= escape(localUrl('page=alerts&unread=1')) ?>">Unread · <?= $stats['unread'] ?></a><?php formStart('notifications_read'); hidden('through_id',(int)(queryOne('SELECT MAX(id) n FROM notifications')['n']??0)); ?><button class="text-button" type="submit">Mark all read</button></form></div>
<?php $n=max(1,(int)($_GET['p']??1)); $ns=queryAll('SELECT * FROM notifications'.(!empty($_GET['unread'])?' WHERE is_read=0':'').' ORDER BY id DESC LIMIT 31 OFFSET '.(($n-1)*30)); if (!$ns) emptyState('You’re all caught up','Follows, mentions, replies, favourites, boosts, and quotes will appear here.'); foreach (array_slice($ns,0,30) as $notice): $verbs=['follow'=>'followed you','reply'=>'replied to your post','mention'=>'mentioned you','like'=>'favourited your post','boost'=>'boosted your post','quote'=>'linked to your post in a quote','accept'=>'accepted your follow request','reject'=>'declined your follow request']; ?><article class="notification <?= $notice['is_read']?'':'unread' ?>"><span class="notification-mark" aria-hidden="true"><?= ['like'=>'♥','boost'=>'↻','reply'=>'↩','mention'=>'@','follow'=>'◉'][$notice['kind']]??'✦' ?></span><div><a class="author" href="<?= escape(localUrl('page=remote&actor='.rawurlencode($notice['actor_id']))) ?>"><?= escape(actorLabel($notice['actor_id'])) ?></a><p><?= escape($verbs[$notice['kind']]??$notice['kind']) ?>.</p><time class="muted" datetime="<?= nowIso((int)$notice['created_at']) ?>"><?= escape(displayTime((int)$notice['created_at'])) ?></time><?php if ($notice['object_id']): ?><a class="subtle-link" href="<?= escape(postUiUrl($notice['object_id'])) ?>">View conversation →</a><?php endif ?><?php formStart($notice['is_read']?'notification_unread':'notification_read'); hidden('id',$notice['id']); ?><button class="text-button" type="submit"><?= $notice['is_read']?'Mark unread':'Mark read' ?></button></form></div><?php if (!$notice['is_read']): ?><span class="unread-dot" title="Unread"></span><?php endif ?></article><?php endforeach ?>
<?php if ($n>1 || count($ns)>30): ?><nav class="pagination"><?php if ($n>1): ?><a href="<?= escape(localUrl('page=alerts&p='.($n-1).(!empty($_GET['unread'])?'&unread=1':''))) ?>">← Newer</a><?php endif ?><?php if (count($ns)>30): ?><a href="<?= escape(localUrl('page=alerts&p='.($n+1).(!empty($_GET['unread'])?'&unread=1':''))) ?>">Older →</a><?php endif ?></nav><?php endif ?></section>
<?php elseif ($page==='settings'): ?>
<section class="panel padded"><h2>Make it yours</h2><p class="muted">Your public profile and display preferences.</p><form method="post" enctype="multipart/form-data"><?php hidden('_csrf',csrf()); hidden('action','profile'); ?><label for="displayName">Display name</label><input id="displayName" name="name" value="<?= escape($me['name']) ?>" required maxlength="100"><label for="bio">Biography</label><textarea id="bio" name="bio" rows="4" maxlength="500"><?= escape($me['bio']) ?></textarea><label for="website">Website</label><input id="website" name="website" type="url" placeholder="https://your.website" value="<?= escape($me['website']) ?>"><p class="hint">Checked automatically after saving and every day. Existing profile links and a shared domain can confirm the association. No special HTML snippet is required. HTTPS availability alone does not prove ownership.</p>
<?php if ($me['website_checked_at']): $websiteState=websiteStatus($me); ?><p class="website-check-result <?= $websiteState['confirmed']?'verified-text':'' ?>"><?= escape($websiteState['label']) ?> · <?= escape(displayTime((int)$me['website_checked_at'])) ?></p><?php if (!$websiteState['confirmed'] && $me['website_error']): ?><p class="hint"><?= escape($me['website_error']) ?></p><?php endif ?><?php endif ?>
<label for="timezone">Timezone</label><select id="timezone" name="timezone"><?php foreach (DateTimeZone::listIdentifiers() as $tz): ?><option value="<?= escape($tz) ?>" <?= $tz===$me['timezone']?'selected':'' ?>><?= escape(str_replace('_',' ',$tz)) ?></option><?php endforeach ?></select><p class="hint">Times follow your timezone when signed in. Public visitors see UTC.</p><label for="avatarUpload">Profile photo · up to 5 MB</label><input id="avatarUpload" type="file" name="avatar" accept="image/png,image/jpeg,image/gif,image/webp"><label for="coverUpload">Header image · up to 5 MB</label><input id="coverUpload" type="file" name="cover" accept="image/png,image/jpeg,image/gif,image/webp"><button class="btn primary" type="submit">Save profile</button></form>
<?php if ($me['website']): formStart('verify_website'); ?><button class="text-button" type="submit">Recheck website verification</button></form><?php endif ?></section>
<section class="panel padded"><h2>Timeline preferences</h2><p class="muted">Manage public instance feeds and posts you have hidden locally.</p><a class="btn" href="<?= escape(localUrl('page=instances')) ?>">Manage instances</a><?php formStart('unhide_posts'); ?><button class="text-button" type="submit">Show hidden remote posts again</button></form></section>
<section class="panel padded"><h2>Account security</h2><p class="hint">Your permanent address is <?= escape(localHandle()) ?>.</p><?php formStart('password'); ?><label for="oldPassword">Current password</label><input id="oldPassword" type="password" name="old_password" autocomplete="current-password" required><label for="newPassword">New password · at least 12 characters</label><input id="newPassword" type="password" name="new_password" autocomplete="new-password" minlength="12" required><button class="btn" type="submit">Change password</button></form></section>
<?php elseif ($page==='queue'): ?>
<section class="panel padded"><h2>Federation delivery</h2><p class="muted"><?= $stats['queued'] ?> outgoing deliveries pending. The background worker handles delivery, incoming activities, and website checks.</p><div class="button-row"><?php formStart('worker'); ?><button class="btn primary" type="submit">Process now</button></form><?php formStart('retry_failed'); ?><button class="btn" type="submit">Retry failed jobs</button></form><?php formStart('refresh'); ?><button class="btn" type="submit">Refresh followed accounts</button></form></div></section>
<?php foreach (['deliveries'=>'Outgoing deliveries','inbox_jobs'=>'Incoming activities'] as $table=>$label): ?><section class="panel"><div class="section-bar"><?= $label ?></div><div class="table-scroll"><table><thead><tr><th>Destination / actor</th><th>Status</th><th>Attempts</th><th>Last error</th></tr></thead><tbody><?php foreach (queryAll('SELECT * FROM '.$table.' ORDER BY '.($table==='deliveries'?'id':'created_at').' DESC LIMIT 80') as $job): ?><tr><td><?= escape($job['destination']??$job['actor_id']) ?></td><td><?= escape($job['status']) ?></td><td><?= (int)$job['attempts'] ?></td><td><?= escape($job['last_error']) ?></td></tr><?php endforeach ?></tbody></table></div></section><?php endforeach ?>
<?php endif ?>
</main><aside class="right-column"><section class="panel padded"><span class="eyebrow">A NETWORK WITHOUT WALLS</span><h2>Stay curious.</h2><p class="muted">Follow people across independent servers. Your conversations belong to a bigger world.</p><?php if ($auth): ?><a class="btn full" href="<?= escape(localUrl('page=find')) ?>">Find an account →</a><?php endif ?></section><section class="network-stats"><span><b><?= $stats['posts'] ?></b> public posts</span><span><b><?= $stats['followers'] ?></b> followers</span><span><b><?= $stats['following'] ?></b> following</span></section><p class="footer-note">XFedi <?= APP_VERSION ?><br>Personal by design. Federated by nature.<br><a href="<?= escape(actorUrl()) ?>">ActivityPub</a> · <a href="<?= siteOrigin() ?>/feed.rss">RSS</a></p></aside></div>
<nav class="mobile-nav" aria-label="Mobile navigation"><?php foreach (($auth?['home','federated','alerts','bookmarks','settings']:['home','profile']) as $slug): [$icon,$label]=$navigation[$slug]; ?><a href="<?= escape(localUrl($slug==='home'?'':'page='.$slug)) ?>" class="<?= $slug===$page?'active':'' ?>"><span aria-hidden="true"><?= $icon ?></span><?= escape($slug==='alerts'?'Alerts':$label) ?><?= $slug==='alerts' && $stats['unread']?' · '.$stats['unread']:'' ?></a><?php endforeach ?></nav>
<?php endif ?></body></html>
