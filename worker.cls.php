<?php

/**
 * Class worker
 *
 * multi-threaded worker-class
 */
class worker {
    /** @var callable[] the working queue */
    protected $queue=array();
    /** @var int number of active processes */
    protected $working=0;
    //protected $worked=0;
    /** @var int maximum number of simultaneous threads */
    protected $maxThreads=0;
    const AUTOSTART=true;

    /**
     * create a child process
     * @return bool true on parent process; false on child
     * @throws Exception
     */
    protected function fork() {
        $pid=pcntl_fork();
        if(-1==$pid) throw new Exception ("Could not fork process.");
        return (bool)$pid;
    }

    /**
     * enqueue a callback to a list
     *
     * @param $list array list to map a callback with
     * @param $cb callable function to accept $value and $key in this order
     */
    public function map($list,$cb) {
        foreach ($list as $k=>$v) {
            $args=array($v,$k);
            $this->enqueue(function()use($args,$cb) {
                call_user_func_array($cb,$args);
            });
        }
        $this->work()->wait();
    }

    /**
     * create a new working queue
     * @param int $threads number of threads; 0 means no thread limit;
     */
    public function __construct($threads=0)
    {
        $this->maxThreads = $threads;
    }

    /**
     * @param $cb callable action to perform
     * @param bool $start start immediately
     * @return \static
     */
    public function enqueue($cb,$start=false) {
        $this->queue[]=$cb;
        $start&&$this->work(false);
        return $this;
    }

    /**
     * start working the queue
     * @throws Exception
     * @return \static
     * @param bool $wait wait to finish or not; eg. only start
     */
    public function work($wait=true) {
        if($this->working>=$this->maxThreads) return $this;
        while($action=array_shift($this->queue)) {
            /** @var $action callable */
            if($this->fork()) {
                // parent thread
                $this->working++;
                // check thread limit and wait...
                if($this->maxThreads && $this->working>=$this->maxThreads) {
                    pcntl_wait($state);
                    $this->working--;
                    //$this->worked++;
                }
            } else {
                //child
                $action();
                exit;
            }
        }
        // wait for the tail...
        $wait&&$this->wait();
        return $this;
    }

    /**
     * wait for all jobs to finish
     * @return \static
     */
    public function wait() {
        while($this->working--) {
            pcntl_wait($state);
            //$this->worked++;
        }
        $this->working=0;
        return $this;
    }

    /**
     * @return bool
     */
    public function hasJobs() {
        return (bool)count($this->queue);
    }
    /*
    public function getNumFinished() {
        return $this->worked;
    }*/
}

// tests
/* 
include "sharedMemory.cls.php";

$worker = new worker(10);
$mem=new sharedMemory(sharedMemory::getMaxSize());
$mem->numbers=array();

for($i=0;$i<80;$i++) $worker->enqueue(function()use($i,$mem){
    $sleep=50000*(1+round(rand()%100));
    usleep($sleep);
    $append="$i: $sleep";
    $mem->transactVar('numbers',function($n)use($append){
        $n[]=$append;
        return $n;
    });
    echo "done $i\n";
},1);
$worker->wait();
echo \json_encode($mem->numbers,JSON_PRETTY_PRINT);
$mem->remove();

/**/
