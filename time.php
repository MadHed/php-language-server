<?php

$start = microtime(true);

class Foo {
    public $a;
    public $b;
    public $c;
    public $d;
    public $e;
    public $f;
}

$a = new Foo();
for($i=0;$i<1000000;$i++) {
    $r = new \ReflectionClass($a);
    $ps = $r->getProperties();
    $arr = [1,2,3];
    foreach($ps as $p) {
        if (!in_array($p->getName(), $arr)) {
            $p->getValue($a);
        }
    }
}

$end = microtime(true);
echo ((int)(($end-$start)*1000))."ms\n";

echo memory_get_usage()."\n";

