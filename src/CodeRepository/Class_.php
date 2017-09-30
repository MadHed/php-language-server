<?php

namespace LanguageServer\CodeRepository;

class Class_ extends Symbol {
    public $parent;
    private $implements = [];
    private $extends;
    private $variables = [];
    private $functions = [];

    public function __construct(string $name) {
        $this->name = $name;
    }

    public function fqn(): string {
        return $this->parent->fqn().'\\'.$this->name;
    }

    public function addFunction(Function_ $fun) {
        $this->functions[$fun->name] = $fun;
        $fun->parent = $this;
    }

    public function addVariable(Variable $var) {
        $this->variables[$var->name] = $var;
        $var->parent = $this;
    }

    public function variables() {
        return new ArrayIterator($this->variables);
    }

    public function functions() {
        return new ArrayIterator($this->functions);
    }
}
