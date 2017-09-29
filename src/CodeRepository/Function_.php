<?php

namespace LanguageServer\CodeRepository;

class Function_ implements Symbol {
    public $parent;
    private $name;
    private $variables = [];

    public function __construct(string $name) {
        $this->name = $name;
    }

    public function getName(): string {
        return $this->name;
    }

    public function addVariable(Variable $var) {
        $this->variables[$var->getName()] = $var;
        $var->parent = $this;
    }

    public function variables() {
        return new ArrayIterator($this->variables);
    }

    public function getFQN(): string {
        if ($this->parent instanceof File) {
            return $this->parent->getNamespace()->getFQN().'\\'.$this->name.'()';
        }
        else if ($this->parent instanceof Symbol) {
            return $this->parent->getFQN().'::'.$this->name.'()';
        }
    }
}
