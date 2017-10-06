<?php

namespace LanguageServer\CodeDB;

class Namespace_ extends Symbol {

    public function __construct(string $name, $start, $length) {
        $name = trim($name, '\\');
        parent::__construct($name, $start, $length);
    }

    public function fqn(): string {
        return ($this->name ? '\\' : '').strtolower($this->name);
    }

    public function getDescription() {
        return "<?php\nnamespace ".$this->fqn();
    }
}
