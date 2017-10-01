<?php

namespace LanguageServer\CodeDB;

class Class_ extends Symbol {
    private $implements = [];
    private $extends;

    public function __construct(string $name) {
        parent::__construct($name);
    }

    public function fqn(): string {
        return $this->parent->fqn().'\\'.$this->name;
    }
}
