<?php

namespace LanguageServer\CodeDB;

class Function_ extends Symbol {
    public function __construct(string $name, $start, $length) {
        parent::__construct($name, $start, $length);
    }

    public function fqn(): string {
        if ($this->parent instanceof Namespace_) {
            return $this->parent->fqn().'\\'.\strtolower($this->name).'()';
        }
        else if ($this->parent instanceof Class_ || $this->parent instanceof Interface_) {
            return $this->parent->fqn().'::'.\strtolower($this->name).'()';
        }
        else {
            return $this->parent->fqn().'@'.\strtolower($this->name).'()';
        }
    }

    public function getDescription() {
        return "<?php\nfunction ".$this->fqn();
    }
}
