<?php

use Workerman\Timer;
use Workerman\Worker;
use Workerman\Connection\TcpConnection;

class LuckyDrawApp
{
    const HEARTBEAT_TIME = 55;
    private $member = [];
    private $prize = [];
    private $currentPrize = null;
    private $showResult = false;
    private $currentWinner = []; // 当前中奖用户
    private $winnerList = null; // 奖项和获奖者列表的映射
    private $running = false;
    private $masterConnId = 0; // 主控端连接Id (connection id 从 1 开始)
    private $worker; // Worker 容器实例 (worker id 从 0 开始)
    private $ip = "0.0.0.0";
    private $port = 3000;
    private $name = "LuckyDrawLive";

    /**
     * 用来指定 workerman 日志文件位置。仅仅记录 workerman 自身相关启动停止等日志，不包含任何业务日志
     * @param $filename
     * @return $this
     */
    public function setLogFile($filename)
    {
        Worker::$logFile = $filename;
        return $this;
    }

    /**
     * 以守护进程方式(-d启动)运行，则所有向终端的输出(echo var_dump等)都会被重定向到 stdoutFile 指定的文件中
     * @param $filename
     * @return $this
     */
    public function setStdoutFile($filename)
    {
        Worker::$stdoutFile = $filename;
        return $this;
    }

    /**
     * 设置监听的 IP 地址和端口号
     * @param $ip
     * @param $port
     * @return $this
     */
    public function setSocketAddress($ip, $port)
    {
        $this->ip = $ip;
        $this->port = $port;
        return $this;
    }

    /**
     * 设置 Worker 进程的名称
     * @param $name
     * @return $this
     */
    public function setWorkerName($name)
    {
        $this->name = $name;
        return $this;
    }

    public function run()
    {
        $error = $this->checkEnv();
        if (!empty($error)) {
            throw new \Error(implode(';', $error));
        }

        $this->worker = new Worker('websocket://' . $this->ip . ':' . $this->port);
        $this->worker->count = 1;
        $this->worker->name = $this->name;

        $this->worker->onWorkerStart = [$this, 'onWorkerStart'];
        $this->worker->onConnect = [$this, 'onConnect'];
        $this->worker->onMessage = [$this, 'onMessage'];
        $this->worker->onClose = [$this, 'onClose'];
        $this->worker->onError = [$this, 'onError'];

        Worker::runAll();
    }

