<?php

/**
 * Class sharedMemory
 *
 * cross process memory using system V -resources
 *
 * Warning: makes use to semaphore-functions. these functions are kernel specific and mad programmed.
 * There's a hig probability that created semaphores won't be deleted completely.
 *
 * one note about these: if there is no way to modify the stored values directly. you habe to put them into
 * a variable, modify that and put that pack into memory. @see sharedMemory::transactVar
 *
 */

require_once ('semaphore.cls.php');

class sharedMemory extends semaphore{
    /** @var null|resource the memory-segment */
    protected $mem=null;
    /** @var string[] name-index-associations */
    protected $nameToKey=array();

    public function beginTransaction() {
        // sem_acquire blocks until the segment is accessible
        $this->lock();
        return $this;
    }
    public function endTransaction() {
        $this->unlock();
        return $this;
    }
    public function __construct($size=10000) {
        parent::__construct(1,0640);
        if(!$this->mem=shm_attach($this->getKey(),$size,0640))
            throw new Exception('could not attach to shared Memory');
        // store index in first place
        $this->nameToKey[]='';
        // here_we'll save the number of instances
        // $this->nameToKey[]='';
        $this->writeIndex();
    }
    public function remove() {
        shm_remove($this->mem);
        parent::remove();
    }
    protected function refreshIndex() {
        $this->nameToKey=$this->getVar(0);
    }
    protected function writeIndex() {
        $this->setVar(0,$this->nameToKey);
    }
    protected function getVar($idx) {
        return shm_get_var($this->mem,$idx);
    }
    protected function setVar($idx,$value) {
        return shm_put_var($this->mem,$idx,$value);
    }
    public function __destruct() {
        shm_detach($this->mem);
    }
    public function __isset($var) {
        $this->refreshIndex();
        return array_search($var,$this->nameToKey);
    }
    public function __get($var) {
        return ($k=$this->__isset($var))?$this->getVar($k):null;
    }
    public function __set($var,$value) {
        if(!$index=$this->__isset($var)) {
            $this->nameToKey[]=$var;
            $this->writeIndex();
            $index=max(array_keys($this->nameToKey));
        }
        $this->setVar($index,$value);
    }
    public function __unset($var) {
        if($index=$this->__isset($var)) {
            unset($this->nameToKey[$index]);
            $this->writeIndex();
            shm_remove_var($this->mem,$index);
        }
    }

    /**
     * get the maximum sze for a shared memory segment
     *
     * @return int size in bytes
     */
    public static function getMaxSize() {
        return (int)`cat /proc/sys/kernel/shmmax`;
    }
    public static function MiB($count) {
        return $count<<20;
    }
    public static function KiB($count) {
        return $count<<10;
    }

    /**
     * set the maximum size for a shared memory segment
     *
     * requires root privileges
     *
     * @param $bytes number of new size in bytes
     * @return bool whether the value was accepted or not
     */
    public static function setMaxSize($bytes) {
        $bytes=(int)$bytes;
        `echo $bytes >/proc/sys/kernel/shmmax`;
        return $bytes==static::getMaxSize();
    }

    /**
     * transact with the memory by callback
     *
     * locks the memory before call and releases afterwards
     * @param $cb callable
     * @return static
     */
    public function transact($cb) {
        $this->beginTransaction();
        $cb($this);
        $this->endTransaction();
        return $this;
    }
    public function __wakeup() {
        parent::__wakeup();
        $this->mem=shm_attach($this->getKey(),1,0777);
        $this->refreshIndex();
    }

    /**
     * transact with a memory-variable by callback
     * @param $var string the variable name
     * @param $cb callback return a new value or modify it by reference; return will be preferred for write back
     * @return static
     */
    public function transactVar($var,$cb) {
        return $this->transact(function($m)use($var,$cb){
            $val = $ref = $m->$var;
            $ret=$cb($ref);
            if($ret!==null) return $m->$var=$ret;
            if($ref!=$val) return $m->$var = $ref;
        });
    }
}

/** tests **/
/**


$mem=new sharedMemory(sharedMemory::getMaxSize());
// the way to do it in parallel processes
$mem->beginTransaction();
$mem->myVar='i am in the shared memory now';
$mem->endTransaction();

// this won't work:
$mem->arr = array();
$mem->arr[] = 'value'; // magic-issue about arrays
print_r($mem->arr);

// but this will as it's an object
$mem->nextStorage = new sharedMemory();
$mem->nextStorage->remove();

// change value by cb using a return value
$mem->transactVar('myVar',function($value){
	if(stripos($value,'shared memory')) return 'i was changed by a callback';
	return $value;
});
// change value by cb using a reference
echo $mem->myVar.PHP_EOL;
$mem->transactVar('myVar',function(&$val){
    $val=10;
    // returning something<>10 here would set the value to that
});
echo $mem->myVar.PHP_EOL;

// get instance by key
$mem2=sharedMemory::load($mem->getKey());
print_r($mem2->arr);
unset($mem2->arr);

// free the memory
$mem->remove();

/**/
