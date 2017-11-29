<?php

namespace LanguageServer\CodeDB;

class Reference {
    public $id;
    public $type;
    public $file_id;
    public $fqn;
    public $symbol_id;
    public $range_start_line;
    public $range_start_character;
    public $range_end_line;
    public $range_end_character;
}
