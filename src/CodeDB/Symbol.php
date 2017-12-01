<?php

namespace LanguageServer\CodeDB;

use LanguageServer\Protocol\Range;
use LanguageServer\Protocol\Position;

class Symbol {
    public $id;
    public $parent_id;
    public $type;
    public $description;
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

    public function getDescription() {
        $descs = [];
        foreach(explode("\n", $this->description) as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            $descs[] = $line;
        }
        $desc = implode("\n", $descs);


        switch($this->type) {
            case self::_VARIABLE: $name = '$'; break;
            case self::_NAMESPACE: $name = 'namespace '; break;
            case self::_CLASS: $name = 'class '; break;
            case self::_FUNCTION: $name = 'function '; break;
            case self::_INTERFACE: $name = 'interface '; break;
            case self::_TRAIT: $name = 'trait '; break;
            case self::_CONSTANT: $name = 'const '; break;
            default: $name = '';
        }
        $name .= $this->name;
        return "<?php\n".$desc."\n".$name;
    }
}
