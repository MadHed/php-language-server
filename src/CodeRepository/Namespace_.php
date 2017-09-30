<?php

namespace LanguageServer\CodeRepository;

class Namespace_ extends Symbol {
    private $classes = [];
    private $interfaces = [];
    private $functions = [];
    private $variables = [];

    public function __construct(string $name) {
        $name = trim($name, '\\');
        $this->name = $name;
    }

    public function fqn(): string {
        return ($this->name ? '\\' : '').$this->name;
    }

    public function addVariable(Variable $var) {
        $this->variables[$var->name] = $var;
        $var->parent = $this;
    }

    public function addClass(Class_ $cls) {
        $this->classes[$cls->name] = $cls;
        $cls->parent = $this;
    }

    public function addInterface(Interface_ $iface) {
        $this->interfaces[$iface->name] = $iface;
        $iface->parent = $this;
    }

    public function addFunction(Function_ $fun) {
        $this->functions[$fun->name] = $fun;
        $fun->parent = $this;
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
