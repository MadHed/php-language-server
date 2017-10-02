<?php

namespace LanguageServer\CodeDB;

class Class_ extends Symbol {
    public $implements;
    public $extends;

    public function __construct(string $name, $start, $length) {
        parent::__construct($name, $start, $length);
    }

    public function fqn(): string {
        return $this->parent->fqn().'\\'.$this->name;
    }

    public function getDescription() {
        return 'class '.$this->fqn();
    }

    public function onDelete(Repository $repo) {
        echo "Class_::onDelete ", $this->name, "\n";
        parent::onDelete($repo);
        if ($this->extends) {
            $this->extends->onSymbolDelete($repo);
        }
        foreach($this->implements ?? [] as $impl) {
            $impl->onSymbolDelete($repo);
        }
    }
}
