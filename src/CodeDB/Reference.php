<?php

namespace LanguageServer\CodeDB;

class Reference {
    public $file;
    public $range;
    public $target;

    public function __construct($file, $start, $length, $target) {
        $this->file = $file;
        $this->range = 0;
        $this->setStart($start);
        $this->setLength($length);
        $this->target = $target;
    }

    public function getDescription() {
        if (is_string($this->target)) {
            return $this->target;
        }
        else {
            return $this->target->getDescription();
        }
    }

    public function onSymbolDelete(Repository $repo) {
        $repo->addUnresolvedReference($this);
    }

    public function onDelete(Repository $repo) {
        if ($this->target instanceof Symbol) {
            $this->target->onReferenceDelete($this);
        }
        else {
            $repo->removeUnresolvedReference($this);
        }
    }

    public function isResolved() {
        return $this->target instanceof Symbol;
    }

    public function isUnresolved() {
        return !$this->isResolved();
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
