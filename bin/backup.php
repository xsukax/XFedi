<?php
/** Stream a consistent SQLite snapshot and every immutable uploaded image as tar.gz. */
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
if (PHP_SAPI!=='cli') exit(1);
$dir=dataDir(); $snapshot=$dir.'/.snapshot-'.bin2hex(random_bytes(12));
if (!is_file($dir.'/social.sqlite')) { fwrite(STDERR,"No database: complete account setup first.\n"); exit(1); }
mkdir($snapshot,0700);
try {
    db()->exec('VACUUM INTO '.db()->quote($snapshot.'/social.sqlite'));
    foreach (glob($dir.'/*')?:[] as $file) {
        if (preg_match('/^[0-9a-f]{32}\.(jpg|png|gif|webp)$/D',basename($file)) && !link($file,$snapshot.'/'.basename($file))) throw new RuntimeException('Cannot snapshot upload');
    }
    $args=['tar','-czf','-','-C',$snapshot,'.'];
    putenv('COPYFILE_DISABLE=1');
    $process=proc_open($args,[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot launch tar');
    fclose($pipes[0]); $code=proc_close($process);
    if ($code!==0) throw new RuntimeException('Archive creation failed');
} finally { foreach (glob($snapshot.'/*')?:[] as $file) @unlink($file); @rmdir($snapshot); }
