<?php

namespace LanguageServer\CodeDB;

class Cache {

    private $file;

    public function __construct($filename) {
        $this->file = fopen($filename, 'rb');
    }

    public function read() {
        $repo = new Repository();
        return $repo;
    }

    public function write($repo) {
    }

    public function writeNumber($v) {
    }

    public function readNumber() {
    }

    public function writeString($v) {
    }

    public function readString() {
    }
}
