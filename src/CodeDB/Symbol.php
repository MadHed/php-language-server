<?php

namespace LanguageServer\CodeDB;

abstract class Symbol {
    public $name;
    public $range;
    public $parent;
    public $children = [];
    public $loc = 0;

    abstract function fqn(): string;

    public function __construct(string $name) {
        $this->name = $name;
    }

    public function addChild(Symbol $child) {
        $this->children[$child->name] = $child;
        $child->parent = $this;
    }
}
