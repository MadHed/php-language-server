<?php

namespace LanguageServer\CodeDB;

class Symbol {
    public $id;
    public $parent_id;
    public $type;
    public $name;
    public $fqn;
    public $file_id;
    public $range_start_line;
    public $range_start_character;
    public $range_end_line;
    public $range_end_character;

    const _NAMESPACE = 1;
    const _CLASS = 2;
    const _FUNCTION = 3;
    const _INTERFACE = 4;
    const _VARIABLE = 5;
    const _TRAIT = 6;
    const _CONSTANT = 7;
}
