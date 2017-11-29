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
    ClassConstDeclaration,
    StringLiteral,
    Expression
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
    Variable as VariableExpression,
    AnonymousFunctionCreationExpression
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
        $this->file = new File;
        $this->file->uri = $filename;
        $this->file->hash = \hash('sha256', $src);
        $repo->addFile($this->file);
    }

    private function createSymbol($type, string $name, $range = null) {
        $fqn = $name;
        $parent_id = null;

        switch($type) {
            case Symbol::_VARIABLE: $fqn = '$'.$fqn; break;
            case Symbol::_CONSTANT: $fqn = '#'.$fqn; break;
            case Symbol::_FUNCTION: $fqn = $fqn.'()'; break;
        }

        if ($this->currentFunction) {
            $fqn = $this->currentFunction->fqn.$fqn;
            $parent_id = $this->currentFunction->id;
        }
        else if ($this->currentClass) {
            $fqn = $this->currentClass->fqn.'::'.$fqn;
            $parent_id = $this->currentClass->id;
        }
        else if ($this->currentInterface) {
            $fqn = $this->currentInterface->fqn.'::'.$fqn;
            $parent_id = $this->currentInterface->id;
        }
        else if ($this->namespace) {
            $fqn = $this->namespace->fqn.'\\'.$fqn;
            $parent_id = $this->namespace->id;
        }

        if ($fqn == '') $fqn = '\\';

        if ($fqn[0] !== '\\') $fqn = '\\'.$fqn;

        $fqn = strtolower($fqn);

        $sym = new Symbol;
        $sym->parent_id = $parent_id;
        $sym->name = $name;
        $sym->fqn = $fqn;
        $sym->type = $type;
        $sym->file_id = $this->file->id;
        $sym->range_start_line = $range ? $range->start->line : 0;
        $sym->range_start_character = $range ? $range->start->character : 0;
        $sym->range_end_line = $range ? $range->end->line : 0;
        $sym->range_end_character = $range ? $range->end->character : 0;
        $this->repo->addSymbol($sym);
        return $sym;
    }

    private function createReference($type, string $fqn, $range) {
        $ref = new Reference;
        $ref->fqn = strtolower($fqn);
        $ref->type = $type;
        $ref->file_id = $this->file->id;
        $ref->range_start_line = $range->start->line;
        $ref->range_start_character = $range->start->character;
        $ref->range_end_line = $range->end->line;
        $ref->range_end_character = $range->end->character;
        $this->repo->addReference($ref);
        return $ref;
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
                    return $this->namespace->fqn.'\\'.$name;
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

    private function getGlobalNamespace() {
        if (!isset($this->namespaces[''])) {
            $ns = $this->createSymbol(Symbol::_NAMESPACE, '');
            $this->namespaces[''] = $ns;
        }
        return $this->namespaces[''];
    }

    private function getNamespace() {
        if ($this->namespace === null) {
            $this->namespace = $this->getGlobalNamespace();
        }
        return $this->namespace;
    }

    private function visit($node) {
        $diag = DiagnosticsProvider::checkDiagnostics($node);
/*        if ($diag) {
            $this->file->addDiagnostic(new Diagnostic(
                $diag->kind,
                $diag->message,
                $diag->start,
                $diag->length
            ));
        }*/

        if ($node instanceof NamespaceDefinition) {
            $name = $this->getText($node->name);
            if (isset($this->namespaces[$name])) {
                $this->namespace = $this->namespaces[$name];
            }
            else {
                $ns = $this->createSymbol(Symbol::_NAMESPACE, $name, $this->getRangeFromNode($node->name ?? $node));
                $this->namespace = $ns;
                $this->namespaces[$name] = $ns;
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
                $this->currentClass = $this->createSymbol(Symbol::_CLASS, $name, $this->getRangeFromNode($node->name));

                if ($node->classBaseClause && $node->classBaseClause->baseClass) {
                    $className = $node->classBaseClause->baseClass->getText();
                    $ref = $this->createReference(0, strtolower($this->expandName($className)), $this->getRangeFromNode($node->classBaseClause->baseClass));
                }
                if ($node->classInterfaceClause && $node->classInterfaceClause->interfaceNameList) {
                    foreach($node->classInterfaceClause->interfaceNameList->children as $interfaceName) {
                        if ($interfaceName instanceof Node) {
                            $name = $interfaceName->getText();
                            $ref = $this->createReference(0, strtolower($this->expandName($name)), $this->getRangeFromNode($interfaceName));
                        }
                    }
                }
            }
        }
        else if ($node instanceof InterfaceDeclaration) {
            $name = $node->name->getText($this->src->fileContents);
            if ($name) {
                $this->currentInterface = $this->createSymbol(Symbol::_INTERFACE, $name, $this->getRangeFromNode($node->name));

                if ($node->interfaceBaseClause && $node->interfaceBaseClause->interfaceNameList) {
                    foreach($node->interfaceBaseClause->interfaceNameList->children as $interfaceName) {
                        if ($interfaceName instanceof Node) {
                            $name = $interfaceName->getText();
                            $ref = $this->createReference(0, strtolower($this->expandName($name)), $this->getRangeFromNode($interfaceName));
                        }
                    }
                }
            }
        }
        else if ($node instanceof FunctionDeclaration) {
            $name = $node->name->getText($this->src->fileContents);
            if ($name) {
                $this->currentFunction = $this->createSymbol(Symbol::_FUNCTION, $name, $this->getRangeFromNode($node->name));
            }
        }
        else if ($node instanceof MethodDeclaration) {
            $name = $node->name->getText($this->src);
            if ($name && ($this->currentClass || $this->currentInterface)) {
                $this->currentFunction = $this->createSymbol(Symbol::_FUNCTION, $name, $this->getRangeFromNode($node->name));
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
                    $co = $this->createSymbol(Symbol::_CONSTANT, $name, $this->getRangeFromNode($el->name));
                }
            }
        }
        else if ($node instanceof VariableExpression) {
            $name = $node->getName();
            if ($name) {
                if ($name === 'this') {
                    /*if (!$this->currentClass) return;
                    $start = $node->getStart();
                    $length = $node->getEndPosition() - $start;
                    $ref = new Reference(
                        $this->file,
                        $start,
                        $length,
                        $this->currentClass
                    );
                    $this->currentClass->addBackRef($ref);
                    $this->file->references->append($ref);*/
                }
                else if (!\array_key_exists($name, $this->scope)) {
                    $var = $this->createSymbol(Symbol::_VARIABLE, $name, $this->getRangeFromNode($node->name));
                    $this->scope[$name] = $var;
                }
                else {
                    $var = $this->scope[$name];
                    $ref = $this->createReference(0, $var->fqn, $this->getRangeFromNode($node));
                }
            }
        }
        else if (
            $node instanceof QualifiedName
            && ($node->parent instanceof Node\Statement\ExpressionStatement || $node->parent instanceof Expression) &&
            !(
                $node->parent instanceof Node\Expression\MemberAccessExpression || $node->parent instanceof CallExpression ||
                $node->parent instanceof ObjectCreationExpression ||
                $node->parent instanceof Node\Expression\ScopedPropertyAccessExpression || $node->parent instanceof AnonymousFunctionCreationExpression ||
                ($node->parent instanceof Node\Expression\BinaryExpression && $node->parent->operator->kind === TokenKind::InstanceOfKeyword)
            )
        ) {
            $name = $this->getText($node);
            if ($name) {
                $ref = $this->createReference(0, '\\#'.$name, $this->getRangeFromNode($node));
            }
        }
        else if ($node instanceof \Microsoft\PhpParser\Node\Parameter) {
            $name = $node->getName();
            if ($name) {
                $var = $this->createSymbol(Symbol::_VARIABLE, $name, $this->getRangeFromNode($node->variableName));
                $this->scope[$name] = $var;
            }
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
                $fqn = $this->currentClass->fqn;
            }
            else if ($name === 'parent') {
                return; // TODO
            }
            else if ($name === 'class') {
                return;
            }
            else {
                $fqn = $this->expandName($name);
            }

            $ref = $this->createReference(0, strtolower($fqn), $this->getRangeFromNode($node->classTypeDesignator));
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
                    $className = $this->currentClass->fqn;
                }
                else if ($className === 'parent') {
                    return; // TODO
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

                $ref = $this->createReference(0, strtolower($className), $this->getRangeFromNode($node->scopeResolutionQualifier));

                $ref = $this->createReference(0, $refName, $this->getRangeFromNode($node->memberName));
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
                    $className = $this->currentClass->fqn;
                }
                else if ($className === 'parent') {
                    return; // TODO
                }
                else {
                    $className = $this->expandName($className);
                }

                $ref = $this->createReference(0, strtolower($className), $this->getRangeFromNode($node->rightOperand));
            }
        }
        else if ($node instanceof CallExpression) {
            if (
                $node->callableExpression instanceof QualifiedName
            ) {
                $name = $this->getText($node->callableExpression);
                if (strcasecmp($name, 'define') === 0) {
                    if (count($node->argumentExpressionList->children) > 1) {
                        if ($node->argumentExpressionList->children[0]->expression instanceof StringLiteral) {
                            $name = $node->argumentExpressionList->children[0]->expression->getStringContentsText();
                            $con = $this->createSymbol(Symbol::_CONSTANT, $name, $this->getRangeFromNode($node->argumentExpressionList->children[0]));
                        }
                    }
                }
                else {
                    $funcName = $this->expandName($name, true);
                    $ref = $this->createReference(0, strtolower($funcName.'()'), $this->getRangeFromNode($node->callableExpression));
                }
            }
        }
        else if ($node instanceof MemberAccessExpression) {
            if (
                $node->dereferencableExpression instanceof VariableExpression
                && $node->dereferencableExpression->getName() === 'this'
                && $this->currentClass
            ) {
                $memberName = $this->getText($node->memberName);
                if (strlen($memberName) === 0 || $memberName[0] === '$') return;
                if ($node->parent instanceof CallExpression) {
                    $fqn = $this->currentClass->fqn.'::'.strtolower($memberName).'()';
                }
                else {
                    $fqn = $this->currentClass->fqn.'::$'.$memberName;
                }

                $ref = $this->createReference(0, $fqn, $this->getRangeFromNode($node->memberName));
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
