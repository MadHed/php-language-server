<?php

namespace LanguageServer\CodeDB;

abstract class Symbol {
    public $name;
    public $range;
    public $parent;
    public $children;
    public $backRefs;

    abstract function fqn(): string;

    public function __construct(string $name, $start, $length) {
        $this->name = $name;
        $this->range = 0;
        $this->setStart($start);
        $this->setLength($length);
    }

    public function addChild(Symbol $child) {
        $this->children[$child->name] = $child;
        $child->parent = $this;
    }

    public function addBackRef(Reference $ref) {
        $this->backRefs[] = $ref;
    }

    public function removeBackRef(Reference $ref) {
        foreach($this->backRefs ?? [] as $i => $br) {
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
        return "<?php\n//".$this->fqn();
    }

    public function onReferenceDelete(Reference $ref) {
        $this->removeBackRef($ref);
    }

    public function onDelete(Repository $repo) {
        foreach($this->backRefs ?? [] as $br) {
            $br->onSymbolDelete($repo);
        }
        foreach($this->children ?? [] as $child) {
            $child->onDelete($repo);
        }
    }

    public function getReferenceAtOffset($offset) {
        foreach($this->children ?? [] as $child) {
            $ref = $child->getReferenceAtOffset($offset);
            if ($ref) return $ref;
        }
        return null;
    }

    public function getStart() {
        return $this->range & 0xffffffff;
    }

    public function getLength() {
        return $this->range >> 32;
    }

    public function setStart($val) {
        $this->range = $this->range & 0xffffffff00000000 | ($val & 0xffffffff);
    }

    public function setLength($val) {
        $this->range = $this->range & 0xffffffff | ($val << 32);
    }
}
