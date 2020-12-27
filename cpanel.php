<?php
require 'utils.php';
require 'config.php';
require 'logger.php';
require 'requirements.php';
require 'requirementscheck.php';
require 'ziparchiveexternal.php';
require 'runtime.php';
require 'controller.php';
require 'jsonresponse.php';
require 'installer.php';
/**
 * A PHP client for cPanel UAPI.
 */
class Cpanel
{
    /** @var string cPanel UAPI module/function */
    public $action;

    /** @var array */
    public $response;

    /** @var string */
    public $errorMessage;

    /** @var string user:password */
    protected $userpwd;

    /** @var string */
    protected $mysqlPrefix;

    /** @var int */
    protected $mysqlMaxDbNamelength;

    /** @var int */
    protected $mysqlMaxUsernameLength;

    public function __construct(string $user, string $password)
    {
        $this->userpwd = "$user:$password";
    }

    public function sendRequest(string $action, array $params = [])
    {
        // cPanel UAPI accepts session login (cookie needed).
        // cPanel API Tokens aren't widely supported yet

        $url = 'https://localhost:2083';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_NOBODY, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_AUTOREFERER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (200 != $httpCode) {
            throw new Exception(strtr('Unable to connect to cPanel at %s [HTTP %c]', [
                '%s' => $url,
                '%c' => $httpCode,
            ]), 503);
        }

        $endpoint = $url . '/execute/' . $action;

        $url = $endpoint . '?' . http_build_query($params);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->userpwd);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_AUTOREFERER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_URL, $url);
        $result = curl_exec($ch);
        if ($result == false) {
            throw new Exception('curl_exec threw error "' . curl_error($ch) . "\" for $action");
        }
        curl_close($ch);
        $array = json_decode($result, false);
        if (!$array) {
            throw new Exception("Can't authenticate to cPanel host (wrong username:password)", 403);
        }
        $this->action = $action;
        $this->response = $array;
        $this->isSuccess = 1 == $array->status;
        if (!$this->isSuccess) {
            $this->errorMessage = implode('-', $this->response->errors);
        }
    }

    /**
     * Creates a MySQL database, its user and set privileges.
     *
     * @param string $prefix vendor prefix
     */
    public function setupMysql(string $prefix = null)
    {
        $this->sendRequest('Mysql/get_restrictions');
        // ^^^ response->data:
        // prefix => chevereto_
        // max_database_name_length => 64
        // max_username_length => 47

        if (!$this->isSuccess) {
            throw new Exception($this->errorMessage);
        }

        $this->mysqlPrefix = $this->response->data->prefix;
        $this->mysqlMaxDbNamelength = $this->response->data->max_database_name_length;
        $this->mysqlMaxUsernameLength = $this->response->data->max_username_length;

        $dbPrefix = $this->mysqlPrefix . $prefix;

        for ($i = 0; $i < 5; ++$i) {
            $dbName = static::getDbRandomName($dbPrefix, $this->mysqlMaxDbNamelength);
            $this->sendRequest('Mysql/check_database', ['name' => $dbName]);
            if (!$this->isSuccess) { // No DB = profit
                break;
            } else {
                if ($i == 4) {
                    throw new Exception('Unable to determine a valid MySQL database name', 201);
                }
            }
        }

        $this->sendRequest('Mysql/create_database', ['name' => $dbName]);
        if (!$this->isSuccess) {
            throw new Exception($this->errorMessage);
        }

        $dbUserPassword = password(16);
        for ($i = 0; $i < 5; ++$i) {
            $dbUser = static::getDbRandomName($dbPrefix, $this->mysqlMaxUsernameLength);
            $this->sendRequest('Mysql/create_user', [
                'name' => $dbUser,
                'password' => $dbUserPassword,
            ]);
            if ($this->isSuccess) {
                break;
            } else {
                if ($i == 4) {
                    throw new Exception('Unable to create the MySQL database user', 202);
                }
            }
        }

        $this->sendRequest('Mysql/set_privileges_on_database', [
            'user' => $dbUser,
            'database' => $dbName,
            'privileges' => 'ALL PRIVILEGES',
        ]);
        if (!$this->isSuccess) {
            throw new Exception($this->errorMessage);
        }

        return [
            'host' => 'localhost',
            'port' => '3306',
            'name' => $dbName,
            'user' => $dbUser,
            'userPassword' => $dbUserPassword,
        ];
    }

    public static function getHtaccessHandlers(string $filepath)
    {
        $contents = file_get_contents($filepath);
        preg_match_all('/# php -- BEGIN cPanel-generated handler, do not edit[\s\S]+# php -- END cPanel-generated handler, do not edit/', $contents, $matches);
        if ($matches) {
            return $matches[0][0];
        }
    }

    public static function getDbRandomName(string $prefix, int $maxLength)
    {
        $maxRandomLength = $maxLength - strlen($prefix);
        if ($maxRandomLength <= 0) {
            return $prefix;
        }
        $randomLength = min(5, $maxRandomLength);

        return $prefix . substr(bin2hex(random_bytes($randomLength)), 0, $randomLength);
    }
}

 ?>
