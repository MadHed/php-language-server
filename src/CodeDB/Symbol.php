<?php

namespace LanguageServer\CodeDB;

abstract class Symbol {
    public $name;
    public $range;
    public $parent;
    public $children;

    abstract function fqn(): string;

    public function __construct(string $name) {
        $this->name = $name;
    }

    public function addChild(Symbol $child) {
        $this->children[$child->name] = $child;
        $child->parent = $this;
    }

    public function getFile() {
        $node = $this;
        do {
            if ($node instanceof File) {
                return $node;
            }
            $node = $node->parent;
        } while ($node !== null);
        return null;
    }
}
