<?php
require 'utils.php';
require 'config.php';
require 'logger.php';
require 'requirements.php';
require 'requirementscheck.php';
require 'ziparchiveexternal.php';
require 'installer.php';
require 'controller.php';
require 'jsonresponse.php';
require 'cpanel.php';

class Runtime
{
    /** @var array Runtime settings */
    public $settings;

    /** @var Logger */
    protected $logger;

    /** @var string Working path (absolute) */
    public $absPath;

    /** @var string Working path (relative) */
    public $relPath;

    /** @var string Path to this installer file (absolute) */
    public $installerFilepath;

    /** @var string HTTP hostname */
    public $httpHost;

    /** @var string HTTP protocol (http, https) */
    public $httpProtocol;

    /** @var string Root URL for the current project */
    public $rootUrl;

    /** @var string Human-readable server information */
    public $serverString;

    /** @var array */
    public $workingPaths;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function setSettings(array $settings)
    {
        $this->settings = $settings;
    }

    public function setServer(array $server)
    {
        $this->server = $server;
    }

    public function run()
    {
        error_reporting($this->settings['error_reporting']);
        $this->applyPHPSettings($this->settings);
        $this->processContext();
    }

    protected function applyPHPSettings(array $settings)
    {
        $runtimeTable = [
            'log_errors' => ini_set('log_errors', (string) $settings['log_errors']),
            'display_errors' => ini_set('display_errors', (string) $settings['display_errors']),
            'error_log' => ini_set('error_log', $settings['error_log']),
            'set_time_limit' => set_time_limit($settings['time_limit']),
            'ini_set' => ini_set('default_charset', $settings['default_charset']),
            'setlocale' => setlocale(LC_ALL, $settings['LC_ALL']),
        ];
        $messageTemplate = 'Unable to set %k value %v (FALSE return value)';
        foreach ($runtimeTable as $k => $v) {
            if (false === $v) {
                $this->logger->addMessage(strtr($messageTemplate, [
                    '%k' => $k,
                    '%v' => var_export($settings[$k], true),
                ]));
            }
        }
    }

    protected function processContext()
    {
        if (!isset($this->server)) {
            $this->setServer($_SERVER);
        }
        $this->php = phpversion();
        $this->absPath = rtrim(str_replace('\\', '/', dirname(INSTALLER_FILEPATH)), '/') . '/';
        $this->relPath = rtrim(dirname($this->server['SCRIPT_NAME']), '\/') . '/';
        $this->installerFilename = basename(INSTALLER_FILEPATH);
        $this->installerFilepath = INSTALLER_FILEPATH;
        $this->httpHost = $this->server['HTTP_HOST'];
        $this->serverSoftware = $this->server['SERVER_SOFTWARE'];
        $httpProtocol = 'http';
        $isHttpsOn = !empty($this->server['HTTPS']) && strtolower($this->server['HTTPS']) == 'on';
        $isHttpsX = isset($this->server['HTTP_X_FORWARDED_PROTO']) && $this->server['HTTP_X_FORWARDED_PROTO'] == 'https';
        if ($isHttpsOn || $isHttpsX) {
            $httpProtocol .= 's';
        }
        $this->httpProtocol = $httpProtocol;
        $this->rootUrl = $this->httpProtocol . '://' . $this->httpHost . $this->relPath;
        $this->serverString = 'Server ' . $this->httpHost . ' PHP ' . phpversion();
        $this->setWorkingPaths([INSTALLER_FILEPATH, $this->absPath]);
    }

    protected function setWorkingPaths(array $workingPaths)
    {
        $this->workingPaths = $workingPaths;
    }
}
 ?>
