<?php

namespace LanguageServer\CodeRepository;

require 'vendor/autoload.php';

class SerializationState {
    public $id = 1;
    public $refs = [];
    public function addRef($obj) {
        $this->refs[\spl_object_hash($obj)] = $this->id;
    }
    public function getRef($obj) {
        $hash = \spl_object_hash($obj);
        if (array_key_exists($hash, $this->refs)) {
            return $this->refs[$hash];
        }
        return -1;
    }
}

function serialize($value, $state = null) {
    if ($state === null) {
        $state = new SerializationState();
    }

    if ($value === null) {
        return 'N;';
    }
    else if ($value === false) {
        return 'b:0;';
    }
    else if ($value === true) {
        return 'b:1;';
    }
    else if (\is_int($value)) {
        return "i:$value;";
    }
    else if (\is_float($value)) {
        return "d:$value;";
    }
    else if (\is_string($value)) {
        return "s:".\strlen($value).":\"".$value."\";";
    }
    else if (\is_array($value)) {
        $str = "a:".\count($value).":{";
        foreach($value as $k => $v) {
            $str .= serialize($k, $state);
            $state->id++;
            $str .= serialize($v, $state);
        }
        return $str . "}";
    }
    else if (\is_object($value)) {
        $id = $state->getRef($value);
        if ($id >= 0) {
            return "r:$id;";
        }
        else {
            $state->addRef($value);
            $cls = \get_class($value);
            $str = "O:".\strlen($cls).":\"".$cls."\":";
            $ref = new \ReflectionClass($value);
            $refs[] = $ref;
            while($ref = $ref->getParentClass()) {
                $refs[] = $ref;
            }

            $numProps = 0;
            foreach($refs as $ref) {
                $props = $ref->getProperties();
                foreach($props as $prop) {
                    if (!$prop->isStatic()) $numProps++;
                }
            }

            $str .= "$numProps:{";

            $visitedProps = [];
            foreach($refs as $ref) {
                $className = $ref->getName();
                $props = $ref->getProperties();
                foreach($props as $prop) {
                    if ($prop->isStatic()) continue;
                    $prop->setAccessible(true);
                    $state->id++;
                    if ($prop->isPrivate()) {
                        $str .= serialize("\0".$className."\0".$prop->getName(), $state);
                        $str .= serialize($prop->getValue($value), $state);
                    }
                    else if (!isset($visitedProps[$prop->getName()])) {
                        if ($prop->isProtected()) {
                            $str .= serialize("\0*\0".$prop->getName(), $state);
                        }
                        else {
                            $str .= serialize($prop->getName(), $state);
                        }
                        $str .= serialize($prop->getValue($value), $state);
                        $visitedProps[$prop->getName()] = 1;
                    }
                }
            }
            $str .= "}";
            return $str;
        }
    }
}

function unserialize($string) {
}

class Foo {
    public $a;
    public $b;
    public $c;
    public $r;
    public function __construct() {
        $this->r = $this;
    }
}
$val = [new Foo(), new Foo()];

$a = \serialize($val);
$b = serialize($val);
echo "$a\n";
echo "$b\n";
var_dump($a === $b);
