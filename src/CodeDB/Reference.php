<?php

namespace LanguageServer\CodeDB;

class Reference {
    public $file;
    public $start;
    public $length;
    public $target;

    public function __construct($file, $start, $length, $target) {
        $this->file = $file;
        $this->start = $start;
        $this->length = $length;
        $this->target = $target;
    }

    public function getDescription() {
        $fqn = $this->target->fqn();
        if ($this->target instanceof \LanguageServer\CodeDB\Class_) {
            $text = "class {$fqn}";
        }
        else if ($this->target instanceof \LanguageServer\CodeDB\Interface_) {
            $text = "interface {$fqn}";
        }
        else if ($this->target instanceof \LanguageServer\CodeDB\Constant) {
            $text = "const {$fqn}";
        }
        else if ($this->target instanceof \LanguageServer\CodeDB\Function_) {
            $text = "function {$fqn}";
        }
        else if ($this->target instanceof \LanguageServer\CodeDB\Namespace_) {
            $text = "namespace {$fqn}";
        }
        else if ($this->target instanceof \LanguageServer\CodeDB\File) {
            $text = "File {$fqn}";
        }
        else if ($this->target instanceof \LanguageServer\CodeDB\Variable) {
            $text = "var {$fqn}";
        }
        else {
            $text = $fqn;
        }
    }
}
