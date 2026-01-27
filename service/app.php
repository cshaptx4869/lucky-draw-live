<?php

require_once __DIR__ . '/vendor/autoload.php';

const DS = DIRECTORY_SEPARATOR;
const TMP_PATH = __DIR__ . DS . 'tmp';

$app = new LuckyDrawApp();
$app->setStdoutFile(TMP_PATH . DS . 'debug.log')
    ->setSocketAddress('0.0.0.0', 3000)
    ->run();
