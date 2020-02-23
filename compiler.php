<?php

require_once __DIR__ . '/vendor/autoload.php';

$compiler = new \TimurFlush\PhinxToPhar\Compiler(
    'v0.11.0',
    __DIR__ . '/build',
    __DIR__ . '/dist'
);
$compiler->compile();
