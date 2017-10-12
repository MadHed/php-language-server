<?php

namespace LanguageServer\CodeDB;

class DynamicArray extends \SplFixedArray {
    public function __construct($size = 0) {
        parent::__construct($size);
    }

    public function erase($element) {
        foreach($this as $i => $e) {
            if ($e === $element) {
                $this[$i] = $this[$this->count()-1];
                $this->setSize($this->count()-1);
                return true;
            }
        }
        return false;
    }

    public function append($element) {
        $this->setSize($this->count()+1);
        $this[$this->count()-1] = $element;
    }
}
