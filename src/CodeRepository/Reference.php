<?php

namespace LanguageServer\CodeRepository;

class Reference implements \Serializable {
    public $file;
    public $range;
    public $target;

    public function __construct($file, $range, $target) {
        $this->file = $file;
        $this->range = $range;
        $this->target = $target;
    }

    public function serialize() {
        return json_encode([
            'f' => $this->file->name,
            'r' => $this->range,
            't' => \is_string($this->target) ? $this->target : $this->target->fqn()
        ]);
    }

    public function unserialize($str) {
        $values = json_decode($str);
        $this->file = $values->f;
        $this->range = new \Microsoft\PhpParser\Range(
            new \Microsoft\PhpParser\LineCharacterPosition($values->r->start->line, $values->r->start->character),
            new \Microsoft\PhpParser\LineCharacterPosition($values->r->end->line, $values->r->end->character)
        );
        $this->target = $values->t;
    }
}
