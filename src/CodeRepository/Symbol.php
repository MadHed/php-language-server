<?php

namespace LanguageServer\CodeRepository;

abstract class Symbol {
    public $file;
    public $name;
    public $range;

    abstract function fqn(): string;
}
