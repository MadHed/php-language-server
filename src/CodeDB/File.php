<?php

namespace LanguageServer\CodeDB;

class Diagnostic {
    public $kind;
    public $message;
    public $startLine;
    public $startCharacter;
    public $endLine;
    public $endCharacter;

    public function __construct($kind, $message, $startLine, $startCharacter, $endLine, $endCharacter)
    {
        $this->kind = $kind;
        $this->message = $message;
        $this->startLine = $startLine;
        $this->startCharacter = $startCharacter;
        $this->endLine = $endLine;
        $this->endCharacter = $endCharacter;
    }
}
class File extends Symbol {
    private $hash;
    public $parseTime = 0;
    public $diagnostics;

    public function __construct(string $name, string $content) {
        parent::__construct($name);
        $this->hash = \hash('SHA256', $content);
    }

    public function fqn(): string {
        return $this->name;
    }

    public function hash() {
        return $this->hash;
    }

    public function getSymbolAtPosition($line, $character) {
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
                            }
                        }
                    }
                }
            }
        }

        foreach($symbols as $sym) {
            if (!(($line === $sym->range->start->line && $character < $sym->range->start->character)
                || ($line === $sym->range->end->line && $character > $sym->range->end->character)
                || ($line < $sym->range->start->line || $line > $sym->range->end->line))
            ) {
                return $sym;
            }
        }
        return null;
    }

    public function addDiagnostic($diag) {
        $this->diagnostics[] = $diag;
    }

}
