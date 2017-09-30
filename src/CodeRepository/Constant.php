<?php

namespace LanguageServer\CodeRepository;

class Constant extends Symbol {
    public function __construct(string $name) {
        $this->name = $name;
    }
}
