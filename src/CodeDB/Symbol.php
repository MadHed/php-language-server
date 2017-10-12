<?php

namespace LanguageServer\CodeDB;

abstract class Symbol extends FileRegion {
    public $name;
    public $parent;
    public $children;
    public $backRefs;

    abstract function fqn(): string;

    public function __construct(string $name, $start, $length) {
        parent::__construct($start, $length);
        $this->name = $name;
        $this->backRefs = new DynamicArray;
        $this->children = new DynamicArray;
    }

    public function addChild(Symbol $child) {
        $this->children->append($child);
        $child->parent = $this;
    }

    public function addBackRef(Reference $ref) {
        $this->backRefs->append($ref);
    }

    public function removeBackRef(Reference $ref) {
        $this->backRefs->erase($ref);
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
        return "<?php\n//".$this->fqn();
    }

    public function onReferenceDelete(Reference $ref) {
        $this->removeBackRef($ref);
    }

    public function onDelete(Repository $repo) {
        foreach($this->backRefs as $br) {
            $br->onSymbolDelete($repo);
        }
        foreach($this->children as $child) {
            $child->onDelete($repo);
        }
    }

    public function getReferenceAtOffset($offset) {
        foreach($this->children as $child) {
            $ref = $child->getReferenceAtOffset($offset);
            if ($ref) return $ref;
        }
        return null;
    }
}
