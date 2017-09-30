<?php

namespace LanguageServer\CodeRepository;

class Function_ extends Symbol {
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
        if ($this->parent instanceof Namespace_) {
            return $this->parent->fqn().'\\'.$this->name.'()';
        }
        else if ($this->parent instanceof Class_ || $this->parent instanceof Interface_) {
            return $this->parent->fqn().'::'.$this->name.'()';
        }
        else {
            return $this->parent->fqn().'@'.$this->name.'()';
        }
    }
}
