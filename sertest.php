<?php

namespace LanguageServer\CodeRepository;

require_once 'vendor/autoload.php';

class SerializationState {
    public $id = 1;
    public $refs = [];
    public $objs = [];
    public $pos = 0;
    public $reflClasses = [];

    public function addRef($obj) {
        $this->refs[\spl_object_hash($obj)] = $this->id;
    }

    public function getRef($obj) {
        $hash = \spl_object_hash($obj);
        if (isset($this->refs[$hash])) {
            return $this->refs[$hash];
        }
        return -1;
    }

    public function getReflectionClass($cls) {
        return $this->reflClasses[$cls] ?? $this->reflClasses[$cls] = new \ReflectionClass($cls);
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
        $len = \strlen($value);
        return "s:$len:\"$value\";";
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
            $nref = $ref;
            do {
                $refs[] = $ref;
            } while($nref = $ref->getParentClass() && $nref != $ref && $ref = $nref);

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
                    $propName = $prop->getName();
                    $prop->setAccessible(true);
                    $state->id++;
                    if ($prop->isPrivate()) {
                        $str .= serialize("\0$className\0$propName", $state);
                        $str .= serialize($prop->getValue($value), $state);
                    }
                    else if (!isset($visitedProps[$propName])) {
                        if ($prop->isProtected()) {
                            $str .= serialize("\0*\0$propName", $state);
                        }
                        else {
                            $str .= serialize($propName, $state);
                        }
                        $str .= serialize($prop->getValue($value), $state);
                        $visitedProps[$propName] = 1;
                    }
                }
            }
            $str .= "}";
            return $str;
        }
    }
    else {
        return "N;";
    }
}

$starts = [];
$totals = [];
function start($name) {
    global $starts;
    $starts[$name] = microtime(true);
}

function stop($name) {
    global $totals, $starts;
    $t = microtime(true) - $starts[$name];
    if (!isset($totals[$name])) {
        $totals[$name] = $t;
    }
    else {
        $totals[$name] += $t;
    }
}

function unserialize($string, $state = null) {
    if ($state === null) {
        $state = new SerializationState();
    }

    $ch = $string[$state->pos];

    if ($ch === 's') { // s:5:"hello";
        $start = $state->pos + 2;
        $number = 0;
        while ($string[$start] >= '0' && $string[$start] <= '9') {
            $number *= 10;
            $number += $string[$start];
            $start++;
        }
        $state->pos = $start + 2;
        $str = substr($string, $state->pos, $number);
        $state->pos += $number + 2;
        return $str;
    }
    else if ($ch === 'i') { // i:1234;
        $start = $state->pos + 2;
        $number = 0;
        while ($string[$start] >= '0' && $string[$start] <= '9') {
            $number *= 10;
            $number += $string[$start];
            $start++;
        }
        $state->pos = $start + 1;
        return $number;
    }
    else if ($ch === 'O') { // O:3:"Foo":2:{s:1:"a";i:0;}

        $start = $state->pos + 2;
        $number = 0;
        while ($string[$start] >= '0' && $string[$start] <= '9') {
            $number *= 10;
            $number += $string[$start];
            $start++;
        }
        $className = substr($string, $start + 2, $number);
        $state->pos = $start + $number + 2;

        $refl = $state->getReflectionClass($className);

        $obj = $refl->newInstanceWithoutConstructor();

        $state->objs[$state->id] = $obj;

        $start = $state->pos + 2;
        $numProps = 0;
        while ($string[$start] >= '0' && $string[$start] <= '9') {
            $numProps *= 10;
            $numProps += $string[$start];
            $start++;
        }

        $state->pos = $start + 2;

        for($i=0;$i<$numProps;$i++) {
            $state->id++;
            $k = unserialize($string, $state);
            $v = unserialize($string, $state);

            if (substr($k, 0, 2) === "\0*") {
                $k = substr($k, 3);
                $prop = $refl->getProperty($k);
                $prop->setAccessible(true);
                $prop->setValue($obj, $v);
            }
            else if (substr($k, 0, 1) === "\0") {
                $z = strrpos($k, "\0");
                $cls = substr($k, 1, $z - 1);
                $k = substr($k, $z + 1);

                $refl2 = $state->getReflectionClass($cls);
                $prop = $refl2->getProperty($k);
                $prop->setAccessible(true);
                $prop->setValue($obj, $v);
            }
            else {
                $obj->$k = $v;
            }
        }

        $state->pos += 1;

        return $obj;
    }
    else if ($ch === 'r') { // r:123;
        $start = $state->pos + 2;
        $id = 0;
        while ($string[$start] >= '0' && $string[$start] <= '9') {
            $id *= 10;
            $id += $string[$start];
            $start++;
        }
        $state->pos = $start + 1;
        return $state->objs[$id];
    }
    else if ($ch === 'N') { // N;
        $state->pos += 2;
        return null;
    }
    else if ($ch === 'a') { // a:1:{i:0;i:13;}
        $arr = [];
        $start = $state->pos + 2;
        $num = 0;
        while ($string[$start] >= '0' && $string[$start] <= '9') {
            $num *= 10;
            $num += $string[$start];
            $start++;
        }
        $state->pos = $start + 2;
        for($i=0;$i<$num;$i++) {
            $state->id++;
            $k = unserialize($string, $state);
            $v = unserialize($string, $state);
            $arr[$k] = $v;
        }
        $state->pos++;
        return $arr;
    }
    else if ($ch === 'd') { // d:123.456;
        $num = 0;
        $decs = 1;
        $decimals = false;
        while (($string[$start] >= '0' && $string[$start] <= '9') || $string[$start] === '.') {
            if ($string[$start] === '.') {
                $decimals = true;
            }
            else {
                $num *= 10;
                $num += $string[$start];
                if ($decimals) {
                    $decs *= 10;
                }
            }
            $start++;
        }
        $num /= $decs;
        $state->pos = $start + 1;
        return $num;
    }
    if ($ch === 'b') { // b:1;
        $state->pos += 3;
        return $string[$state->pos - 1] === '1';
    }
    else {
        return false;
    }
}
