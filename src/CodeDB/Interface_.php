<?php

namespace LanguageServer\CodeDB;

class Interface_ extends Symbol {
    public $extends;

    public function __construct(string $name, $start, $length) {
        parent::__construct($name, $start, $length);
    }

    public function fqn(): string {
        return $this->parent->fqn().'\\'.$this->name;
    }

    public function getDescription() {
        return "<?php\ninterface ".$this->fqn();
    }

    public function onDelete(Repository $repo) {
        parent::onDelete($repo);
        foreach($this->extends ?? [] as $ext) {
            $ext->onSymbolDelete($repo);
        }
    }

    public function getReferenceAtOffset($offset) {
        foreach($this->extends ?? [] as $ext) {
            if (
                $offset >= $ext->start
                && $offset <= $ext->start + $ext->length
            ) {
                return $ext;
            }
        }

        foreach($this->children ?? [] as $child) {
            $ref = $child->getReferenceAtOffset($offset);
            if ($ref) return $ref;
        }

        return null;
    }
}
