<?php

namespace LanguageServer\CodeDB;

class Class_ extends Symbol {
    public $implements;
    public $extends;

    public function __construct(string $name) {
        parent::__construct($name);
    }

    public function fqn(): string {
        return $this->parent->fqn().'\\'.$this->name;
    }
}
