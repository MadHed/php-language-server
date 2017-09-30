<?php

namespace LanguageServer\CodeRepository;

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
        return new VariablesIterator($this);
    }

    public function functions() {
        return new FunctionsIterator($this);
    }

    public function constants() {
        return new ConstantsIterator($this);
    }

    public function classes() {
        return new ClassesIterator($this);
    }

    public function interfaces() {
        return new InterfacesIterator($this);
    }

    public function files() {
        return new FilesIterator($this);
    }

    public function namespaces() {
        return new NamespacesIterator($this);
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

    abstract public function gen();
}

class FilesIterator extends IteratorBase {
    public function gen() {
        foreach($this->base->gen() as $element) {
            yield from $element->files()->gen();
        }
    }
}

class VariablesIterator extends IteratorBase {
    public function gen() {
        foreach($this->base->gen() as $element) {
            yield from $element->variables()->gen();
        }
    }
}

class FunctionsIterator extends IteratorBase {
    public function gen() {
        foreach($this->base->gen() as $element) {
            yield from $element->functions()->gen();
        }
    }
}

class ConstantsIterator extends IteratorBase {
    public function gen() {
        foreach($this->base->gen() as $element) {
            yield from $element->constants()->gen();
        }
    }
}

class ClassesIterator extends IteratorBase {
    public function gen() {
        foreach($this->base->gen() as $element) {
            yield from $element->classes()->gen();
        }
    }
}

class InterfacesIterator extends IteratorBase {
    public function gen() {
        foreach($this->base->gen() as $element) {
            yield from $element->interfaces()->gen();
        }
    }
}

class NamespacesIterator extends IteratorBase {
    public function gen() {
        foreach($this->base->gen() as $element) {
            yield from $element->namespaces()->gen();
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

class Reference {
    public $file;
    public $range;
    public $target;

    public function __construct($file, $range, $target) {
        $this->file = $file;
        $this->range = $range;
        $this->target = $target;
    }
}

class Repository {
    public $files = [];
    public $references = [];

    public function files() {
        return new ArrayIterator($this->files);
    }
}
