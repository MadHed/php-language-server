<?php

namespace LanguageServer\CodeDB;

function makeSelectorFunction($s) {
    if (\is_string($s)) {
        if (\substr($s, -2) === '()') {
            $s = substr($s, 0, -2);
            return function ($o) use ($s) {
                return $o->$s();
            };
        }
        else {
            return function ($o) use ($s) {
                return $o->$s;
            };
        }
    }
    else if (\is_callable($s)) {
        return $s;
    }
    else {
        throw new \Exception('Invalid selector');
    }
}

abstract class IteratorBase {
    protected $base;

    public function __construct($base) {
        $this->base = $base;
    }

    public function variables() {
        return new ChildrenIterator($this, Variable::class);
    }

    public function functions() {
        return new ChildrenIterator($this, Function_::class);
    }

    public function constants() {
        return new ChildrenIterator($this, Constant::class);
    }

    public function classes() {
        return new ChildrenIterator($this, Class_::class);
    }

    public function interfaces() {
        return new ChildrenIterator($this, Interface_::class);
    }

    public function namespaces() {
        return new ChildrenIterator($this, Namespace_::class);
    }

    public function filter($predicate) {
        return new FilterIterator($this, $predicate);
    }

    public function limit(int $limit) {
        return new LimitIterator($this, $limit);
    }

    public function sort($comparer) {
        return new SortIterator($this, $comparer);
    }

    public function select($selector) {
        return new SelectIterator($this, makeSelectorFunction($selector));
    }

    public function sortBy($selector) {
        $selector = makeSelectorFunction($selector);
        return new SortIterator($this, function($a, $b) use ($selector) {
            return ($selector)($a) <=> ($selector)($b);
        });
    }

    public function find($selector) {
        foreach($this->gen() as $el) {
            if (($selector)($el)) {
                return $el;
            }
        }
        return null;
    }

    public function count() {
        $c = 0;
        foreach($this->gen() as $el) {
            $c++;
        }
        return $c;
    }

    public function implode($glue) {
        $str = '';
        $first = true;
        foreach($this->gen() as $el) {
            if (!$first) {
                $str .= $glue;
            }
            $str .= $el;
            $first = false;
        }
        return $str;
    }

    abstract public function gen();
}

class ChildrenIterator extends IteratorBase {
    private $type;

    public function __construct($base, $type) {
        parent::__construct($base);
        $this->type = $type;
    }

    public function gen() {
        foreach($this->base->gen() as $element) {
            if (isset($element->children)) {
                foreach($element->children as $child) {
                    if ($child instanceof $this->type) {
                        yield $child;
                    }
                }
            }
        }
    }
}

class SelectIterator extends IteratorBase {
    private $selector;

    public function __construct($base, $selector) {
        parent::__construct($base);
        $this->selector = $selector;
    }

    public function gen() {
        foreach($this->base->gen() as $element) {
            yield ($this->selector)($element);
        }
    }
}

class ArrayIterator extends IteratorBase {
    public function gen() {
        yield from $this->base;
    }
}

class MultiIterator extends IteratorBase {
    public function gen() {
        foreach($this->base as $el) {
            yield from $el->gen();
        }
    }
}

class FilterIterator extends IteratorBase {
    private $predicate;

    public function __construct($base, callable $predicate) {
        parent::__construct($base);
        $this->predicate = $predicate;
    }

    public function gen() {
        foreach($this->base->gen() as $el) {
            if (($this->predicate)($el)) {
                yield $el;
            }
        }
    }
}

class SortIterator extends IteratorBase {
    private $comparer;

    public function __construct($base, $comparer) {
        parent::__construct($base);
        $this->comparer = $comparer;
    }

    public function gen() {
        $elements = [];
        foreach($this->base->gen() as $el) {
            $elements[] = $el;
        }
        \usort($elements, $this->comparer);
        yield from $elements;
    }
}

class LimitIterator extends IteratorBase {
    private $limit;

    public function __construct($base, int $limit) {
        parent::__construct($base);
        $this->limit = $limit;
    }

    public function gen() {
        $c = 0;
        foreach($this->base->gen() as $el) {
            if ($c++ == $this->limit) {
                break;
            }
            yield $el;
        }
    }
}


function nameContains($search) {
    return function ($c) use ($search) {
        return stripos($c->name, $search) !== false;
    };
}

function fqnEquals($search) {
    return function ($c) use ($search) {
        return $c->fqn() === $search;
    };
}

function nameEquals($search) {
    return function ($c) use ($search) {
        return $c->name === $search;
    };
}

function byName() {
    return function ($a, $b) {
        return $a->name <=> $b->name;
    };
}

function byFQN() {
    return function ($a, $b) {
        return $a->fqn() <=> $b->fqn();
    };
}

class Repository {
    public $files = [];
    public $references = [];
    public $fqnMap = [];

    public function files() {
        return new ArrayIterator($this->files);
    }
}
