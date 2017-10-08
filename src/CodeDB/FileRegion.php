<?php

namespace LanguageServer\CodeDB;

class FileRegion {
    public $start;
    public $length;

    public function __construct($start, $length) {
        $this->start = $start;
        $this->length = $length;
    }

    public function getStart() {
        return $this->start;
    }

    public function getLength() {
        return $this->length;
    }

    public function setStart($val) {
        $this->start = $val;
    }

    public function setLength($val) {
        $this->length = $val;
    }
}
