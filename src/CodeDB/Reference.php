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
}
