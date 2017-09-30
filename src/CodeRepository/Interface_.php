<?php

namespace LanguageServer\CodeRepository;

class Interface_ extends Symbol {
    public $parent;
    private $functions = [];

    public function __construct(string $name) {
        $this->name = $name;
    }

    public function fqn(): string {
        return $this->parent->fqn().'\\'.$this->name;
    }
}
