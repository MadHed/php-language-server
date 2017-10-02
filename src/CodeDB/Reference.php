<?php

namespace LanguageServer\CodeDB;

class Reference {
    public $file;
    public $start;
    public $length;
    public $target;

    public function __construct($file, $start, $length, $target) {
        $this->file = $file;
        $this->start = $start;
        $this->length = $length;
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
        echo "Reference::onSymbolDelete", "\n";
        $this->target = $this->target->fqn();
        $repo->references[] = $this;
    }

    public function onDelete(Repository $repo) {
        echo "Reference::onDelete", "\n";
        if ($this->target instanceof Symbol) {
            $this->target->onReferenceDelete($this);
        }

        foreach ($repo->references as $key => $ref) {
            if ($ref === $this) {
                echo "Symbol::onDelete - unset", "\n";
                unset($repo->references[$key]);
                break;
            }
        }
    }
}
