<?php

namespace LanguageServer\CodeRepository;

class Variable extends Symbol {

    public function __construct(string $name) {
        $this->name = $name;
    }

    public function fqn(): string {
        if ($this->parent instanceof File) {
            return $this->parent->getNamespace()->fqn().'\\'.$this->name;
        }
        else if ($this->parent instanceof Symbol) {
            return $this->parent->fqn().'::'.$this->name;
        }
    }
}
