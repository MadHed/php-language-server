<?php

$start = microtime(true);

$str = str_repeat('X', 1000000);
echo memory_get_usage()."\n";
$sub = substr($str, 500000);

$end = microtime(true);
echo ((int)(($end-$start)*1000))."ms\n";

echo memory_get_usage()."\n";

