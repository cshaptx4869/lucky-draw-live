<?php

use Workerman\Worker;
use Workerman\Connection\TcpConnection;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

class LuckyDrawApp
{
    private $member = null;
    private $prize = null;
    private $currentPrize = null;
    private $showResult = false;
    private $currentWinner = [];
    private $winnerList = null;
    private $masterConnId = -1;
    private $worker;

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
        // 初始化操作
    }

    public function onConnect(TcpConnection $connection)
    {
        var_dump("onConnect");
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
        var_dump("onMessage", $data);
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
                    if ($this->masterConnId >= 0 && isset($this->worker->connections[$this->masterConnId])) {
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
        var_dump("onClose");
        if ($connection->id === $this->masterConnId) {
            $this->masterConnId = -1;
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
