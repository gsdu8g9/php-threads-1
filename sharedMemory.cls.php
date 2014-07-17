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

class sharedMemory {
    /** @var null|resource the semaphore */
    protected $sem=null;
    /** @var null|resource the memory-segment */
    protected $mem=null;
    /** @var string[] name-index-associations */
    protected $nameToKey=array();
    /** @var null|int ipc-v-key for memory-block and semaphore */
    protected $key=null;

    public function beginTransaction() {
        // sem_acquire blocks until the segment is accessible
        if(!sem_acquire($this->sem))
            throw new Exception('Could not acquire semaphore');
        return $this;
    }
    public function endTransaction() {
        if(!sem_release($this->sem))
            throw new Exception('Could not release semaphore');
        return $this;
    }
    public function __construct($size=10000) {
        if(!$this->sem=sem_get($this->getKey()))
            throw new Exception('Could not get semaphore');
        $this->mem=shm_attach($this->getKey(),$size,0640);
        // store index in first place
        $this->nameToKey[]='';
        // here_we'll save the number of instances
        // $this->nameToKey[]='';
        $this->writeIndex();
    }
    public function getKey() {
        return isset($this->key)?$this->key:$this->key=ftok(tempnam('/tmp','SEM'),'a');
    }
    public function remove() {
        $rShm=shm_remove($this->mem);
        $rSem=sem_remove($this->sem);
        if(!($rSem&&$rShm))
            throw new Exception('could not remove shared memory segment completely.');
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
    public function transact($cb) {
        $this->beginTransaction();
        $cb($this);
        $this->endTransaction();
        return $this;
    }
    public function transactVar($varname,$cb) {
        return $this->transact(function($m)use($varname,$cb){
            $m->$varname=$cb($m->$varname);
        });
    }
    public function __sleep() {
        return array('key');
    }
    public function __wakeup() {
        print_r($this);
        if(!$this->sem=sem_get($this->getKey()))
            throw new Exception('Could not get semaphore');
        $this->mem=shm_attach($this->getKey(),1,0777);
        $this->refreshIndex();
    }
}

/** tests **/
/**

$mem=new sharedMemory(sharedMemory::getMaxSize());
$mem->beginTransaction();
$mem->myVar='i am in the shared memory now';
$mem->endTransaction();

// this won't work:
$mem->arr = array();
$mem->arr[] = 'value'; // magic-issue about arrays
print_r($mem);
unset($mem->arr);

// but this will
$mem->nextStorage = new sharedMemory();
$mem->nextStorage->remove();

$mem->transactVar('myVar',function($value){
	if(stripos($value,'shared memory')) return 'i was changed by a callback';
	// warning: you have to return something; else you would set the variable to null!
	return $value;
});
echo $mem->myVar.PHP_EOL;
$mem->remove();

*/
