<?php

require 'sertest.php';

function memory() {
    echo memory_get_usage(true)."\n";
}

$start = microtime(true);
$end = microtime(true);
echo ((int)(($end-$start)*1000))."ms\n";


$str[] = new \stdClass();
for($i=1;$i<1000000;$i++) {
    $str[] = new \stdClass();
    $str[$i]->ref = $str[$i-1];
}
memory();
for($i=0;$i<4;$i++) {
    \serialize($str);
    \gc_collect_cycles();
    memory();
}

