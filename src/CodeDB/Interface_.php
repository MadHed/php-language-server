<?php

namespace LanguageServer\CodeDB;

class Interface_ extends ClassLike {
    public $extends;

    public function __construct(string $name, $start, $length) {
        parent::__construct($name, $start, $length);
    }

    public function fqn(): string {
        return $this->parent->fqn().'\\'.strtolower($this->name);
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
                $offset >= $ext->getStart()
                && $offset <= $ext->getStart() + $ext->getLength()
            ) {
                return $ext;
            }
        }

        foreach($this->children as $child) {
            $ref = $child->getReferenceAtOffset($offset);
            if ($ref) return $ref;
        }

        return null;
    }

    public function findMethod($name, $call = null) {
        foreach($this->children as $member) {
            if ($member instanceof Function_) {
                if (strcasecmp($member->name, $name) === 0) {
                    return $member;
                }
                else if ($call === null && strcasecmp($member->name, '__call') === 0) {
                    $call = $member;
                }
                else if ($call === null && strcasecmp($member->name, '__callstatic') === 0) {
                    $call = $member;
                }
            }
        }
        if ($this->extends !== null) {
            foreach($this->extends as $ext) {
                if ($ext->target instanceof Interface_) {
                    $sym = $ext->target->findMethod($name, $call);
                    if ($sym !== null) {
                        return $sym;
                    }
                }
            }
        }
        return $call;
    }

    public function findConstant($name) {
        foreach($this->children as $member) {
            if ($member instanceof Constant) {
                if ($member->name === $name) {
                    return $member;
                }
            }
        }
        if ($this->extends !== null) {
            foreach($this->extends as $ext) {
                if ($ext->target instanceof Interface_) {
                    $sym = $ext->target->findConstant($name);
                    if ($sym !== null) {
                        return $sym;
                    }
                }
            }
        }
        return null;
    }
}
