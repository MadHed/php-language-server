<?php

namespace LanguageServer\CodeDB;

abstract class Symbol {
    public $name;
    public $start;
    public $length;
    public $parent;
    public $children;
    public $backRefs;

    abstract function fqn(): string;

    public function __construct(string $name, $start, $length) {
        $this->name = $name;
        $this->start = $start;
        $this->length = $length;
    }

    public function addChild(Symbol $child) {
        $this->children[$child->name] = $child;
        $child->parent = $this;
    }

    public function addBackRef(Reference $ref) {
        echo "Symbol::addBackRef ", $this->name, "\n";
        $this->backRefs[] = $ref;
    }

    public function removeBackRef(Reference $ref) {
        echo "Symbol::removeBackRef ", $this->name, "\n";
        foreach($this->backRefs as $i => $br) {
            if ($br === $ref) {
                unset($this->backRefs[$i]);
                return;
            }
        }
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

    public function getDescription() {
        return $this->fqn();
    }

    public function onReferenceDelete(Reference $ref) {
        echo "Symbol::onReferenceDelete ", $this->name, "\n";
        $this->removeBackRef($ref);
    }

    public function onDelete(Repository $repo) {
        echo "Symbol::onDelete ", $this->name, "\n";
        foreach($this->backRefs ?? [] as $br) {
            $br->onSymbolDelete($repo);
        }
        foreach($this->children ?? [] as $child) {
            $child->onDelete($repo);
        }
    }
}
