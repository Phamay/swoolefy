<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
+----------------------------------------------------------------------
*/

namespace Swoolefy\Core\Pools;

use Swoole\Process;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Table\TableManager;

abstract class AbstractProcessPools {

    private $swooleProcess;
    private $processName;
    private $async = null;
    private $args = [];
    private $bind_worker_id = null;

     /**
     * __construct 
     * @param string  $processName
     * @param boolean $async      
     * @param array   $args       
     */
    public function __construct(string $processName, $async = true, array $args = []) {
        $this->async = $async;
        $this->args = $args;
        $this->processName = $processName;
        $this->swooleProcess = new \Swoole\Process([$this,'__start'], false, 2);
        Swfy::getServer()->addProcess($this->swooleProcess);
    }

    /**
     * getProcess 获取process进程对象
     * @return object
     */
    public function getProcess() {
        return $this->swooleProcess;
    }

    /*
     * 服务启动后才能获得到创建的进程pid,不启动为null
     */
    public function getPid() {
       if(isset($this->swooleProcess->pid)){
            return $this->swooleProcess->pid;
        }else{
            return null;
        }
    }

    /**
     * setBindWorkerId 进程绑定对应的worker 
     * @param  int $worker_id
     */
    public function setBindWorkerId(int $worker_id) {
        $this->bind_worker_id = $worker_id;    
    }

    /**
     * __start 创建process的成功回调处理
     * @param  Process $process
     * @return void
     */
    public function __start(Process $process) {
        TableManager::getTable('table_process_pools_map')->set(
            md5($this->processName), ['pid'=>$this->swooleProcess->pid]
        );
        if(extension_loaded('pcntl')) {
            pcntl_async_signals(true);
        }

        Process::signal(SIGTERM, function() use($process) {
            $this->onShutDown();
            TableManager::getTable('table_process_pools_map')->del(md5($this->processName));
            swoole_event_del($process->pipe);
            $this->swooleProcess->exit(0);
        });

        if($this->async){
            swoole_event_add($this->swooleProcess->pipe, function(){
                $msg = $this->swooleProcess->read(64 * 1024);
                try{
                    $this->onReceive($msg);
                }catch(\Throwable $t) {
                    // 记录错误与异常
                    $exceptionHanderClass = BaseServer::getExceptionClass();
                    $errMsg = $t->getMessage();
                    $exceptionHanderClass::shutHalt($errMsg);
                }
            });
        }

        $this->swooleProcess->name('php-ProcessPools_of_worker'.$this->bind_worker_id.':'.$this->getProcessName());
        try{
            $this->run($this->swooleProcess);
        }catch(\Throwable $t) {
            // 记录错误与异常
            $exceptionHanderClass = BaseServer::getExceptionClass();
            $errMsg = $t->getMessage();
            $exceptionHanderClass::shutHalt($errMsg);
        }
    }

    /**
     * getArgs 获取变量参数
     * @return mixed
     */
    public function getArgs() {
        return $this->args;
    }

    /**
     * getProcessName 
     * @return string 
     */
    public function getProcessName() {
        return $this->processName;
    }

    /**
     * sendMessage 向绑定的worker进程发送数据
     * worker进程将通过onPipeMessage函数监听获取数数据
     * @param  mixed  $msg
     * @param  int    $worker_id
     * @return boolean
     */
    public function sendMessage($msg = null, int $worker_id = null) {
        if(!$msg) {
            throw new \Exception('param $msg can not be null or empty', 1);   
        }
        if($worker_id == null) {
            $worker_id = $this->bind_worker_id;
        }
        return Swfy::getServer()->sendMessage($msg, $worker_id);
    }

    /**
     * run 进程创建后的run方法
     * @param  Process $process
     * @return void
     */
    public abstract function run(Process $process);
    public abstract function onShutDown();
    public abstract function onReceive($str, ...$args);

}