<?php
/**
 * Created by PhpStorm.
 * User: abewm
 * Date: 11/10/14
 * Time: 8:56 AM
 */

class taskList {
    /**
     * @var int maximum number of parallel threads
     */
    public $maxThreads;
    /**
     * @var int time to sleep in uSeconds within execution loop
     */
    public $sleepInterval; // usleep time
    /**
     * active tasks
     * @var task[]
     */
    protected $active=array();
    /**
     * tasks left in queue
     * @var task[]
     */
    protected $queue=array();

    /**
     * enqueue the same callback to a list
     * @param array $list list to map
     * @param callable $cb callback to apply; accepting at least one argument, the list item's value
     * @param mixed $arg [optional] additional argument to pass into the callback
     * @param mixed $arg [optional] additional argument to pass into the callback
     *
     * @return self
     */
    function map($list,$cb,$arg=null,$arg=null) {
        foreach ($list as $v) {
            $moreArgs=array_slice(func_get_args(),2);
            $args=array_merge(array($v),$moreArgs);
            $this->enqueue(function()use($args,$cb) {
                call_user_func_array($cb,$args);
            });
        }
        return $this;
    }

    /**
     * create a new task list
     * @param int $threads maximum number of threads to run parallel; set to 0 for no limit
     * @param int $sleepInterval sleep interval if queue is full
     */
    function __construct($threads=10,$sleepInterval=500000) {
        $this->maxThreads = $threads;
        $this->sleepInterval=$sleepInterval;
    }

    /**
     * add a new task into the queue
     * @param task|callable $cb action to enqueue
     *
     * @return self
     */
    function enqueue($cb) {
        $this->queue[]=$cb instanceof task?$cb:new task($cb);
        return $this;
    }

    /**
     * execute all tasks in the queue
     *
     * @return self
     */
    function work() {
        while($this->hasJobs()||$this->hasActive()) {
            // wait and filter finished processes
            $this->active=array_filter($this->active,function($t){
                /** @var task $t */
                return !$t->wait()->isDone();
            });
            // start Tasks if possible
            if($this->mayStartTask()) {
                do {
                    /** @var task $task */
                    $task = array_shift($this->queue);
                    $this->active[]=$task->execute();
                } while($this->mayStartTask());
            } else {
                usleep($this->sleepInterval);
            }
        }
        return $this;
    }

    /**
     * determines if a new task may be started
     * @return bool
     */
    protected function mayStartTask() {
        return $this->hasJobs()&&$this->maxThreads&&$this->maxThreads<$this->hasActive();
    }

    /**
     * @return int number of active tasks
     */
    function hasActive() {
        return count($this->active);
    }

    /**
     * @return int number of tasks left in queue
     */
    function hasJobs() {
        return count($this->queue);
    }

    /**
     * create a new task executing the whole list in a seperate thread
     * @return task
     */
    function workParallel() {
        return call_user_func(new task(array($this,@work)));
    }

    /**
     * clear the queue; e.g. after making the list work in parallel process
     * @return $this
     */
    function clearQueue() {
        $this->queue=array();
        return $this;
    }
}

/**
 * Class task
 * @property callable $action
 * @property int $pid
 * @property int $state
 * @property bool $isRunning
 */
class task {
    /**
     * @var callable
     */
    protected $action;
    /**
     * @var int the Process-ID of the thread; ONLY AVAILABLE IN MAIN THREAD (Parent)
     */
    protected $pid;
    /**
     * @var int execution state of the process; ONLY AVAILABLE IN MAIN THREAD (Parent)
     */
    protected $state;
    /**
     * @var bool status of the process; ONLY AVAILABLE IN MAIN THREAD (Parent)
     */
    protected $isRunning=false;

    /**
     * @return bool determines if the task was run and finished execution
     */
    function isDone() {
        return !($this->pid&&$this->isRunning);
    }

    /**
     * fork and run
     * @return self
     */
    function execute() {
        if($this->isRunning) return $this;
        if($pid=pcntl_fork()) {
            $this->pid=$pid;
            $this->isRunning=true;
        } else {
            call_user_func($this->action);
            exit;
        }
        return $this;
    }

    /**
     * update the execution status or wait for the process to finish
     * @param int $options PCNTL_wait Options
     *
     * @return self
     */
    function wait($options=WNOHANG) {
        if($this->pid==pcntl_waitpid($this->pid,$state,$options)) {
            $this->state=$state;
            if(pcntl_wifexited($state)) $this->isRunning=false;
        }
        return $this;
    }

    /**
     * @param callable $action
     */
    function __construct($action) {
        $this->action=$action;
    }

    /**
     * execute the task
     * @return self
     */
    function __invoke() {
        return $this->execute();
    }

    /**
     * read a runtime property
     * @param string $var property name
     *
     * @return mixed
     */
    public function __get($var) {
        return $this->$var;
    }
}
