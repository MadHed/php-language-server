<?php

namespace LanguageServer\CodeDB;

class Constant extends Symbol {
    public function __construct(string $name, $start, $length) {
        parent::__construct($name, $start, $length);
    }

    public function fqn(): string {
        if ($this->parent instanceof Namespace_) {
            return $this->parent->fqn().'\\#'.$this->name;
        }
        else if ($this->parent instanceof ClassLike) {
            return $this->parent->fqn().'::#'.$this->name;
        }
        else {
            return $this->parent->fqn().'#'.$this->name;
        }
    }

    public function getDescription() {
        return "<?php\nconst ".$this->fqn();
    }
}
