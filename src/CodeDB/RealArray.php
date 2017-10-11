<?php

namespace LanguageServer\CodeDB;

class RealArray extends \SplFixedArray {
    public function __construct($size = 0) {
        parent::__construct($size);
    }

    public function erase($el) {
        foreach($this as $i => $e) {
            if ($e === $el) {
                $this[$i] = $this[$this->count() - 1];
                $this->setSize($this->count()-1);
                return;
            }
        }
    }

    public function append($el) {
        $this->setSize($this->count()+1);
        $this[$this->count()-1] = $el;
    }
}
