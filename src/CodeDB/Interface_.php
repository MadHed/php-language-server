<?php

namespace LanguageServer\CodeDB;

class Interface_ extends Symbol {
    public function __construct(string $name) {
        parent::__construct($name);
    }

    public function fqn(): string {
        return $this->parent->fqn().'\\'.$this->name;
    }
}
