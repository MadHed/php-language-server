<?php

namespace LanguageServer\CodeDB;

require_once 'vendor/autoload.php';

class SerializationState {
    public $id = 1;
    public $refs = [];
    public $objs = [];
    public $pos = 0;
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
            $state->id++;
            $str .= serialize($k, $state) . serialize($v, $state);
        }
        return $str . "}";
    }
    else if (\is_object($value)) {
        $id = $state->refs[\spl_object_hash($value)] ?? 0;
        if ($id > 0) {
            return "r:$id;";
        }
        else {
            $state->refs[\spl_object_hash($value)] = $state->id;
            $ref = new \ReflectionClass($value);
            $cls = $ref->getName();
            $nref = $ref;
            do {
                $refs[] = $ref;
            } while($nref = $ref->getParentClass() && $nref != $ref && $ref = $nref);

            $str = "O:".\strlen($cls).":\"".$cls."\":";

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
                        $str .= serialize("\0$className\0$propName", $state) . serialize($prop->getValue($value), $state);
                    }
                    else if (!isset($visitedProps[$propName])) {
                        if ($prop->isProtected()) {
                            $str .= serialize("\0*\0$propName", $state) . serialize($prop->getValue($value), $state);
                        }
                        else {
                            $str .= serialize($propName, $state) . serialize($value->$propName, $state);
                        }
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

function unserialize($string, $state = null) {
    if ($state === null) {
        $state = new SerializationState();
    }

    $ch = $string[$state->pos];

    if ($ch === 's') { // s:5:"hello";
        $start = $state->pos + 2;
        $number = 0;
        while (true) {
            $ch = $string[$start];
            if ($ch < '0' || $ch > '9') break;
            $number *= 10;
            $number += $ch;
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
        while (true) {
            $ch = $string[$start];
            if ($ch < '0' || $ch > '9') break;
            $number *= 10;
            $number += $ch;
            $start++;
        }
        $state->pos = $start + 1;
        return $number;
    }
    else if ($ch === 'O') { // O:3:"Foo":2:{s:1:"a";i:0;}

        $start = $state->pos + 2;
        $number = 0;
        while (true) {
            $ch = $string[$start];
            if ($ch < '0' || $ch > '9') break;
            $number *= 10;
            $number += $ch;
            $start++;
        }
        $className = substr($string, $start + 2, $number);
        $state->pos = $start + $number + 2;

        $refl = new \ReflectionClass($className);

        $obj = $refl->newInstanceWithoutConstructor();

        $state->objs[$state->id] = $obj;

        $start = $state->pos + 2;
        $numProps = 0;
        while (true) {
            $ch = $string[$start];
            if ($ch < '0' || $ch > '9') break;
            $numProps *= 10;
            $numProps += $ch;
            $start++;
        }

        $state->pos = $start + 2;

        for($i=0;$i<$numProps;$i++) {
            $state->id++;
            $k = unserialize($string, $state);
            $v = unserialize($string, $state);
            $k0 = $k[0];

            if ($k0 === "\0" && $k[1] === '*') { // protected
                $k = substr($k, 3);
                $prop = new \ReflectionClass($className, $k);
                $prop->setAccessible(true);
                $prop->setValue($obj, $v);
            }
            else if ($k0 === "\0") { // private
                $z = strrpos($k, "\0");
                $cls = substr($k, 1, $z - 1);
                $k = substr($k, $z + 1);

                $refl2 = new \ReflectionClass($cls);
                $prop = $refl2->getProperty($k);
                $prop->setAccessible(true);
                $prop->setValue($obj, $v);
            }
            else { // public
                $obj->$k = $v;
            }
        }

        $state->pos += 1;

        return $obj;
    }
    else if ($ch === 'r') { // r:123;
        $start = $state->pos + 2;
        $id = 0;
        while (true) {
            $ch = $string[$start];
            if ($ch < '0' || $ch > '9') break;
            $id *= 10;
            $id += $ch;
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
        while (true) {
            $ch = $string[$start];
            if ($ch < '0' || $ch > '9') break;
            $num *= 10;
            $num += $ch;
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
        $start = $state->pos + 2;
        $end = $start;
        while (true) {
            $ch = $string[$end];
            if (($ch < '0' || $ch > '9') && $ch !== '.' && $ch !== '-' && $ch !== 'E') break;
            $end++;
        }
        $num = (float)substr($string, $start, $end - $start);
        $state->pos = $end + 1;
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




// ---------------------------------------------------





function binserialize($value, $state = null) {
    if ($state === null) {
        $state = new SerializationState();
    }

    if ($value === null) {
        return "n";
    }
    else if ($value === false) {
        return 'f';
    }
    else if ($value === true) {
        return 't';
    }
    else if (\is_int($value)) {
        return 'i'.pack('q', $value);
    }
    else if (\is_float($value)) {
        return 'f'.pack('d', $value);
    }
    else if (\is_string($value)) {
        return 's'.pack('V', \strlen($value)).$value;
    }
    else if (\is_array($value)) {
        $str = 'a'.pack('V', count($value));
        foreach($value as $k => $v) {
            $state->id++;
            $str .= binserialize($k, $state) . binserialize($v, $state);
        }
        return $str;
    }
    else if (\is_object($value)) {
        $id = $state->refs[\spl_object_hash($value)] ?? 0;
        if ($id > 0) {
            return 'r'.pack('V', $id);
        }
        else {
            $state->refs[\spl_object_hash($value)] = $state->id;
            $ref = new \ReflectionClass($value);
            $cls = $ref->getName();
            $nref = $ref;
            do {
                $refs[] = $ref;
            } while($nref = $ref->getParentClass() && $nref != $ref && $ref = $nref);

            $str = 'o'.pack('V', strlen($cls)).$cls;

            $numProps = 0;
            foreach($refs as $ref) {
                $props = $ref->getProperties();
                foreach($props as $prop) {
                    if (!$prop->isStatic()) $numProps++;
                }
            }

            $str .= pack('V', $numProps);

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
                        $str .= binserialize("\0$className\0$propName", $state) . binserialize($prop->getValue($value), $state);
                    }
                    else if (!isset($visitedProps[$propName])) {
                        if ($prop->isProtected()) {
                            $str .= binserialize("\0*\0$propName", $state) . binserialize($prop->getValue($value), $state);
                        }
                        else {
                            $str .= binserialize($propName, $state) . binserialize($value->$propName, $state);
                        }
                        $visitedProps[$propName] = 1;
                    }
                }
            }
            return $str;
        }
    }
    else {
        return "n";
    }
}

function binunserialize($string, $state = null) {
    if ($state === null) {
        $state = new SerializationState();
    }

    $ch = $string[$state->pos];
    $state->pos++;

    if ($ch === 's') { // s:5:"hello";
        $len = unpack('V', substr($string, $state->pos, 4))[1];
        $state->pos += 4;
        $str = substr($string, $state->pos, $len);
        $state->pos += $len;
        return $str;
    }
    else if ($ch === 'i') { // i:1234;
        $number = unpack('q', substr($string, $state->pos, 8))[1];
        $state->pos += 8;
        return $number;
    }
    else if ($ch === 'o') { // O:3:"Foo":2:{s:1:"a";i:0;}
        $len = unpack('V', substr($string, $state->pos, 4))[1];
        $state->pos += 4;

        $className = substr($string, $state->pos, $len);
        $state->pos += $len;

        $refl = new \ReflectionClass($className);

        $obj = $refl->newInstanceWithoutConstructor();

        $state->objs[$state->id] = $obj;

        $numProps = unpack('V', substr($string, $state->pos, 4))[1];
        $state->pos += 4;

        for($i = 0; $i < $numProps; $i++) {
            $state->id++;
            $k = binunserialize($string, $state);
            $v = binunserialize($string, $state);
            $k0 = $k[0];

            if ($k0 === "\0" && $k[1] === '*') { // protected
                $k = substr($k, 3);
                $prop = new \ReflectionClass($className, $k);
                $prop->setAccessible(true);
                $prop->setValue($obj, $v);
            }
            else if ($k0 === "\0") { // private
                $z = strrpos($k, "\0");
                $cls = substr($k, 1, $z - 1);
                $k = substr($k, $z + 1);

                $refl2 = new \ReflectionClass($cls);
                $prop = $refl2->getProperty($k);
                $prop->setAccessible(true);
                $prop->setValue($obj, $v);
            }
            else { // public
                $obj->$k = $v;
            }
        }

        return $obj;
    }
    else if ($ch === 'r') { // r:123;
        $id = unpack('V', substr($string, $state->pos, 4))[1];
        $state->pos += 4;
        return $state->objs[$id];
    }
    else if ($ch === 'n') { // N;
        return null;
    }
    else if ($ch === 'a') { // a:1:{i:0;i:13;}
        $arr = [];
        $num = unpack('V', substr($string, $state->pos, 4))[1];
        $state->pos += 4;
        for($i=0;$i<$num;$i++) {
            $state->id++;
            $k = binunserialize($string, $state);
            $v = binunserialize($string, $state);
            $arr[$k] = $v;
        }
        return $arr;
    }
    else if ($ch === 'f') { // d:123.456;
        $val = unpack('f', substr($string, $state->pos, 8))[1];
        $state->pos += 8;
        return $val;
    }
    else if ($ch === 't') { // b:1;
        return true;
    }
    else if ($ch === 'f') { // b:1;
        return false;
    }
    else {
        return false;
    }
}
