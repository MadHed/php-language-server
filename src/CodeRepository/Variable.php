<?php

namespace LanguageServer\CodeRepository;

class Variable implements Symbol {
    /**
     * @var File|Class_|Function_
     */
    public $parent;

    private $name;

    public function __construct(string $name) {
        $this->name = $name;
    }

    public function getName(): string {
        return $this->name;
    }

    public function getFQN(): string {
        if ($this->parent instanceof File) {
            return $this->parent->getNamespace()->getFQN().'\\'.$this->name;
        }
        else if ($this->parent instanceof Symbol) {
            return $this->parent->getFQN().'::'.$this->name;
        }
    }
}
