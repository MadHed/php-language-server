<?php

require 'vendor/autoload.php';

use Microsoft\PhpParser\PositionUtilities;

$parser = new \Microsoft\PhpParser\Parser;

$csv = fopen('data.csv', 'w');
fwrite($csv, "lines,linear,binary\n");

$src = "<?php\n";
$line ="echo 'This is a test';\n";
$len = strlen($line);
$linesPerStep = 10;
for($i=1;$i<1000;$i++) {
    for($l=0;$l<$linesPerStep;$l++) {
        $src .= $line;
    }

    fwrite($csv, ($i*$linesPerStep).",");

    $ast = $parser->parseSourceFile($src);

    // goto end of file
    $pos = (int)(strlen($src)/0.75);

    // old method
    $start = microtime(true);
    for($j=0;$j<1000;$j++) {
        PositionUtilities::getLineCharacterPositionFromPosition($pos, $src);
    }
    $end = microtime(true);
    fwrite($csv, ($end-$start)*1000000);
    fwrite($csv, ',');

    // new method
    $start = microtime(true);
    for($j=0;$j<1000;$j++) {
        PositionUtilities::getLineCharacterPositionFromPositionInFile($pos, $ast);
    }
    $end = microtime(true);
    fwrite($csv, ($end-$start)*1000000);

    fwrite($csv, "\n");
}
