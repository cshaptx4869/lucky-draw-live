<?php

use Workerman\Timer;
use Workerman\Worker;
use Workerman\Connection\TcpConnection;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

class LuckyDrawApp
{
    const HEARTBEAT_TIME = 55;
    private $member = null;
    private $prize = null;
    private $currentPrize = null;
    private $showResult = false;
    private $currentWinner = [];
    private $winnerList = null;
    private $masterConnId = 0; // connection id 从 1 开始
    private $worker; // worker id 从 0 开始

    public function __construct($member, $prize)
    {
        $this->member = $member;
        $this->prize = $prize;

        $this->worker = new Worker('websocket://0.0.0.0:3000');
        $this->worker->count = 1;
        $this->worker->name = 'LuckyDrawLive';

        $this->worker->onWorkerStart = [$this, 'onWorkerStart'];
        $this->worker->onConnect = [$this, 'onConnect'];
        $this->worker->onMessage = [$this, 'onMessage'];
        $this->worker->onClose = [$this, 'onClose'];
    }

    public function run()
    {
        $error = $this->checkEnv();
        if (!empty($error)) {
            throw new Error(join($error, ';'));
        }
        Worker::runAll();
    }

    public function onWorkerStart()
    {
        // var_dump("onWorkerStart: " . $this->worker->id);
        // 进程启动后设置一个每10秒运行一次的定时器
        Timer::add(10, function () {
            $timeNow = time();
            foreach ($this->worker->connections as $connection) {
                // 有可能该connection还没收到过消息，则lastMessageTime设置为当前时间
                if (!property_exists($connection, "lastMessageTime")) {
                    $connection->lastMessageTime = $timeNow;
                    continue;
                }
                // 上次通讯时间间隔大于心跳间隔，则认为客户端已经下线，关闭连接
                if ($timeNow - $connection->lastMessageTime > self::HEARTBEAT_TIME) {
                    $connection->close();
                }
            }
        });
    }

    public function onConnect(TcpConnection $connection)
    {
        // var_dump("onConnect: " . $connection->id);
        $connection->send($this->buffer("init", [
            'member' => $this->member,
            'prize' => $this->prize,
            'currentPrize' => $this->currentPrize === null && !empty($this->prize) ? $this->prize[0] : $this->currentPrize,
            'showResult' => $this->showResult,
            'winnerList' => $this->winnerList,
            'currentWinner' => $this->currentWinner,
        ]));
    }

    public function onMessage(TcpConnection $connection, $data)
    {
        // 给connection临时设置一个lastMessageTime属性，用来记录上次收到消息的时间
        $connection->lastMessageTime = time();
        // var_dump("onMessage: " . $connection->id, $data);
        $obj = json_decode($data);
        if ((json_last_error() !== JSON_ERROR_NONE) || !(is_object($obj) && property_exists($obj, "emit") && property_exists($obj, "data"))) {
            $connection->send($this->buffer("error", "数据格式错误"));
            return;
        }
        if (in_array($obj->emit, ["tag", "ping"])) {
            if ($obj->emit === "tag") {
                $tag = $obj->data;
                $connection->tag = $tag;
                if ($tag === "master") {
                    if ($this->masterConnId > 0 && isset($this->worker->connections[$this->masterConnId])) {
                        $this->worker->connections[$this->masterConnId]->close();
                    }
                    $this->masterConnId = $connection->id;
                }
            } else if ($obj->emit === "ping") {
                $connection->send($this->buffer("pong"));
            }
        } else {
            // 记录同步状态
            if ($obj->emit === "currentPrize") {
                $this->currentPrize = $obj->data;
            } elseif ($obj->emit === "showResult") {
                $this->showResult = $obj->data;
            } elseif ($obj->emit === "currentWinner") {
                $this->currentWinner = $obj->data;
            } elseif ($obj->emit === "winnerList") {
                $this->winnerList = $obj->data;
            } elseif ($obj->emit === "reset") {
                $this->currentPrize = null;
                $this->showResult = false;
                $this->currentWinner = [];
                $this->winnerList = null;
            }
            // 同步转发更新
            foreach ($this->worker->connections as $conn) {
                if ($conn !== $connection) {
                    $conn->send($data);
                }
            }
        }
    }

    public function onClose(TcpConnection $connection)
    {
        var_dump("onClose: " . $connection->id);
        if ($connection->id === $this->masterConnId) {
            $this->masterConnId = 0;
        }
    }

    private function checkEnv()
    {
        $errorMsg = [];
        if (strpos(PHP_OS, "Linux") !== false) {
            // 检查扩展
            $checkExt = ["pcntl", "posix"];
            $loadedExts = get_loaded_extensions();
            foreach ($checkExt as $ext) {
                if (!in_array($ext, $loadedExts)) {
                    $errorMsg[] = $ext . ' 扩展没有安装';
                }
            }
            // 检查函数
            $checkFunc = [
                "stream_socket_server",
                "stream_socket_client",
                "pcntl_signal_dispatch",
                "pcntl_signal",
                "pcntl_alarm",
                "pcntl_fork",
                "pcntl_wait",
                "posix_getuid",
                "posix_getpwuid",
                "posix_kill",
                "posix_setsid",
                "posix_getpid",
                "posix_getpwnam",
                "posix_getgrnam",
                "posix_getgid",
                "posix_setgid",
                "posix_initgroups",
                "posix_setuid",
                "posix_isatty",
            ];
            $disabledFuncs = explode(',', ini_get('disable_functions'));
            foreach ($checkFunc as $func) {
                if (in_array($func, $disabledFuncs)) {
                    $errorMsg[] = $func . ' 函数被禁用';
                }
            }
        }
        return $errorMsg;
    }

    private function buffer($emit, $data = null)
    {
        return json_encode([
            "emit" => $emit,
            "data" => $data
        ]);
    }
}

$app = new LuckyDrawApp($member, $prize);
$app->run();
