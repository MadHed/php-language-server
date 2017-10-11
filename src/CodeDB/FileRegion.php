<?php

namespace LanguageServer\CodeDB;

class FileRegion {
    private $range = 0; // 22:10 bitfield

    const START_BITS = 22;
    const LENGTH_BITS = 10;
    const START_MASK = 0b1111111111111111111111;
    const LENGTH_MASK = 0b1111111111;

    public function __construct($start, $length) {
        $this->range = 0;
        $this->setStart($start);
        $this->setLength($length);
    }

    public function getStart() {
        return $this->range >> self::LENGTH_BITS;
    }

    public function getLength() {
        return $this->range & self::LENGTH_MASK;
    }

    public function setStart($val) {
        $this->range = ($this->range & self::LENGTH_MASK) | (($val & self::START_MASK) << self::LENGTH_BITS);
    }

    public function setLength($val) {
        $this->range = ($this->range & ~self::LENGTH_MASK) | ($val & self::LENGTH_MASK);
    }
}
