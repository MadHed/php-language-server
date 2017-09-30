<?php

namespace LanguageServer\CodeRepository;

use Microsoft\PhpParser\Parser;
use function LanguageServer\pathToUri;
use function LanguageServer\uriToPath;

require_once 'vendor/autoload.php';

function bytes($v) {
    if ($v < 1024) {
        return $v.'b';
    }
    else if ($v < 1024*1024) {
        return (int)($v/1024).'kB';
    }
    else if ($v < 1024*1024*1024) {
        return (int)($v/1024/1024).'MB';
    }
    else {
        return (int)($v/1024/1024/1024).'GB';
    }
}

function seconds($v) {
    if ($v < 0.001) {
        return (int)($v*1000000).'us';
    }
    else if ($v < 1) {
        return (int)($v*1000).'ms';
    }
    else if ($v < 60) {
        return (int)($v).'s';
    }
    else {
        return (int)($v/60).'m';
    }
}

$start = \microtime(true);

if (file_exists('phpls.cache')) {
    $usstart = microtime(true);
    $repo = @unserialize(file_get_contents('phpls.cache'));
    $usend = microtime(true);
    echo "Unserialized in ".seconds($usend-$usstart)."\n";
}

if (empty($repo)) {
    $repo = new Repository();
}

$parser = new Parser();

$files = array();

$rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator('./vendor/jetbrains/phpstorm-stubs'));
foreach ($rii as $file) {
    $filename = $file->getRealPath();
    if ($file->isFile() && $file->isReadable() && strtolower($file->getExtension()) === 'php'){
        $files[] = $filename;
    }
}

$rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator('.'));
//$rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator('/mnt/e/Projekte/magento'));
//$rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator('src'));
foreach ($rii as $file) {
    $filename = $file->getRealPath();
    if ($file->isFile() && $file->isReadable() && strtolower($file->getExtension()) === 'php'){
        $files[] = $filename;
    }
}

$cached = 0;
foreach($files as $i => $filename) {
    echo "\r".(int)($i*100/count($files))."%";
    flush();
    $uri = pathToUri($filename);
    if (isset($repo->files[$uri]) && hash_file('sha256', $filename) === $repo->files[$uri]->hash()) {
        $cached++;
        continue;
    }

    echo " Parsing $filename\n";

    $parsestart = microtime(true);
    $src = file_get_contents($filename);
    $ast = $parser->parseSourceFile($src, $uri);
    $collector = new Collector($repo, $uri, $ast);
    $collector->iterate($ast);
    $parseend = microtime(true);
    $collector->file->parseTime = $parseend-$parsestart;
}

$parser = null;
$ast = null;
$collector = null;

$refstart = microtime(true);
$resolved = $unresolved = 0;
foreach($repo->references as $ref) {
    if (\is_string($ref->target) || \is_string($ref->file)) {
        if (\is_string($ref->target) && isset($repo->fqnMap[$ref->target])) {
            $ref->target = $repo->fqnMap[$ref->target];
        }
        if (\is_string($ref->file) && isset($repo->files[$ref->file])) {
            $ref->file = $repo->files[$ref->file];
        }
        $unresolved++;
    }
    else {
        $resolved++;
    }
}
echo "\n";
$refend = microtime(true);
echo 'References resolved in '.seconds($refend-$refstart)."\n";

$end = \microtime(true);

echo count($repo->references)." references. Resolved: $resolved, Unresolved: $unresolved\n";
echo \count($files)." files in ".seconds($end-$start)."; $cached from cache; ".bytes(\memory_get_usage())." allocated\n";

$sestart = microtime(true);
file_put_contents('phpls.cache', serialize($repo));
$seend = microtime(true);

echo "Serialized in ".seconds($seend-$sestart)."\n";
echo "Memory used after serializing: ".bytes(memory_get_usage())."\n";

