<?php

namespace LanguageServer\CodeRepository;

use Microsoft\PhpParser\{
    Parser,
    Node,
    Token
};

use Microsoft\PhpParser\Node\{
    SourceFileNode,
    MethodDeclaration,
    PropertyDeclaration,
    Parameter
};

use Microsoft\PhpParser\Node\Statement\{
    NamespaceDefinition,
    ClassDeclaration,
    InterfaceDeclaration,
    FunctionDeclaration
};

use function LanguageServer\pathToUri;
use function LanguageServer\uriToPath;

require_once 'vendor/autoload.php';


$repo = new Repository();
$parser = new Parser();

class Collector {
    private $repo;
    public $file;
    private $namespace;
    private $filename;
    private $src;

    private $currentClass;
    private $currentInterface;
    private $currentFunction;

    private $scope = [];

    public function __construct($repo, $filename, $src) {
        $this->repo = $repo;
        $this->filename = $filename;
        $this->src = $src;
    }

    public function iterate($node) {
        $this->visit($node);
        if ($node instanceof Node) {
            foreach($node::CHILD_NAMES as $name) {
                $child = $node->$name;
                if (\is_array($child)) {
                    foreach($child as $actualChild) {
                        if ($actualChild !== null) {
                            $this->iterate($actualChild);
                        }
                    }
                }
                else if ($child !== null) {
                    $this->iterate($child);
                }
            }
        }
        $this->leave($node);
    }

    private function visit($node) {
        if ($node instanceof SourceFileNode) {
            $this->file = new File($this->filename, $node->fileContents);
            $node->toRepo = $this->file;
        }
        else if ($node instanceof NamespaceDefinition) {
            $this->namespace = $this->repo->namespace($node->name ? $node->name->getText() : '');
            $node->toRepo = $this->namespace;
        }
        else if ($node instanceof ClassDeclaration) {
            $name = $node->name->getText($this->src);
            if ($name) {
                $this->currentClass = new Class_($name);
                $node->toRepo = $this->currentClass;
                $this->file->addClass($this->currentClass);
            }
        }
        else if ($node instanceof FunctionDeclaration) {
            $name = $node->name->getText($this->src);
            if ($name) {
                $this->currentFunction = new Function_($name);
                $this->file->addFunction($this->currentFunction);
                $node->toRepo = $this->currentFunction;
            }
        }
        else if ($node instanceof MethodDeclaration) {
            $name = $node->name->getText($this->src);
            if ($name && $this->currentClass) {
                $this->currentFunction = new Function_($name);
                $this->currentClass->addFunction($this->currentFunction);
                $node->toRepo = $this->currentFunction;
            }
        }
        else if ($node instanceof \Microsoft\PhpParser\Node\Expression\Variable) {
            $name = $node->getName();
            if ($name) {
                if (!\array_key_exists($name, $this->scope)) {
                    $var = new Variable($name);
                    $this->scope[$name] = $var;
                    $target = $this->currentFunction ?? $this->currentClass ?? $this->file;
                    $target->addVariable($var);
                    $node->toRepo = $var;
                }
            }
        }
        else if ($node instanceof Parameter) {
            $name = $node->getName();
            if ($this->currentFunction && $name) {
                if (!\array_key_exists($name, $this->scope)) {
                    $var = new Variable($name);
                    $this->scope[$name] = $var;
                    $this->currentFunction->addVariable($var);
                    $node->toRepo = $var;
                }
            }
        }
    }

    private function leave($node) {
        if ($node instanceof SourceFileNode) {
            if ($this->namespace === null) {
                $this->namespace = $this->repo->namespace('');
            }
            $this->namespace->addFile($this->file);
        }
        else if ($node instanceof ClassDeclaration) {
            $this->currentClass = null;
        }
        else if ($node instanceof FunctionDeclaration) {
            $this->currentFunction = null;
            $this->scope = [];
        }
        else if ($node instanceof MethodDeclaration) {
            $this->currentFunction = null;
            $this->scope = [];
        }
    }
}

$start = \microtime(true);

$rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator('./src'));
$files = array();
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
    $collector = new Collector($repo, $uri, $src);
    $collector->iterate($ast);
    $parseend = microtime(true);
    $collector->file->parseTime = $parseend-$parsestart;
}

$end = \microtime(true);

echo (int)($end-$start).' seconds; '.(int)(\memory_get_usage()/(1024))." kB allocated\n";
