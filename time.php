<?php

require 'sertest.php';

function memory() {
    echo memory_get_usage(true)."\n";
}

$start = microtime(true);
$end = microtime(true);
echo ((int)(($end-$start)*1000))."ms\n";


class A {
    public $r;
}

for($i=1;$i<4000000;$i++) {
    $c = new A();
    $c->r = null;
    $arr[] = $c;
}
memory();

