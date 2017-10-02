<?php

namespace LanguageServer\CodeDB;

use Microsoft\PhpParser\{
    PositionUtilities,
    Parser,
    Node,
    Token,
    TokenKind,
    DiagnosticsProvider
};

use Microsoft\PhpParser\Node\{
    SourceFileNode,
    MethodDeclaration,
    PropertyDeclaration,
    Parameter,
    QualifiedName,
    NamespaceUseClause,
    ConstElement
};

use Microsoft\PhpParser\Node\Statement\{
    NamespaceDefinition,
    ClassDeclaration,
    InterfaceDeclaration,
    FunctionDeclaration,
    ConstDeclaration
};

use Microsoft\PhpParser\Node\Expression\{
    ObjectCreationExpression,
    CallExpression,
    ScopedPropertyAccessExpression,
    BinaryExpression
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
    private $aliases = [];

    public function __construct($repo, $filename, $src) {
        $this->repo = $repo;
        $this->filename = $filename;
        $this->src = $src;
        $this->file = new File($filename, $src->fileContents);
        $range = PositionUtilities::getRangeFromPositionInFile($src->getStart(), $src->getStart(), $src);
        $this->file->range = $range;
        $repo->files[$filename] = $this->file;
    }

    private function expandName($name) {
        if (isset($this->aliases[$name])) {
            return $this->aliases[$name];
        }

        if (\strlen($name) === 0) {
            return $name;
        }
        else if ($name[0] === '\\') {
            // fully qualified
            return $name;
        }
        else {
            $parts = explode('\\', $name, 2);
            if (isset($this->aliases[$parts[0]])) {
                if (count($parts) > 1) {
                    return $this->aliases[$parts[0]].'\\'.$parts[1];
                }
                else {
                    return $this->aliases[$parts[0]];
                }
            }
            else {
                if ($this->namespace) {
                    return $this->namespace->fqn().'\\'.$name;
                }
                else {
                    return $name;
                }
            }
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

    private function getRangeFromNode($node) {
        if ($node instanceof Node) {
            $start = $node->getStart();
            $end = $node->getEndPosition();
        }
        else if ($node instanceof Token) {
            $start = $node->getStartPosition();
            $end = $node->getEndPosition();
        }
        else {
            $start = $end = 0;
        }
        return PositionUtilities::getRangeFromPositionInFile($start, $end - $start, $this->src);
    }

    private function getText($node) {
        if ($node instanceof Node) {
            return $node->getText();
        }
        else if ($node instanceof Token) {
            return $node->getText($this->src->fileContents);
        }
        return '';
    }

    private function addAlias($name, $alias = null) {
        if ($alias !== null) {
            $this->aliases[$alias] = $name;
            return;
        }
        $name = trim($name, '\\');
        $parts = explode('\\', $name);
        $this->aliases[end($parts)] = '\\'.$name;
    }

    private function getNamespace() {
        if ($this->namespace === null) {
            if (!isset($this->file->children[''])) {
                $ns = new Namespace_('');
                $ns->range = PositionUtilities::getRangeFromPositionInFile(0, 0, $this->src);
                $this->file->addChild($ns);
            }
            $this->namespace = $this->file->children[''];
        }
        return $this->namespace;
    }

    private function visit($node) {
        $diag = DiagnosticsProvider::checkDiagnostics($node);
        if ($diag) {
            $range = PositionUtilities::getRangeFromPositionInFile(
                $diag->start,
                $diag->length,
                $this->src
            );

            $this->file->addDiagnostic(new Diagnostic(
                $diag->kind,
                $diag->message,
                $range->start->line,
                $range->start->character,
                $range->end->line,
                $range->end->character
            ));
        }

        if ($node instanceof NamespaceDefinition) {
            $name = $this->getText($node->name);
            if (isset($this->file->children[$name])) {
                $this->namespace = $this->file->children[$name];
            }
            else {
                $this->namespace = new Namespace_($name);
                $this->namespace->range = $this->getRangeFromNode($node->name);
                $this->file->addChild($this->namespace);
            }
        }
        else if ($node instanceof NamespaceUseClause) {
            $base = $this->getText($node->namespaceName);
            if ($node->namespaceAliasingClause !== null) {
                if ($node->namespaceAliasingClause->name !== null) {
                    $alias = $this->getText($node->namespaceAliasingClause->name);
                    $this->addAlias($base, $alias);
                }
            }
            else if ($node->groupClauses != null && $node->groupClauses->children != null) {
                foreach($node->groupClauses->children as $group) {
                    if ($group && $group instanceof Node && $group->namespaceName !== null) {
                        $ext = $this->getText($group->namespaceName);
                        $this->addAlias($base.$ext);
                    }
                }
            }
            else {
                $this->addAlias($base);
            }
        }
        else if ($node instanceof ClassDeclaration) {
            $name = $node->name->getText($this->src->fileContents);
            if ($name) {
                $this->currentClass = new Class_($name);
                $this->getNamespace()->addChild($this->currentClass);
                $this->repo->fqnMap[$this->currentClass->fqn()] = $this->currentClass;
                $this->currentClass->range = $this->getRangeFromNode($node->name);

                if ($node->classBaseClause && $node->classBaseClause->baseClass) {
                    $className = $node->classBaseClause->baseClass->getText();
                    $this->repo->references[] = new Reference(
                        $this->file,
                        $this->getRangeFromNode($node->classBaseClause->baseClass),
                        $this->expandName($className)
                    );
                }
                if ($node->classInterfaceClause && $node->classInterfaceClause->interfaceNameList) {
                    foreach($node->classInterfaceClause->interfaceNameList->children as $interfaceName) {
                        if ($interfaceName instanceof Node) {
                            $name = $interfaceName->getText();
                            $this->repo->references[] = new Reference(
                                $this->file,
                                $this->getRangeFromNode($interfaceName),
                                $this->expandName($name)
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
                $this->getNamespace()->addChild($this->currentInterface);
                $this->repo->fqnMap[$this->currentInterface->fqn()] = $this->currentInterface;
                $this->currentInterface->range = $this->getRangeFromNode($node->name);

                if ($node->interfaceBaseClause && $node->interfaceBaseClause->interfaceNameList) {
                    foreach($node->interfaceBaseClause->interfaceNameList->children as $interfaceName) {
                        if ($interfaceName instanceof Node) {
                            $name = $interfaceName->getText();
                            $this->repo->references[] = new Reference(
                                $this->file,
                                $this->getRangeFromNode($interfaceName),
                                $this->expandName($name)
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
                $this->getNamespace()->addChild($this->currentFunction);
                $this->repo->fqnMap[$this->currentFunction->fqn()] = $this->currentFunction;
                $this->currentFunction->range = $this->getRangeFromNode($node->name);
            }
        }
        else if ($node instanceof MethodDeclaration) {
            $name = $node->name->getText($this->src);
            if ($name && $this->currentClass) {
                $this->currentFunction = new Function_($name);
                $this->currentClass->addChild($this->currentFunction);
                $this->repo->fqnMap[$this->currentFunction->fqn()] = $this->currentFunction;
                $this->currentFunction->range = $this->getRangeFromNode($node->name);
            }
        }
        else if ($node instanceof ConstDeclaration) {
            foreach($node->constElements as $el) {
                if (!$el instanceof ConstElement) continue;
                $name = $this->getText($el->name);
                if ($name && $this->currentClass) {
                    $co = new Constant($name);
                    $co->range = $this->getRangeFromNode($el->name);
                    $this->currentClass->addChild($co);
                    $this->repo->fqnMap[$co->fqn()] = $co;
                }
            }
        }
        else if ($node instanceof \Microsoft\PhpParser\Node\Expression\Variable) {
            $name = $node->getName();
            if ($name) {
                // if (!\array_key_exists($name, $this->scope)) {
                //     $var = new Variable($name);
                //     $this->scope[$name] = $var;
                //     $target = $this->currentFunction ?? $this->currentClass ?? $this->getNamespace();
                //     $target->addChild($var);
                //     $range = PositionUtilities::getRangeFromPositionInFile($node->getStart(), $node->getEndPosition() - $node->getStart(), $this->src);
                //     $var->range = $range;
                // }
                // else {
                //     /*$start = $node->getStart();
                //     $length = $node->getEndPosition() - $start;
                //     $this->repo->references[] = new Reference(
                //         $this->file,
                //         PositionUtilities::getRangeFromPositionInFile($start, $length, $this->src),
                //         $this->scope[$name]
                //     );*/
                // }
            }
        }
        else if ($node instanceof Parameter) {
            // $name = $node->getName();
            // if ($this->currentFunction && $name) {
            //     if (!\array_key_exists($name, $this->scope)) {
            //         $var = new Variable($name);
            //         $this->scope[$name] = $var;
            //         $this->currentFunction->addChild($var);
            //         $range = PositionUtilities::getRangeFromPositionInFile($node->getStart(), $node->getEndPosition() - $node->getStart(), $this->src);
            //         $var->range = $range;
            //     }
            // }
        }
        else if ($node instanceof ObjectCreationExpression) {
            if ($node->classTypeDesignator instanceof QualifiedName) {
                $name = $node->classTypeDesignator->getText();
            }
            else if ($node->classTypeDesignator instanceof Token) {
                $name = $node->classTypeDesignator->getText($this->src->fileContents);
            }
            else {
                return;
            }

            $fqn = $this->expandName($name);
            $this->repo->references[] = new Reference(
                $this->file,
                $this->getRangeFromNode($node->classTypeDesignator),
                $fqn
            );
        }
        else if ($node instanceof ScopedPropertyAccessExpression) {
            // ->callableExpression
            // ? ScopedPropertyAccessExpression
            // -> scopeResolutionQualifier !(Token|Node)
            // -> memberName !Token
            if (
                $node->scopeResolutionQualifier instanceof Token
                || $node->scopeResolutionQualifier instanceof QualifiedName
                && $node->memberName instanceof Token
            ) {
                $className = $this->expandName($this->getText($node->scopeResolutionQualifier));
                $memberName = $this->getText($node->memberName);
                if ($node->parent instanceof CallExpression) {
                    $refName = $className.'::'.$memberName.'()';
                }
                else {
                    $refName = $className.'::'.$memberName;
                }

                $this->repo->references[] = new Reference(
                    $this->file,
                    $this->getRangeFromNode($node->scopeResolutionQualifier),
                    $className
                );
                $this->repo->references[] = new Reference(
                    $this->file,
                    $this->getRangeFromNode($node->memberName),
                    $refName
                );
            }
        }
        else if ($node instanceof BinaryExpression) {
            if (
                $node->operator->kind === TokenKind::InstanceOfKeyword
                && (
                    $node->rightOperand instanceof QualifiedName
                    || $node->rightOperand instanceof Token
                )
            ) {
                $className = $this->expandName($this->getText($node->rightOperand));
                $this->repo->references[] = new Reference(
                    $this->file,
                    $this->getRangeFromNode($node->rightOperand),
                    $className
                );
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
