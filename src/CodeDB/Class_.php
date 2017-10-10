<?php

namespace LanguageServer\CodeDB;

class Class_ extends ClassLike {
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

        foreach($this->children as $child) {
            $ref = $child->getReferenceAtOffset($offset);
            if ($ref) return $ref;
        }

        return null;
    }

    public function findField($name, $get = null) {
        foreach($this->children as $member) {
            if ($member instanceof Variable && $member->name === $name) {
                return $member;
            }
        }
        if ($this->extends !== null && $this->extends->target instanceof Class_) {
            $sym = $this->extends->target->findField($name);
            if ($sym !== null) {
                return $sym;
            }
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
        if ($this->extends !== null && $this->extends->target instanceof Class_) {
            $sym = $this->extends->target->findMethod($name, $call);
            if ($sym !== null) {
                return $sym;
            }
        }
        if ($this->implements !== null) {
            foreach($this->implements as $impl) {
                if ($impl->target instanceof Interface_) {
                    $sym = $impl->target->findMethod($name, $call);
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
            if ($member instanceof Constant && $member->name === $name) {
                return $member;
            }
        }
        if ($this->extends !== null && $this->extends->target instanceof Class_) {
            $sym = $this->extends->target->findConstant($name);
            if ($sym !== null) {
                return $sym;
            }
        }
        if ($this->implements !== null) {
            foreach($this->implements as $impl) {
                if ($impl->target instanceof Interface_) {
                    $sym = $impl->target->findConstant($name);
                    if ($sym !== null) {
                        return $sym;
                    }
                }
            }
        }
        return null;
    }

}
