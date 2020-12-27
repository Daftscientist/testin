<?php
require 'utils.php';
require 'config.php';
require 'logger.php';
require 'installer.php';
require 'requirementscheck.php';
require 'ziparchiveexternal.php';
require 'runtime.php';
require 'controller.php';
require 'jsonresponse.php';
require 'cpanel.php';

class Requirements
{
    /** @var array */
    public $phpVersions;

    /** @var array */
    public $phpExtensions;

    /** @var array */
    public $phpClasses;

    /**
     * @param array $phpVersions an array listing the minimum PHP version followed by the recommended PHP version
     */
    public function __construct(array $phpVersions)
    {
        $this->phpVersions = $phpVersions;
    }

    public function setPHPExtensions(array $phpExtensions)
    {
        $this->phpExtensions = $phpExtensions;
    }

    public function setPHPClasses(array $phpClasses)
    {
        $this->phpClasses = $phpClasses;
    }
}
 ?>
