<?php

namespace LanguageServer\CodeRepository;

use Microsoft\PhpParser\{
    PositionUtilities,
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
        $this->file = new File($filename, $src->fileContents);
        $repo->files[$filename] = $this->file;
    }

    private function expandName($name) {
        if (\strlen($name) === 0 || $name[0] === '\\') {
            return $name;
        }
        else if ($this->namespace === null) {
            return '\\'.$name;
        }
        else {
            return $this->namespace->fqn().'\\'.$name;
        }
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

    private function getNamespace() {
        if ($this->namespace === null) {
            if (!isset($this->file->namespaces[''])) {
                $globalNspace = new Namespace_('');
                $globalNspace->parent = $this->file;
                $this->file->namespaces[''] = $globalNspace;
            }
            $this->namespace = $this->file->namespaces[''];
        }
        return $this->namespace;
    }

    private function visit($node) {
        if ($node instanceof NamespaceDefinition) {
            $name = $node->name ? $node->name->getText() : '';
            if (isset($this->file->namespaces[$name])) {
                $this->namespace = $this->file->namespaces[$name];
            }
            else {
                $this->namespace = new Namespace_($name);
                $this->namespace->parent = $this->file;
                $this->file->namespaces[$name] = $this->namespace;
            }
        }
        else if ($node instanceof ClassDeclaration) {
            $name = $node->name->getText($this->src->fileContents);
            if ($name) {
                $this->currentClass = new Class_($name);
                $this->getNamespace()->addClass($this->currentClass);

                if ($node->classBaseClause && $node->classBaseClause->baseClass) {
                    $className = $node->classBaseClause->baseClass->getText();
                    $start = $node->classBaseClause->baseClass->getStart();
                    $length = $node->classBaseClause->baseClass->getEndPosition() - $start;
                    $this->repo->references[] = new Reference(
                        $this->file,
                        PositionUtilities::getRangeFromPositionInFile($start, $length, $this->src),
                        $this->expandName($className)
                    );
                }
                if ($node->classInterfaceClause && $node->classInterfaceClause->interfaceNameList) {
                    foreach($node->classInterfaceClause->interfaceNameList->children as $interfaceName) {
                        if ($interfaceName instanceof Node) {
                            $start = $interfaceName->getStart();
                            $length = $interfaceName->getEndPosition() - $start;
                            $interfaceName = $interfaceName->getText();
                            $this->repo->references[] = new Reference(
                                $this->file,
                                PositionUtilities::getRangeFromPositionInFile($start, $length, $this->src),
                                $this->expandName($interfaceName)
                            );
                        }
                    }
                }
            }
        }
        else if ($node instanceof InterfaceDeclaration) {
            $name = $node->name->getText($this->src->fileContents);
            if ($name) {
                $this->currentInterface = new Interface_($name);
                $this->getNamespace()->addInterface($this->currentInterface);

                if ($node->interfaceBaseClause && $node->interfaceBaseClause->interfaceNameList) {
                    foreach($node->interfaceBaseClause->interfaceNameList->children as $interfaceName) {
                        if ($interfaceName instanceof Node) {
                            $start = $interfaceName->getStart();
                            $length = $interfaceName->getEndPosition() - $start;
                            $interfaceName = $interfaceName->getText();
                            $this->repo->references[] = new Reference(
                                $this->file,
                                PositionUtilities::getRangeFromPositionInFile($start, $length, $this->src),
                                $this->expandName($interfaceName)
                            );
                        }
                    }
                }
            }
        }
        else if ($node instanceof FunctionDeclaration) {
            $name = $node->name->getText($this->src->fileContents);
            if ($name) {
                $this->currentFunction = new Function_($name);
                $this->getNamespace()->addFunction($this->currentFunction);
            }
        }
        else if ($node instanceof MethodDeclaration) {
            $name = $node->name->getText($this->src);
            if ($name && $this->currentClass) {
                $this->currentFunction = new Function_($name);
                $this->currentClass->addFunction($this->currentFunction);
            }
        }
        else if ($node instanceof \Microsoft\PhpParser\Node\Expression\Variable) {
            $name = $node->getName();
            if ($name) {
                if (!\array_key_exists($name, $this->scope)) {
                    $var = new Variable($name);
                    $this->scope[$name] = $var;
                    $target = $this->currentFunction ?? $this->currentClass ?? $this->getNamespace();
                    $target->addVariable($var);
                }
                else {
                    $start = $node->getStart();
                    $length = $node->getEndPosition() - $start;
                    $this->repo->references[] = new Reference(
                        $this->file,
                        PositionUtilities::getRangeFromPositionInFile($start, $length, $this->src),
                        $this->scope[$name]
                    );
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
                }
            }
        }
    }

    private function leave($node) {
        if ($node instanceof ClassDeclaration) {
            $this->currentClass = null;
        }
        else if ($node instanceof InterfaceDeclaration) {
            $this->currentInterface = null;
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
