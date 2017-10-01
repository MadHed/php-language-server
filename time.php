<?php

$start = microtime(true);

function b() {
    return 1;
}

function a($x) {
    if ($x > 0) {
        return a($x - 1) + b();
    }
    else {
        return b();
    }
}

$sum = 0;
for($i=0;$i<1000000;$i++) {
    $sum = a(100);
}
echo $sum."\n";

$end = microtime(true);
echo ((int)(($end-$start)*1000))."ms\n";

$start = microtime(true);

for($i=0;$i<1000000;$i++) {
    $sum = 0;
    for($j = 0; $j < 100; $j++) {
        $sum += b();
    }
}
echo $sum."\n";

$end = microtime(true);
echo ((int)(($end-$start)*1000))."ms\n";


$start = microtime(true);

for($i=0;$i<1000000;$i++) {
    $sum = 0;
    for($j = 0; $j < 100; $j++) {
        $sum += 1;
    }
}
echo $sum."\n";

$end = microtime(true);
echo ((int)(($end-$start)*1000))."ms\n";


echo memory_get_usage()."\n";

