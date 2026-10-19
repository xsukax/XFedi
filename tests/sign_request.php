<?php
// Test-only signer, never routed by the production web server.
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';require dirname(__DIR__).'/app/features.php';initDb();
$actor='https://remote.example/users/bob';$u=user();
executeSql('INSERT OR REPLACE INTO remote_actors(id,username,handle,name,inbox,key_id,public_key,fetched_at) VALUES(?,?,?,?,?,?,?,?)',[$actor,'bob','@bob@remote.example','Bob','https://remote.example/inbox',$actor.'/main-key',$u['public_key'],time()]);
$body=jenc(['@context'=>AS_CTX,'id'=>'https://remote.example/activities/http-like','type'=>'Like','actor'=>$actor,'object'=>noteUrl(1)]);
$target='/xsukax_fediverse.php?ap=inbox';$date=gmdate('D, d M Y H:i:s').' GMT';$digest='SHA-256='.base64_encode(hash('sha256',$body,true));
$base='(request-target): post '.$target."\nhost: social.example\ndate: ".$date."\ndigest: ".$digest;openssl_sign($base,$sig,$u['private_key'],OPENSSL_ALGO_SHA256);
echo jenc(['body'=>$body,'target'=>$target,'headers'=>['Host'=>'social.example','Date'=>$date,'Digest'=>$digest,'Content-Type'=>'application/activity+json','Signature'=>'keyId="'.$actor.'/main-key",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="'.base64_encode($sig).'"']]);
