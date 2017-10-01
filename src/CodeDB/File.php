<?php

namespace LanguageServer\CodeDB;

class File extends Symbol {
    private $hash;
    public $parseTime = 0;

    public function __construct(string $name, string $content) {
        parent::__construct($name);
        $this->hash = \hash('SHA256', $content);
    }

    public function fqn(): string {
        return $this->name;
    }

    public function hash() {
        return $this->hash;
    }
}
