<?php

namespace LanguageServer\CodeRepository;

require 'vendor/autoload.php';

class SerializationState {
    public $id = 1;
    public $refs = [];
    public $pos = 0;
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

function unserialize($string, $state = null) {
    if ($state === null) {
        $state = new SerializationState();
    }

    $ch = $string[$state->pos];
    if ($ch === 'N') { // N;
        $state->pos += 2;
        return null;
    }
    else if ($ch === 'b') { // b:1;
        $state->pos += 3;
        return $string[$state->pos - 1] === '1';
    }
    else if ($ch === 'i') { // i:1234;
        $start = $state->pos + 2;
        $end = $start + 1;
        while (ord($string[$end]) >= 48 && ord($string[$end]) <= 57) {
            $end++;
        }
        $state->pos = $end + 1;
        return (int)substr($string, $start, $end - $start);
    }
    else if ($ch === 'd') { // d:123.456;
        $start = $state->pos + 2;
        $end = $start + 1;
        while ((ord($string[$end]) >= 48 && ord($string[$end]) <= 57) || $string[$end] === '.') {
            $end++;
        }
        $state->pos = $end + 1;
        return (float)substr($string, $start, $end - $start);
    }
    else if ($ch === 's') { // s:5:"hello";
        $start = $state->pos + 2;
        $end = $start + 1;
        while (ord($string[$end]) >= 48 && ord($string[$end]) <= 57) {
            $end++;
        }
        $state->pos = $end + 2;
        $num = (int)substr($string, $start, $end - $start);
        $str = substr($string, $state->pos, $num);
        $state->pos += $num + 2;
        return $str;
    }
    else if ($ch === 'a') { // a:1{i:0;i:13;}
        $arr = [];
        $start = $state->pos + 2;
        $end = $start + 1;
        while (ord($string[$end]) >= 48 && ord($string[$end]) <= 57) {
            $end++;
        }
        $num = (int)substr($string, $start, $end - $start);
        $state->pos = $end + 2;
        for($i=0;$i<$num;$i++) {
            $k = unserialize($string, $state);
            $v = unserialize($string, $state);
            $arr[$k] = $v;
        }
        $state->pos++;
        return $arr;
    }
    else if ($ch === 'O') { // O:3:"Foo":2:{s:1:"a";i:0;}

        $start = $state->pos + 2;
        $end = $start + 1;
        while (ord($string[$end]) >= 48 && ord($string[$end]) <= 57) {
            $end++;
        }
        $nameLength = (int)substr($string, $start, $end - $start);
        $className = substr($string, $end + 2, $nameLength);

        $obj = new \stdClass();

        $start = $end + $nameLength + 4;
        $end = $start + 1;
        while (ord($string[$end]) >= 48 && ord($string[$end]) <= 57) {
            $end++;
        }
        $numProps = (int)substr($string, $start, $end - $start);

        $state->pos = $end + 2;

        for($i=0;$i<$numProps;$i++) {
            $k = unserialize($string, $state);
            $v = unserialize($string, $state);
            var_dump($k);
            if (substr($k, 0, 2) === "\0*") {
                $k = substr($k, 3);
                $obj->$k = $v;
            }
            else if (substr($k, 0, 1) === "\0") {
                $z = strrpos($k, "\0");
                $cls = substr($k, 1, $z - 1);
                var_dump($cls);
                $k = substr($k, $z + 1);
                var_dump($k);
                echo "\n";
                $obj->$k = $v;
            }
            else {
                $obj->$k = $v;
            }
        }
        else if ($ch === 'r') { // r:123;

        }

        $state->pos += 1;

        return $obj;
    }
    else {
        return false;
    }
}

//var_dump(unserialize('O:3:"Foo":3:{s:1:"a";i:0;s:1:"b";i:0;s:1:"c";i:0;}'));
var_dump(unserialize(file_get_contents('phpls.cache')));
