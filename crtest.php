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
    $repo = @\unserialize(file_get_contents('phpls.cache'));
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
$rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator('/mnt/e/Projekte/magento'));
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
    $collector->file->parseTime = 0;//$parseend-$parsestart;
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

\ini_set('serialize_precision', 20);

$sestart = microtime(true);
//file_put_contents('phpls.cache', \serialize($repo));
$seend = microtime(true);

echo "Serialized in ".seconds($seend-$sestart)."\n";
echo "Memory used after serializing: ".bytes(memory_get_usage())."\n";






class SerializationState {
    public $id = 1;
    public $refs = [];
    public function addRef($obj) {
        $this->refs[\spl_object_hash($obj)] = $this->id;
    }
    public function getRef($obj) {
        $hash = \spl_object_hash($obj);
        if (isset($this->refs[$hash])) {
            return $this->refs[$hash];
        }
        return -1;
    }
}

function serialize($value, $state = null) {
    if ($state === null) {
        $state = new SerializationState();
    }

    if ($value === null) {
        return 'N;';
    }
    else if ($value === false) {
        return 'b:0;';
    }
    else if ($value === true) {
        return 'b:1;';
    }
    else if (\is_int($value)) {
        return "i:$value;";
    }
    else if (\is_float($value)) {
        return "d:$value;";
    }
    else if (\is_string($value)) {
        $len = \strlen($value);
        return "s:$len:\"$value\";";
    }
    else if (\is_array($value)) {
        $str = "a:".\count($value).":{";
        foreach($value as $k => $v) {
            $str .= serialize($k, $state);
            $state->id++;
            $str .= serialize($v, $state);
        }
        return $str . "}";
    }
    else if (\is_object($value)) {
        $id = $state->getRef($value);
        if ($id >= 0) {
            return "r:$id;";
        }
        else {
            $state->addRef($value);
            $cls = \get_class($value);
            $str = "O:".\strlen($cls).":\"".$cls."\":";
            $ref = new \ReflectionClass($value);
            $nref = $ref;
            do {
                $refs[] = $ref;
            } while($nref = $ref->getParentClass() && $nref != $ref && $ref = $nref);

            $numProps = 0;
            foreach($refs as $ref) {
                $props = $ref->getProperties();
                foreach($props as $prop) {
                    if (!$prop->isStatic()) $numProps++;
                }
            }

            $str .= "$numProps:{";

            $visitedProps = [];
            foreach($refs as $ref) {
                $className = $ref->getName();
                $props = $ref->getProperties();
                foreach($props as $prop) {
                    if ($prop->isStatic()) continue;
                    $propName = $prop->getName();
                    $prop->setAccessible(true);
                    $state->id++;
                    if ($prop->isPrivate()) {
                        $str .= serialize("\0$className\0$propName", $state);
                        $str .= serialize($prop->getValue($value), $state);
                    }
                    else if (!isset($visitedProps[$propName])) {
                        if ($prop->isProtected()) {
                            $str .= serialize("\0*\0$propName", $state);
                        }
                        else {
                            $str .= serialize($propName, $state);
                        }
                        $str .= serialize($prop->getValue($value), $state);
                        $visitedProps[$propName] = 1;
                    }
                }
            }
            $str .= "}";
            return $str;
        }
    }
    else {
        return "N;";
    }
}

function unserialize($string) {
}

$start = microtime(true);
file_put_contents('phpls.cache', serialize($repo));
$end = microtime(true);
echo "Custom serialization: ".seconds($end-$start)."; ".bytes(\memory_get_usage())."\n";