    /**
     * 设置 Worker 子进程启动时的回调函数，每个子进程启动时都会执行
     */
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
                if (($timeNow - $connection->lastMessageTime) > self::HEARTBEAT_TIME) {
                    $connection->close();
                }
            }
        });
    }

    /**
     * 当客户端与 Workerman 建立连接时(TCP三次握手完成后)触发的回调函数
     * 每个连接只会触发一次 onConnect 回调
     * @param TcpConnection $connection 新的连接对象
     */
    public function onConnect(TcpConnection $connection)
    {
        // var_dump("onConnect: " . $connection->id);
        $connection->send($this->buffer("init", [
            'member' => $this->member,
            'prize' => $this->prize,
            'currentPrize' => $this->currentPrize,
            'showResult' => $this->showResult,
            'currentWinner' => $this->currentWinner,
            'winnerList' => $this->winnerList,
            'running' => $this->running,
        ]));
        $this->broadcastConnections();
    }

    /**
     * 当客户端通过连接发来数据时(Workerman收到数据时)触发的回调函数
     * @param TcpConnection $connection 连接对象
     * @param string $data 客户端发送的数据
     */
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
        if ($obj->emit === "tag") {
            // 客户端连接标识 (master/slave)
            $connection->tag = $obj->data;
            // 只允许一个主控端连接
            if ($obj->data === "master") {
                if (isset($this->worker->connections[$this->masterConnId])) {
                    $this->worker->connections[$this->masterConnId]->close();
                }
                $this->masterConnId = $connection->id;
            }
        } else if ($obj->emit === "ping") {
            // 客户端心跳包
            $connection->send($this->buffer("pong"));
        } else {
            // 主控端
            if ($connection->tag === "master") {
                // 记录同步状态
                if ($obj->emit === "choosePrize") {
                    // 选择奖项
                    $this->currentPrize = $obj->data->currentPrize;
                    $this->showResult = $obj->data->showResult;
                } else if ($obj->emit === "start") {
                    // 开始抽奖
                    $this->running = $obj->data->running;
                    $this->currentWinner = $obj->data->currentWinner;
                    $this->showResult = $obj->data->showResult;
                } else if ($obj->emit === "stop") {
                    // 停止抽奖
                    $this->running = $obj->data->running;
                    $this->currentWinner = $obj->data->currentWinner;
                    $this->showResult = $obj->data->showResult;
                    $this->winnerList = $obj->data->winnerList;
                } elseif ($obj->emit === "reset") {
                    // 重置
                    $this->currentPrize = count($this->prize) > 0 ? $this->prize[0] : null;
                    $this->showResult = false;
                    $this->currentWinner = [];
                    $this->winnerList = null;
                    $this->running = false;
                } elseif ($obj->emit === "config") {
                    // 导入配置
                    $this->member = $obj->data->member;
                    $this->prize = $obj->data->prize;
                    $this->currentPrize = $obj->data->currentPrize;
                    $this->showResult = $obj->data->showResult;
                    $this->currentWinner = $obj->data->currentWinner;
                    $this->winnerList = $obj->data->winnerList;
                    $this->running = $obj->data->running;
                }
                // 同步转发更新
                foreach ($this->worker->connections as $conn) {
                    if ($conn !== $connection) {
                        $conn->send($data);
                    }
                }
            }
        }
        // master debug
        if ($connection->tag === "master") {
            $this->writeln(json_encode([
                "handle" => "onMessage",
                "connection" => $connection->id,
                "data" => $obj
            ]));
        }
    }

    /**
     * 当客户端连接与 Workerman 断开时触发的回调函数。不管连接是如何断开的，只要断开就会触发 onClose
     * 每个连接只会触发一次 onClose
     * @param TcpConnection $connection 关闭的连接对象
     */
    public function onClose(TcpConnection $connection)
    {
        // var_dump("onClose: " . $connection->id);
        if ($connection->id === $this->masterConnId) {
            $this->masterConnId = 0;
        }
        // onClose 事件触发时，Workerman 还没有完全清理 $worker->connections 中的连接
        $this->broadcastConnections(true);
        // master debug
        if ($connection->tag === "master") {
            $this->writeln(json_encode([
                "handle" => "onClose",
                "connection" => $connection->id
            ]));
        }
    }

    /**
     * 当客户端的连接上发生错误时触发
     * @param TcpConnection $connection 发生错误的连接对象
     * @param int $code 错误码
     * @param string $msg 错误信息
     */
    public function onError(TcpConnection $connection, $code, $msg)
    {
        // var_dump("onError: " . $connection->id, $code, $msg);
        // master debug
        if ($connection->tag === "master") {
            $this->writeln(json_encode([
                "handle" => "onError",
                "connection" => $connection->id,
                "code" => $code,
                "msg" => $msg
            ]));
        }
    }

    /**
     * 检查环境是否符合要求
     * @return array 错误信息数组
     */
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

    /**
     * 格式化数据为 JSON 字符串
     * @param $emit string 事件名称
     * @param $data mixed 事件数据
     * @return string JSON 字符串
     */
    private function buffer($emit, $data = null)
    {
        return json_encode([
            "emit" => $emit,
            "data" => $data
        ]);
    }

    /**
     * 广播当前连接数
     * @param bool $delay 是否有延迟
     */
    private function broadcastConnections($delay = false)
    {
        $connectionCount = count($this->worker->connections);
        if ($delay) {
            $connectionCount -= 1;
        }
        foreach ($this->worker->connections as $connection) {
            $connection->send($this->buffer("connections", $connectionCount));
        }
    }

    /**
     * 写入日志
     * @param $msg string 日志消息
     */
    private function writeln($msg)
    {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    }
}
