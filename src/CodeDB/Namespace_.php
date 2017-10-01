<?php

namespace LanguageServer\CodeDB;

class Namespace_ extends Symbol {

    public function __construct(string $name) {
        $name = trim($name, '\\');
        parent::__construct($name);
    }

    public function fqn(): string {
        return ($this->name ? '\\' : '').$this->name;
    }
}
