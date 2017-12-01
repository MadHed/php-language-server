<?php

namespace LanguageServer\CodeDB;

use LanguageServer\Protocol\Range;
use LanguageServer\Protocol\Position;

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

    public function getRange() {
        return new Range(
            new Position(
                (int)$this->range_start_line,
                (int)$this->range_start_character
            ),
            new Position(
                (int)$this->range_end_line,
                (int)$this->range_end_character
            )
        );
    }
}
