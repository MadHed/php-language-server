<?php

namespace A\B\C;

use D\E\{F as G};
use D\E\F;

interface I {
    const CO = 0;
    function abc();
}
class Bar implements I {
    static public function test() {

    }

    public function __call() {

    }

    static public function __callStatic() {

    }
}

class Foo extends Bar {
    const BLA = 0;
    public function bar() {
        $x = new \Bar;
        $y = new \Bar;
        strlen();
        Foo::BLA;
        self::test();
        self::dsgjkh();
        echo self::CO;
        self::abc();
    }
}
