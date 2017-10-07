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

function makeComparerFunction($s) {
    if (\is_string($s)) {
        $selector = makeSelectorFunction($s);
        return function ($a, $b) use ($selector) {
            return ($selector)($a) <=> ($selector)($b);
        };
    }
    else if (\is_callable($s)) {
        return $s;
    }
    else {
        throw new \Exception('Invalid selector');
    }
}

abstract class IteratorBase implements \Iterator {
    protected $base;
    private $generator;

    public function __construct($base) {
        $this->base = $base;
        $this->generator = $this->gen();
    }

    public function current() {
        return $this->generator->current();
    }

    public function key() {
        return $this->generator->key();
    }

    public function next() {
        return $this->generator->next();
    }

    public function rewind() {
        $this->generator->rewind();
    }

    public function valid() {
        return $this->generator->valid();
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

    public function symbols() {
        return new ChildrenIterator($this, Symbol::class);
    }

    public function filter($predicate) {
        return new FilterIterator($this, $predicate);
    }

    public function limit(int $limit) {
        return new LimitIterator($this, $limit);
    }

    public function sort($comparer) {
        return new SortIterator($this, makeComparerFunction($comparer));
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
        foreach($this as $el) {
            if (($selector)($el)) {
                return $el;
            }
        }
        return null;
    }

    public function count() {
        $c = 0;
        foreach($this as $el) {
            $c++;
        }
        return $c;
    }

    public function implode($glue) {
        $str = '';
        $first = true;
        foreach($this as $el) {
            if (!$first) {
                $str .= $glue;
            }
            $str .= $el;
            $first = false;
        }
        return $str;
    }

    public function sum() {
        $sum = 0;
        foreach($this as $el) {
            $sum += $el;
        }
        return $sum;
    }

    public function avg() {
        $sum = 0;
        $count = 0;
        foreach($this as $el) {
            $sum += $el;
            $count++;
        }
        if ($count === 0) return null;
        return $sum/$count;
    }

    public function max() {
        $max = PHP_INT_MIN;
        foreach($this as $el) {
            if ($el > $max) $max = $el;
        }
        if ($max === PHP_INT_MIN) return null;
        return $max;
    }

    public function min() {
        $min = PHP_INT_MAX;
        foreach($this as $el) {
            if ($el < $min) $min = $el;
        }
        if ($min === PHP_INT_MAX) return null;
        return $min;
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
        foreach($this->base as $element) {
            if (is_array($element->children)) {
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
        foreach($this->base as $element) {
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

    public function __construct(...$sources) {
        parent::__construct($sources);
    }

    public function gen() {
        foreach($this->base as $el) {
            yield from $el;
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
        foreach($this->base as $el) {
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

    public function from($db) {
        $this->files = $db->files;
        $this->references = $db->references;
        $this->fqnMap = $db->fqnMap;
    }

    public function resolveReferences() {
        $start = microtime(true);
        // first resolve non-members
        foreach($this->references as $fqn => $refs) {
            if (strpos($fqn, '::') === false) {
                if (isset($this->fqnMap[$fqn])) {
                    $target = $this->fqnMap[$fqn];
                    foreach($refs as $ref) {
                        $ref->target = $target;
                        $ref->target->addBackRef($ref);
                    }
                    unset($this->references[$fqn]);
                }
                else if (strpos($fqn, '()') !== false) {
                    // function
                    $pos = strrpos($fqn, '\\');
                    if ($pos > 0) {
                        $newfqn = substr($fqn, $pos);
                        if (isset($this->fqnMap[$newfqn])) {
                            $target = $this->fqnMap[$newfqn];
                            foreach($refs as $ref) {
                                $ref->target = $target;
                                $ref->target->addBackRef($ref);
                            }
                            unset($this->references[$fqn]);
                        }
                    }
                }
            }
        }

        // then resolve members
        foreach($this->references as $fqn => $refs) {
            if (strpos($fqn, '::') !== false) {
                if (isset($this->fqnMap[$fqn])) {
                    $target = $this->fqnMap[$fqn];
                    foreach($refs as $ref) {
                        $ref->target = $target;
                        $ref->target->addBackRef($ref);
                    }
                    unset($this->references[$fqn]);
                }
                else {
                    // is class member
                    $parts = explode('::', $fqn, 2);
                    $clsName = $parts[0];
                    $symName = $parts[1];
                    if (isset($this->fqnMap[$clsName])) {
                        $cls = $this->fqnMap[$clsName];
                        if (!$cls instanceof Class_) {
                            continue;
                        }
                    }
                    else {
                        continue;
                    }

                    if (substr($symName, 0, 1) === '$') {
                        // field
                        $symName = substr($symName, 1);
                        $found = $cls->findField($symName);
                    }
                    else if (substr($symName, -2) === '()') {
                        // method
                        $symName = substr($symName, 0, -2);
                        $found = $cls->findMethod($symName);
                    }
                    else {
                        // const
                        $symName = substr($symName, 1);
                        $found = $cls->findConstant($symName);
                    }

                    if ($found) {
                        foreach($refs as $ref) {
                            $ref->target = $found;
                            $ref->target->addBackRef($ref);
                        }
                        unset($this->references[$fqn]);
                    }
                }
            }
        }
    }

    public function addUnresolvedReference(Reference $ref) {
        $fqn = $ref->target instanceof Symbol ? $ref->target->fqn() : $ref->target;
        if (!isset($this->references[$fqn])) {
            $this->references[$fqn] = [];
        }
        $this->references[$fqn][] = $ref;
        $ref->target = $fqn;
    }

    public function removeUnresolvedReference(Reference $ref) {
        $fqn = $ref->target instanceof Symbol ? $ref->target->fqn() : $ref->target;
        if (!isset($this->references[$fqn])) return;
        foreach($this->references[$fqn] as $i => $r) {
            if ($r === $ref) {
                unset($this->references[$fqn][$i]);
                return;
            }
        }
    }

    public function removeFile(string $uri) {
        $start = microtime(true);
        if (!isset($this->files[$uri])) {
            return;
        }

        $file = $this->files[$uri];
        $file->onDelete($this);
        unset($this->files[$uri]);
    }

    public function getUnresolvedReferenceCount() {
        $c = 0;
        foreach($this->references as $refs) {
            $c += count($refs);
        }
        return $c;
    }
}
