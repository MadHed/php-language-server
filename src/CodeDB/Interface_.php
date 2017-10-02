<?php

namespace LanguageServer\CodeDB;

class Interface_ extends Symbol {
    public function __construct(string $name, $start, $length) {
        parent::__construct($name, $start, $length);
    }

    public function fqn(): string {
        return $this->parent->fqn().'\\'.$this->name;
    }

    public function getDescription() {
        return 'interface '.$this->fqn();
    }

    public function onDelete(Repository $repo) {
        echo "Interface_::onDelete ", $this->name, "\n";
        parent::onDelete($repo);
        foreach($this->extends ?? [] as $ext) {
            $ext->onSymbolDelete($repo);
        }
    }
}
