<?php

namespace LanguageServer\CodeDB;

class Variable extends Symbol {

    public function __construct(string $name) {
        parent::__construct($name);
    }

    public function fqn(): string {
        if ($this->parent instanceof File) {
            return $this->parent->fqn().'\\'.$this->name;
        }
        else if ($this->parent instanceof Symbol) {
            return $this->parent->fqn().'::'.$this->name;
        }
    }
}
