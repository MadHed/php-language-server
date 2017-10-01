<?php

namespace LanguageServer\CodeDB;

class Reference {
    public $file;
    public $range;
    public $target;

    public function __construct($file, $range, $target) {
        $this->file = $file;
        $this->range = $range;
        $this->target = $target;
    }
}
