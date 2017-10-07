<?php

namespace LanguageServer\CodeDB;

use LanguageServer\Protocol\{Range, Position};

class Diagnostic {
    public $kind;
    public $message;
    public $start;
    public $length;

    public function __construct($kind, $message, $start, $length)
    {
        $this->kind = $kind;
        $this->message = $message;
        $this->start = $start;
        $this->length = $length;
    }

    public function getRange(File $file) {
        return $file->getRange($this->start, $this->length);
    }
}

class File extends Symbol {
    private $hash;
    public $parseTime = 0;
    public $lineOffsets;
    public $diagnostics;
    public $references;
    public $lineOffsets;

    public function __construct(string $name, string $content) {
        parent::__construct($name, 0, strlen($content));
        $this->hash = \hash('SHA256', $content);

        $this->lineOffsets[] = 0;
        $offset = 1;
        $len = strlen($content);
        while ($offset < $len && ($offset = \strpos($content, "\n", $offset)) !== false) {
            $this->lineOffsets[] = $offset + 1;
            $offset++;
        }
    }

    public function getRange($start, $length) {
        return new Range(
            $this->getPosition($start),
            $this->getPosition($start + $length)
        );
    }

    public function getPosition($offset) {
        if ($offset < 0) return new Position(0, 0);

        $num = count($this->lineOffsets);
        for($i = 0; $i < $num; $i++) {
            if ($this->lineOffsets[$i] > $offset) {
                return new Position($i - 1, $offset - $this->lineOffsets[$i - 1]);
            }
        }
        return new Position($num - 1, $offset - $this->lineOffsets[$num - 1]);
    }

    public function fqn(): string {
        return $this->name;
    }

    public function hash() {
        return $this->hash;
    }

    public function positionToOffset($line, $character) {
        if ($line < 0) return 0;
        $num = count($this->lineOffsets);
        if ($line >= $num) return $this->lineOffsets[$num-1];

        return $this->lineOffsets[$line] + $character;
    }

    public function getReferenceAtPosition($line, $character) {
        $offset = $this->positionToOffset($line, $character);

        foreach($this->references ?? [] as $ref) {
            if ($offset >= $ref->getStart() && $offset <= $ref->getStart() + $ref->getLength()) {
                return $ref;
            }
        }

        foreach($this->children ?? [] as $child) {
            $ref = $child->getReferenceAtOffset($offset);
            if ($ref) return $ref;
        }
        return null;
    }

    public function getSymbolAtPosition($line, $character) {
        $offset = $this->positionToOffset($line, $character);

        $symbols = [];
        if (is_array($this->children)) {
            foreach($this->children as $ns) {
                $symbols[] = $ns;
                if (is_array($ns->children)) {
                    foreach($ns->children as $sym) {
                        $symbols[] = $sym;
                        if (is_array($sym->children)) {
                            foreach($sym->children as $syms) {
                                $symbols[] = $syms;
                                if (is_array($syms->children)) {
                                    foreach($syms->children as $symss) {
                                        $symbols[] = $symss;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        foreach($symbols as $sym) {
            if ($offset >= $sym->getStart() && $offset <= $sym->getStart() + $sym->getLength()) {
                return $sym;
            }
        }
        return null;
    }

    public function addDiagnostic($diag) {
        $this->diagnostics[] = $diag;
    }

    public function getDescription() {
        return "<?php\n//File: ".$this->fqn();
    }

    public function onDelete(Repository $repo) {
        parent::onDelete($repo);
        foreach($this->references ?? [] as $ref) {
            $ref->onDelete($repo);
        }
    }
}
