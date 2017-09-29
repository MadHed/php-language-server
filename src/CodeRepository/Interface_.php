<?php

namespace LanguageServer\CodeRepository;

class Interface_ implements Symbol {
    private $parent;
    private $name;
    private $functions = [];

    public function __construct(string $name) {
        $this->name = $name;
    }

    public function getName(): string {
        return $this->name;
    }

    public function getFQN(): string {
        return $this->parent->getNamespace()->getFQN().'\\'.$this->name;
    }
}
