<?php

namespace LanguageServer\CodeRepository;

class File {
    public $name;
    public $parent;
    public $namespaces = [];
    private $content;
    private $hash;
    public $parseTime = 0;

    public function __construct(string $name, string $content) {
        $this->name = $name;
        $this->hash = \hash('SHA256', $content);
    }

    public function hash() {
        return $this->hash;
    }

    public function getNamespace(): Namespace_ {
        return $this->parent;
    }

    public function namespaces() {
        return new ArrayIterator($this->namespaces);
    }
}
