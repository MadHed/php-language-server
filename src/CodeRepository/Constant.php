<?php

namespace LanguageServer\CodeRepository;

class Constant extends Symbol {
    private $parent;

    public function __construct(string $name) {
        $this->name = $name;
    }
}
