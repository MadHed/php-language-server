<?php

namespace LanguageServer\CodeDB;

class Reference extends FileRegion {
    public $file;
    public $target;

    public function __construct($file, $start, $length, $target) {
        parent::__construct($start, $length);
        $this->file = $file;
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
}
