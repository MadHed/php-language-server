<?php

namespace LanguageServer\CodeRepository;

abstract class Symbol {
    public $name;
    public $range;
    public $parent;

    abstract function fqn(): string;
}
