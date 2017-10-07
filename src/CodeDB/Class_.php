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

    public function findField($name, $get = null) {
        foreach($this->children ?? [] as $member) {
            if ($member instanceof Variable && $member->name === $name) {
                return $member;
            }
        }
        if ($this->extends !== null && $this->extends->target instanceof Class_) {
            return $this->extends->target->findField($name);
        }
        return null;
    }

    public function findMethod($name, $call = null) {
        foreach($this->children ?? [] as $member) {
            if ($member instanceof Function_) {
                if ($member->name === $name) {
                    return $member;
                }
                else if ($call === null && strtolower($member->name) === '__call') {
                    $call = $member;
                }
                else if ($call === null && strtolower($member->name) === '__callstatic') {
                    $call = $member;
                }
            }
        }
        if ($this->extends !== null && $this->extends->target instanceof Class_) {
            return $this->extends->target->findMethod($name, $call);
        }
        return $call;
    }

    public function findConstant($name) {
        foreach($this->children ?? [] as $member) {
            if ($member instanceof Constant && $member->name === $name) {
                return $member;
            }
        }
        if ($this->extends !== null && $this->extends->target instanceof Class_) {
            return $this->extends->target->findConstant($name);
        }
        return null;
    }

}
