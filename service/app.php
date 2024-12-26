<?php

require_once __DIR__ . '/vendor/autoload.php';

define('DS', DIRECTORY_SEPARATOR);
define('TMP_PATH', __DIR__ . DS . 'tmp');
define('CONFIG_PATH', __DIR__ . DS . 'config');

$excelFile = CONFIG_PATH . DS . 'config.xlsx';
if (file_exists($excelFile)) {
    $excel = Excel::getInstance();
    $member = $excel->parse($excelFile, ['A' => 'name', 'B' => 'phone'], 0);
    $prize = $excel->parse($excelFile, ['A' => 'name', 'B' => 'quota'], 1);
} else {
    require_once CONFIG_PATH . DS . 'config.php';
}

$app = new LuckyDrawApp($member, $prize);
$app->setStdoutFile(TMP_PATH . DS . 'debug.log')
    ->setSocketAddress('0.0.0.0', 3000)
    ->run();
