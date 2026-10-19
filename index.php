<?php
/** XFedi public front controller. Sensitive data never belongs in this directory. */
declare(strict_types=1);
require __DIR__.'/app/core.php';
require __DIR__.'/app/features.php';
if (PHP_SAPI==='cli') {
    if (($argv[1]??'')==='doctor') {
        $extensions=[]; foreach (['pdo_sqlite','curl','openssl','mbstring','fileinfo','dom'] as $e) $extensions[$e]=extension_loaded($e);
        $ok=readyConfig() && !in_array(false,$extensions,true);
        echo jenc(['php'=>PHP_VERSION,'url'=>cfgUrl(),'ready'=>$ok,'extensions'=>$extensions])."\n"; exit($ok?0:1);
    }
    if (!in_array($argv[1]??'',['worker','migrate'],true)) { fwrite(STDERR,"Usage: php index.php doctor|migrate|worker [refresh]\n"); exit(2); }
    try {
        if (!readyConfig()) throw new RuntimeException('Set XFEDI_URL to the permanent public HTTPS script URL.');
        initDb();
        echo jenc(($argv[1]==='migrate')?['schema'=>3,'identity'=>actorUrl()]:runBackground(($argv[2]??'')==='refresh'))."\n";
    } catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
    exit;
}
require __DIR__.'/app/routes.php';
require __DIR__.'/app/actions.php';
require __DIR__.'/app/view_helpers.php';
require __DIR__.'/app/views.php';
