<?php

namespace LanguageServer\CodeRepository;

class Class_ implements Symbol {
    public $parent;
    private $name;
    private $implements = [];
    private $extends;
    private $variables = [];
    private $functions = [];

    public function __construct(string $name) {
        $this->name = $name;
    }

    public function getName(): string {
        return $this->name;
    }

    public function getFQN(): string {
        return $this->parent->getNamespace()->getFQN().'\\'.$this->name;
    }

    public function addFunction(Function_ $fun) {
        $this->functions[$fun->getName()] = $fun;
        $fun->parent = $this;
    }

    public function addVariable(Variable $var) {
        $this->variables[$var->getName()] = $var;
        $var->parent = $this;
    }

    public function variables() {
        return new ArrayIterator($this->variables);
    }

    public function functions() {
        return new ArrayIterator($this->functions);
    }
}
