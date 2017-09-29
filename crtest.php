<?php

namespace LanguageServer\CodeRepository;

use Microsoft\PhpParser\Parser;
use function LanguageServer\pathToUri;
use function LanguageServer\uriToPath;

require_once 'vendor/autoload.php';


$repo = new Repository();
$parser = new Parser();

$start = \microtime(true);

$files = array();

$rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator('./vendor/jetbrains/phpstorm-stubs'));
foreach ($rii as $file) {
    $filename = $file->getRealPath();
    if ($file->isFile() && $file->isReadable() && strtolower($file->getExtension()) === 'php'){
        $files[] = $filename;
    }
}

$rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator('./'));
foreach ($rii as $file) {
    $filename = $file->getRealPath();
    if ($file->isFile() && $file->isReadable() && strtolower($file->getExtension()) === 'php'){
        $files[] = $filename;
    }
}

foreach($files as $i => $filename) {
    echo (int)($i*100/count($files))."%\r";
    flush();
    $parsestart = microtime(true);
    $src = file_get_contents($filename);
    $uri = pathToUri($filename);
    $ast = $parser->parseSourceFile($src, $uri);
    $collector = new Collector($repo, $uri, $ast);
    $collector->iterate($ast);
    $parseend = microtime(true);
    $collector->file->parseTime = $parseend-$parsestart;
}


$resolved = $unresolved = 0;
foreach($repo->references as $ref) {
    if (\is_string($ref->target)) {
        echo $ref->target.' -> ';
        $cls = $repo
            ->namespaces()
            ->files()
            ->classes()
            ->find(fqnEquals($ref->target));

        if (!$cls) {
            $cls = $repo
            ->namespaces()
            ->files()
            ->interfaces()
            ->find(fqnEquals($ref->target));
        }

        if (!$cls) {
            $cls = $repo
            ->namespaces()
            ->files()
            ->functions()
            ->find(fqnEquals($ref->target));
        }

        if ($cls) {
            echo 'OK';
        }
        else {
            echo 'NOT FOUND @'.$ref->file->getName().':'.$ref->range->start->line.':'.$ref->range->start->character;
        }

        echo "\n";
        $unresolved++;
    }
    else {
        $resolved++;
    }
}

$end = \microtime(true);

echo count($repo->references)." references. Resolved: $resolved, Unresolved: $unresolved\n";

echo \count($files).' files in '.(int)($end-$start).' seconds; '.(int)(\memory_get_usage()/(1024))." kB allocated\n";


