<?php
/**
 * Created by PhpStorm.
 * User: abewm
 * Date: 7/17/14
 * Time: 5:42 PM
 */

class semaphore {
    /** @var null|resource the semaphore */
    private $sem=null;
    /** @var null|int ipc-v-key for memory-block and semaphore */
    protected $key=null;

    public function __construct($locks=1,$perms=0600) {
        if (!$this->sem=sem_get($this->getKey(),$locks,$perms))
            throw new Exception('Could not get semaphore:'.print_r($this,1));
    }
    public function getKey() {
        return isset($this->key)?$this->key:$this->key=ftok(tempnam('/tmp','SEM'),'a');
    }
    public function remove() {
        if(!sem_remove($this->sem))
            throw new Exception('could not remove shared memory segment completely.');
    }
    public function __sleep() {
        return array('key');
    }
    public function __wakeup() {
        if(!$this->sem=sem_get($this->getKey()))
            throw new Exception('Could not get semaphore');
    }
    public static function load($key) {
        $rC=new ReflectionClass($c=get_called_class());
        /** @var static $i */
        $i=$rC->newInstanceWithoutConstructor();
        $rP=new ReflectionProperty($c,'key');
        $rP->setAccessible(true);
        $rP->setValue($i,$key);
        $rP->setAccessible(false);
        $i->__wakeup();
        return $i;
    }
    public function lock() {
        if(!sem_acquire($this->sem))
            throw new Exception('Could not acquire semaphore');
    }
    public function unlock() {
        sem_release($this->sem);
    }
} 