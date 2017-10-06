<?php

namespace LanguageServer\CodeDB;

class Class_ extends Symbol {
    public $implements;
    public $extends;

    public function __construct(string $name, $start, $length) {
        parent::__construct($name, $start, $length);
    }

    public function fqn(): string {
        return $this->parent->fqn().'\\'.\strtolower($this->name);
    }

    public function getDescription() {
        return "<?php\nclass ".$this->fqn();
    }

    public function onDelete(Repository $repo) {
        parent::onDelete($repo);
        if ($this->extends) {
            $this->extends->onSymbolDelete($repo);
        }
        foreach($this->implements ?? [] as $impl) {
            $impl->onSymbolDelete($repo);
        }
    }

    public function getReferenceAtOffset($offset) {
        if (
            $this->extends
            && $offset >= $this->extends->getStart()
            && $offset <= $this->extends->getStart() + $this->extends->getLength()
        ) {
            return $this->extends;
        }

        foreach($this->implements ?? [] as $impl) {
            if (
                $offset >= $impl->getStart()
                && $offset <= $impl->getStart() + $impl->getLength()
            ) {
                return $impl;
            }
        }

        foreach($this->children ?? [] as $child) {
            $ref = $child->getReferenceAtOffset($offset);
            if ($ref) return $ref;
        }

        return null;
    }

}
