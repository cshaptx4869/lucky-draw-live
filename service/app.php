<?php

use Workerman\Worker;
use Workerman\Connection\TcpConnection;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

// 抽奖状态相关变量
$currentPrize = null;
$showResult = false;
$currentWinner = [];
$winnerList = null;
$masterConnId = -1;

$worker = new Worker('websocket://0.0.0.0:3000');
$worker->count = 1;

$worker->onWorkerStart = function ($worker) {

};

$worker->onConnect = function (TcpConnection $connection) use ($worker) {
    var_dump("onConnect");
    global $member, $prize, $currentPrize, $showResult, $winnerList, $currentWinner;
    $connection->send(json_encode([
        "emit" => "init",
        "data" => [
            'member' => $member,
            'prize' => $prize,
            'currentPrize' => $currentPrize === null && !empty($prize) > 0 ? $prize[0] : $currentPrize,
            'showResult' => $showResult,
            'winnerList' => $winnerList,
            'currentWinner' => $currentWinner,
        ]
    ]));
};

$worker->onMessage = function (TcpConnection $connection, $data) use ($worker) {
    global $currentPrize, $showResult, $winnerList, $currentWinner, $masterConnId;
    var_dump("onMessage", $data);
    $obj = json_decode($data);
    if (is_object($obj) && property_exists($obj, "emit") && property_exists($obj, "data")) {
        // 记录同步状态
        if ($obj->emit === "currentPrize") {
            $currentPrize = $obj->data;
        } else if ($obj->emit === "showResult") {
            $showResult = $obj->data;
        } else if ($obj->emit === "currentWinner") {
            $currentWinner = $obj->data;
        } else if ($obj->emit === "winnerList") {
            $winnerList = $obj->data;
        } else if ($obj->emit === "reset") {
            $currentPrize = null;
            $showResult = false;
            $currentWinner = [];
            $winnerList = null;
        } else if ($obj->emit === "tag") {
            $tag = $obj->data;
            $connection->tag = $tag;
            if ($tag === "master") {
                if ($masterConnId >= 0 && isset($worker->connections[$masterConnId])) {
                    $worker->connections[$masterConnId]->close();
                }
                $masterConnId = $connection->id;
            }
        }
        // 同步转发更新
        foreach ($worker->connections as $conn) {
            if ($conn !== $connection) {
                $conn->send($data);
            }
        }
    }
};

$worker->onClose = function (TcpConnection $connection) {
    var_dump("onClose");
};

// 运行worker
Worker::runAll();
