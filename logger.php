<?php
require 'utils.php';
require 'config.php';
require 'installer.php';
require 'requirements.php';
require 'requirementscheck.php';
require 'ziparchiveexternal.php';
require 'runtime.php';
require 'controller.php';
require 'jsonresponse.php';
require 'cpanel.php';
class Logger
{
    /** @var string */
    public $name;
    /** @var array */
    // change public => protected, coz we dont want anyone not in the class to access it!
    protected $log;

    public function __construct(string $name)
    {
        $this->name = $name;
        // make it an array when created!
        $this->log = array();
    }

    public function addMessage(string $message)
    {
        // adding a message to the log which should not be acceible
        $this->log[] = $message;
    }

    // returns array | boolean
    public function displayLog() {
      return $this->log;
      if (count($this->log) == 0) return false;
    }
}
 ?>
