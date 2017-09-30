<?php

namespace LanguageServer\CodeRepository;

class File {
    public $name;
    private $hash;
    public $parseTime = 0;

    public $namespaces = [];

    public function __construct(string $name, string $content) {
        $this->name = $name;
        $this->hash = \hash('SHA256', $content);
    }

    public function hash() {
        return $this->hash;
    }

    public function namespaces() {
        return new ArrayIterator($this->namespaces);
    }
}
