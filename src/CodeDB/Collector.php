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
    ConstElement,
    ClassConstDeclaration
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
    BinaryExpression,
    MemberAccessExpression,
    Variable as VariableExpression
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
    private $namespaces = [];

    public function __construct($repo, $filename, $src) {
        $this->repo = $repo;
        $this->filename = $filename;
        $this->src = $src;
        $this->file = new File($filename, $src->fileContents);
        $repo->files[$filename] = $this->file;
    }

    private function expandName($name, $isfunc = false) {
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
                    return '\\'.$name;
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
        return PositionUtilities::getRangeFromPosition($start, $end - $start, $this->src->fileContents);
    }

    private function getStart($node) {
        return $node ? ($node instanceof Node ? $node->getStart() : $node->getStartPosition()) : 0;
    }

    private function getLength($node) {
        $start = $this->getStart($node);
        return $node ? $node->getEndPosition() - $start : 0;
    }

    private function nodeOffsetToSymbol($node, $symbol) {
        if ($node === null) return;
        $symbol->setStart($node instanceof Node ? $node->getStart() : $node->getStartPosition());
        $symbol->setLength($node->getEndPosition() - $symbol->getStart());
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
            if ($name[0] !== '\\') $name = '\\'.$name;
            $this->aliases[$alias] = $name;
            return;
        }
        $name = trim($name, '\\');
        $parts = explode('\\', $name);
        $this->aliases[end($parts)] = '\\'.$name;
    }

    private function getNamespace() {
        if ($this->namespace === null) {
            if (!isset($this->namespaces[''])) {
                $ns = new Namespace_('', 0, 0);
                $this->file->addChild($ns);
                $this->namespaces[''] = $ns;
            }
            $this->namespace = $this->namespaces[''];
        }
        return $this->namespace;
    }

    private function visit($node) {
        $diag = DiagnosticsProvider::checkDiagnostics($node);
        if ($diag) {
            $this->file->addDiagnostic(new Diagnostic(
                $diag->kind,
                $diag->message,
                $diag->start,
                $diag->length
            ));
        }

        if ($node instanceof NamespaceDefinition) {
            $name = $this->getText($node->name);
            if (isset($this->namespaces[$name])) {
                $this->namespace = $this->namespaces[$name];
            }
            else {
                $this->namespace = new Namespace_(
                    $name,
                    $this->getStart($node->name ?? $node),
                    $this->getLength($node->name ?? $node)
                );
                $this->file->addChild($this->namespace);
                $this->namespaces[$name] = $this->namespace;
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

                        if ($group->namespaceAliasingClause !== null && $group->namespaceAliasingClause->name !== null) {
                            $alias = $this->getText($group->namespaceAliasingClause->name);
                            $this->addAlias($base.$ext, $alias);
                        }
                        else {
                            $this->addAlias($base.$ext);
                        }
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
                $this->currentClass = new Class_($name, $this->getStart($node->name), $this->getLength($node->name));
                $this->getNamespace()->addChild($this->currentClass);
                $this->repo->fqnMap[$this->currentClass->fqn()] = $this->currentClass;

                if ($node->classBaseClause && $node->classBaseClause->baseClass) {
                    $className = $node->classBaseClause->baseClass->getText();
                    $ref = new Reference(
                        $this->file,
                        $this->getStart($node->classBaseClause->baseClass),
                        $this->getLength($node->classBaseClause->baseClass),
                        strtolower($this->expandName($className))
                    );
                    $this->currentClass->extends = $ref;
                    $this->repo->addUnresolvedReference($ref);
                }
                if ($node->classInterfaceClause && $node->classInterfaceClause->interfaceNameList) {
                    foreach($node->classInterfaceClause->interfaceNameList->children as $interfaceName) {
                        if ($interfaceName instanceof Node) {
                            $name = $interfaceName->getText();
                            $ref = new Reference(
                                $this->file,
                                $this->getStart($interfaceName),
                                $this->getLength($interfaceName),
                                strtolower($this->expandName($name))
                            );
                            $this->currentClass->implements[] = $ref;
                            $this->repo->addUnresolvedReference($ref);
                        }
                    }
                }
            }
        }
        else if ($node instanceof InterfaceDeclaration) {
            $name = $node->name->getText($this->src->fileContents);
            if ($name) {
                $this->currentInterface = new Interface_($name, $this->getStart($node->name), $this->getLength($node->name));
                $this->getNamespace()->addChild($this->currentInterface);
                $this->repo->fqnMap[$this->currentInterface->fqn()] = $this->currentInterface;

                if ($node->interfaceBaseClause && $node->interfaceBaseClause->interfaceNameList) {
                    foreach($node->interfaceBaseClause->interfaceNameList->children as $interfaceName) {
                        if ($interfaceName instanceof Node) {
                            $name = $interfaceName->getText();
                            $ref = new Reference(
                                $this->file,
                                $this->getStart($interfaceName),
                                $this->getLength($interfaceName),
                                strtolower($this->expandName($name))
                            );
                            $this->currentInterface->extends[] = $ref;
                            $this->repo->addUnresolvedReference($ref);
                        }
                    }
                }
            }
        }
        else if ($node instanceof FunctionDeclaration) {
            $name = $node->name->getText($this->src->fileContents);
            if ($name) {
                $this->currentFunction = new Function_($name, $this->getStart($node->name), $this->getLength($node->name));
                $this->getNamespace()->addChild($this->currentFunction);
                $this->repo->fqnMap[$this->currentFunction->fqn()] = $this->currentFunction;
            }
        }
        else if ($node instanceof MethodDeclaration) {
            $name = $node->name->getText($this->src);
            if ($name && ($this->currentClass || $this->currentInterface)) {
                $this->currentFunction = new Function_($name, $this->getStart($node->name), $this->getLength($node->name));
                ($this->currentClass ?? $this->currentInterface)->addChild($this->currentFunction);
                $this->repo->fqnMap[$this->currentFunction->fqn()] = $this->currentFunction;
            }
        }
        else if ($node instanceof ConstDeclaration || $node instanceof ClassConstDeclaration) {
            if (!$node->constElements) return;
            foreach($node->constElements as $elements) {
                if (!is_array($elements)) {
                    $elements = [$elements];
                }
                foreach($elements as $el) {
                    if (!$el instanceof ConstElement) continue;
                    $name = $this->getText($el->name);
                    $co = new Constant($name, $this->getStart($el->name), $this->getLength($el->name));
                    ($this->currentClass ?? $this->currentInterface ?? $this->currentFunction ?? $this->getNamespace())->addChild($co);
                    $this->repo->fqnMap[$co->fqn()] = $co;
                }
            }
        }
        else if ($node instanceof VariableExpression) {
            /*$name = $node->getName();
            if ($name) {
                if ($name === 'this') {
                    if (!$this->currentClass) return;
                    $start = $node->getStart();
                    $length = $node->getEndPosition() - $start;
                    $ref = new Reference(
                        $this->file,
                        $start,
                        $length,
                        $this->currentClass
                    );
                    $this->currentClass->addBackRef($ref);
                    $this->file->references[] = $ref;
                }
                else if (!\array_key_exists($name, $this->scope)) {
                    $var = new Variable($name, $this->getStart($node->name), $this->getLength($node->name));
                    $this->scope[$name] = $var;
                    $target = $this->currentFunction ?? $this->currentClass ?? $this->getNamespace();
                    $target->addChild($var);
                }
                else {
                    $start = $node->getStart();
                    $length = $node->getEndPosition() - $start;
                    $ref = new Reference(
                        $this->file,
                        $start,
                        $length,
                        $this->scope[$name]
                    );
                    $this->scope[$name]->addBackRef($ref);
                    $this->file->references[] = $ref;
                }
            }*/
        }
        else if ($node instanceof \Microsoft\PhpParser\Node\Parameter) {
            /*$name = $node->getName();
            if ($name) {
                $var = new Variable($name, $this->getStart($node->variableName), $this->getLength($node->variableName));
                $this->scope[$name] = $var;
                $target = $this->currentFunction ?? $this->currentClass ?? $this->getNamespace();
                $target->addChild($var);
            }*/
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

            if ($name === 'self' || $name === 'static') {
                if (!$this->currentClass) return;
                $fqn = $this->currentClass->fqn();
            }
            else if ($name === 'parent') {
                if (!$this->currentClass || !$this->currentClass->extends) return;
                $fqn = $this->currentClass->extends->target;
            }
            else if ($name === 'class') {
                return;
            }
            else {
                $fqn = $this->expandName($name);
            }

            $ref = new Reference(
                $this->file,
                $this->getStart($node->classTypeDesignator),
                $this->getLength($node->classTypeDesignator),
                strtolower($fqn)
            );
            $this->file->references[] = $ref;
            $this->repo->addUnresolvedReference($ref);
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
                $className = $this->getText($node->scopeResolutionQualifier);
                if ($className === 'self' || $className === 'static') {
                    if (!$this->currentClass) return;
                    $className = $this->currentClass->fqn();
                }
                else if ($className === 'parent') {
                    if (!$this->currentClass) return;
                    if (!$this->currentClass->extends) return;
                    $className = $this->currentClass->extends->target;
                }
                else {
                    $className = $this->expandName($className);
                }

                $memberName = $this->getText($node->memberName);
                if ($node->parent instanceof CallExpression) {
                    $refName = strtolower($className.'::'.$memberName.'()');
                }
                else {
                    if ($memberName === 'class') return;
                    $refName = strtolower($className).'::#'.$memberName;
                }

                $ref = new Reference(
                    $this->file,
                    $this->getStart($node->scopeResolutionQualifier),
                    $this->getLength($node->scopeResolutionQualifier),
                    strtolower($className)
                );
                $this->repo->addUnresolvedReference($ref);
                $this->file->references[] = $ref;

                $ref = new Reference(
                    $this->file,
                    $this->getStart($node->memberName),
                    $this->getLength($node->memberName),
                    $refName
                );
                $this->repo->addUnresolvedReference($ref);
                $this->file->references[] = $ref;
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
                $className = $this->getText($node->rightOperand);
                if ($className === 'self' || $className === 'static') {
                    if (!$this->currentClass) return;
                    $className = $this->currentClass->fqn();
                }
                else if ($className === 'parent') {
                    if (!$this->currentClass) return;
                    if (!$this->currentClass->extends) return;
                    $className = $this->currentClass->extends->target;
                }
                else {
                    $className = $this->expandName($className);
                }

                $ref = new Reference(
                    $this->file,
                    $this->getStart($node->rightOperand),
                    $this->getLength($node->rightOperand),
                    strtolower($className)
                );
                $this->repo->addUnresolvedReference($ref);
                $this->file->references[] = $ref;
            }
        }
        else if ($node instanceof CallExpression) {
            if (
                $node->callableExpression instanceof QualifiedName
            ) {
                $funcName = $this->expandName($this->getText($node->callableExpression), true);
                $ref = new Reference(
                    $this->file,
                    $this->getStart($node->callableExpression),
                    $this->getLength($node->callableExpression),
                    strtolower($funcName.'()')
                );
                $this->repo->addUnresolvedReference($ref);
                $this->file->references[] = $ref;
            }
            else if ($node->callableExpression instanceof MemberAccessExpression) {
                if (
                    $node->callableExpression->dereferencableExpression instanceof VariableExpression
                    && $node->callableExpression->dereferencableExpression->getName() === 'this'
                    && $this->currentClass
                ) {
                    $ref = new Reference(
                        $this->file,
                        $this->getStart($node->callableExpression->memberName),
                        $this->getLength($node->callableExpression->memberName),
                        $this->currentClass->fqn().'::'.strtolower($this->getText($node->callableExpression->memberName)).'()'
                    );
                    $this->repo->addUnresolvedReference($ref);
                    $this->file->references[] = $ref;
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
