<?php

namespace LanguageServer\CodeRepository;

class Namespace_ implements Symbol {
    private $name;
    private $files = [];

    public function __construct(string $name) {
        $name = trim($name, '\\');
        $this->name = $name;
    }

    public function getName(): string {
        return $this->name;
    }

    public function getFQN(): string {
        return ($this->name ? '\\' : '').$this->name;
    }

    public function addFile(File $file) {
        $this->files[$file->getName()] = $file;
        $file->parent = $this;
    }

    public function variables() {
        foreach ($this->files as $file) {
            yield from $file->variables();
        }
    }

    public function files() {
        return new ArrayIterator($this->files);
    }
}
