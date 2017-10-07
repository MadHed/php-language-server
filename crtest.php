<?php

namespace LanguageServer\CodeDB;

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
        return (int)($v*1000000).'μs';
    }
    else if ($v < 1) {
        return (int)($v*1000).'ms';
    }
    else if ($v < 60) {
        return (int)($v).'s';
    }
    else {
        return (int)($v/60).'min';
    }
}

$repo = new Repository();
$parser = new Parser();
$files = ['test1.php', 'test2.php'];

foreach($files as $i => $filename) {
    $uri = pathToUri($filename);
    $src = file_get_contents($filename);
    if (isset($repo->files[$uri]) && hash('sha256', $src) === $repo->files[$uri]->hash()) {
        $cached++;
        continue;
    }

    echo "Parsing $filename\n";

    $parsestart = microtime(true);
    $ast = $parser->parseSourceFile($src, $uri);
    $collector = new Collector($repo, $uri, $ast);
    $collector->iterate($ast);
    $parseend = microtime(true);
    $collector->file->parseTime = $parseend-$parsestart;
}

$parser = null;
$ast = null;
$collector = null;

$repo->resolveReferences();

print_r(array_keys($repo->fqnMap));
print_r(array_keys($repo->references));
