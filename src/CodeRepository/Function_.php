<?php

namespace LanguageServer\CodeRepository;

class Function_ extends Symbol {
    public $parent;
    private $variables = [];

    public function __construct(string $name) {
        $this->name = $name;
    }

    public function addVariable(Variable $var) {
        $this->variables[$var->name] = $var;
        $var->parent = $this;
    }

    public function variables() {
        return new ArrayIterator($this->variables);
    }

    public function fqn(): string {
        if ($this->parent instanceof File) {
            return $this->parent->getNamespace()->fqn().'\\'.$this->name.'()';
        }
        else if ($this->parent instanceof Symbol) {
            return $this->parent->fqn().'::'.$this->name.'()';
        }
    }
}
