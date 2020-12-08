<?php

require_once __DIR__ . '/vendor/autoload.php';

$compiler = new \TimurFlush\PhinxToPhar\Compiler(
    '0.12.4',
    __DIR__ . '/build',
    __DIR__ . '/dist'
);
$compiler->compile();
