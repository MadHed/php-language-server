<?php

namespace LanguageServer\CodeRepository;

class File {
    private $name;
    public $parent;
    private $classes = [];
    private $interfaces = [];
    private $functions = [];
    private $variables = [];
    private $constants = [];
    private $content;
    private $hash;
    public $parseTime = 0;

    public function __construct(string $name, string $content) {
        $this->name = $name;
        $this->hash = \hash('SHA256', $content);
    }

    public function hash() {
        return $this->hash;
    }

    public function addVariable(Variable $var) {
        $this->variables[$var->getName()] = $var;
        $var->parent = $this;
    }

    public function addClass(Class_ $cls) {
        $this->classes[$cls->getName()] = $cls;
        $cls->parent = $this;
    }

    public function addInterface(Interface_ $iface) {
        $this->interfaces[$iface->getName()] = $iface;
        $iface->parent = $this;
    }

    public function addFunction(Function_ $fun) {
        $this->functions[$fun->getName()] = $fun;
        $fun->parent = $this;
    }

    public function getNamespace(): Namespace_ {
        return $this->parent;
    }

    public function getName(): string {
        return $this->name;
    }

    public function variables() {
        return new ArrayIterator($this->variables);
    }

    public function classes() {
        return new ArrayIterator($this->classes);
    }

    public function interfaces() {
        return new ArrayIterator($this->interfaces);
    }

    public function functions() {
        return new ArrayIterator($this->functions);
    }
}
