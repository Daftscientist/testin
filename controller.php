<?php
require 'utils.php';
require 'config.php';
require 'logger.php';
require 'requirements.php';
require 'requirementscheck.php';
require 'ziparchiveexternal.php';
require 'runtime.php';
require 'installer.php';
require 'jsonresponse.php';
require 'cpanel.php';

class Controller
{
    /** @var array */
    public $params;

    /** @var string */
    public $response;

    /** @var array */
    public $data;

    /** @var Runtime */
    public $runtime;

    public function __construct(array $params, Runtime $runtime)
    {
        $this->runtime = $runtime;
        if (!$params['action']) {
            throw new Exception('Missing action parameter', 400);
        }
        $this->params = $params;
        $method = $params['action'] . 'Action';
        if (!method_exists($this, $method)) {
            throw new Exception('Invalid action ' . $params['action'], 400);
        }
        $this->{$method}($this->params);
    }

    public function checkLicenseAction(array $params)
    {
        $post = $this->curl(VENDOR['apiLicense'], [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['license' => $params['license']]),
        ]);
        if ($post->json->error) {
            throw new Exception($post->json->error->message, 403);
        }
        $this->response = 200 == $this->code ? 'Valid license key' : 'Unable to check license';
    }

    public function checkDatabaseAction(array $params)
    {
        try {
            $database = new Database(
                $params['host'],
                $params['port'],
                $params['name'],
                $params['user'],
                $params['userPassword']
            );
            $database->checkEmpty();
            $database->checkPrivileges();
            $this->code = 200;
            $this->response = sprintf('Database %s OK', $params['name']);
        } catch (Exception $e) {
            throw new Exception($e->getMessage(), 503);
        }
    }

    public function cPanelProcessAction(array $params)
    {
        try {
            $cpanel = new Cpanel($params['user'], $params['password']);
            $createDb = $cpanel->setupMysql();
            $this->code = 200;
            $this->response = 'cPanel process completed';
            $this->data['db'] = $createDb;
            // [name] =>
            // [user] =>
            // [user_password] =>
        } catch (Exception $e) {
            throw new Exception($e->getMessage(), 503);
        }
    }

    public function cPanelHtaccessHandlersAction(array $params)
    {
        $filePath = $this->runtime->absPath . '.htaccess';
        if (!@is_readable($filePath)) {
            $this->code = 404;
            $this->response = 'No .htaccess found';

            return;
        }
        try {
            if ($handlers = Cpanel::getHtaccessHandlers($filePath)) {
                $this->code = 200;
                $this->response = 'cPanel .htaccess handlers found';
                $this->data['handlers'] = trim($handlers);
            } else {
                $this->code = 404;
                $this->response = 'No cPanel .htaccess handlers found';
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage(), 503);
        }
    }

    public function downloadAction(array $params)
    {
        $fileBasename = 'chevereto-pkg-' . substr(bin2hex(random_bytes(8)), 0, 8) . '.zip';
        $filePath = $this->runtime->absPath . $fileBasename;
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
        $isPost = false;
        $zipball = APPLICATIONS[$params['software']]['zipball'];
        if (!$zipball) {
            throw new Exception('Invalid software parameter', 400);
        }
        if ($params['software'] == 'chevereto') {
            $isPost = true;
        } else {
            $params = null;
            $get = $this->curl($zipball);
            $zipball = $get->json->zipball_url;
        }
        $curl = $this->downloadFile($zipball, $params, $filePath, $isPost);
        // Default chevereto.com API handling
        if ($curl->json->error) {
            throw new Exception($curl->json->error->message, $curl->json->status_code);
        }
        // Everybody else
        if (200 != $curl->transfer['http_code']) {
            throw new Exception('[HTTP ' . $curl->transfer['http_code'] . '] ' . $zipball, $curl->transfer['http_code']);
        }
        $fileSize = filesize($filePath);
        $this->response = strtr('Downloaded %f (%w @%s)', array(
            '%f' => $fileBasename,
            '%w' => $this->getFormatBytes($fileSize),
            '%s' => $this->getBytesToMb($curl->transfer['speed_download']) . 'MB/s.',
        ));
        $this->data['fileBasename'] = $fileBasename;
        $this->data['filePath'] = $filePath;
    }

    public function extractAction(array $params)
    {
        if (!$params['software']) {
            throw new Exception('Missing software parameter', 400);
        } elseif (!isset(APPLICATIONS[$params['software']])) {
            throw new Exception(sprintf('Unknown software %s', $params['software']), 400);
        }

        $software = APPLICATIONS[$params['software']];

        if (!$params['workingPath']) {
            throw new Exception('Missing workingPath parameter', 400);
        }
        $workingPath = $params['workingPath'];
        if (!file_exists($workingPath) && !@mkdir($workingPath)) {
            throw new Exception(sprintf("Working path %s doesn't exists and can't be created", $workingPath), 503);
        }
        if (!is_readable($workingPath)) {
            throw new Exception(sprintf('Working path %s is not readable', $workingPath), 503);
        }

        $filePath = $params['filePath'];
        if (!is_readable($filePath)) {
            throw new Exception(sprintf("Can't read %s", basename($filePath)), 503);
        }
        $zipExt = new ZipArchiveExt();
        $timeStart = microtime(true);
        $zipOpen = $zipExt->open($filePath);
        if (true !== $zipOpen) {
            throw new Exception(strtr("Can't extract %f - %m (ZipArchive #%z)", array(
                '%f' => $filePath,
                '%m' => 'ZipArchive ' . $zipOpen . ' error',
                '%z' => $zipOpen,
            )), 503);
        }
        $numFiles = $zipExt->numFiles - 1; // because of top level folder
        $folder = $software['folder'];
        if ($params['software'] == 'chevereto-free') {
            $comment = $zipExt->getArchiveComment();
            $folder = str_replace('/', '-', $folder) . substr($comment, 0, 7);
        }
        $extraction = $zipExt->extractSubdirTo($workingPath, $folder);
        if (!empty($extraction)) {
            throw new Exception(implode(', ', $extraction));
        }
        $zipExt->close();
        $timeTaken = round(microtime(true) - $timeStart, 2);
        @unlink($filePath);

        $htaccessFiepath = $workingPath . '.htaccess';
        if ($params['appendHtaccess'] && file_exists($htaccessFiepath)) {
            file_put_contents($htaccessFiepath, "\n\n" . $params['appendHtaccess'], FILE_APPEND | LOCK_EX);
        }

        $this->code = 200;
        $this->response = strtr('Extraction completeted (%n files in %ss)', ['%n' => $numFiles, '%s' => $timeTaken]);
    }

    public function createSettingsAction(array $params)
    {
        $settings = [];
        foreach ($params as $k => $v) {
            $settings["%$k%"] = $v;
        }
        $template = '<' . "?php
\$settings['db_host'] = '%host%';
\$settings['db_port'] = '%port%';
\$settings['db_name'] = '%name%';
\$settings['db_user'] = '%user%';
\$settings['db_pass'] = '%userPassword%';
\$settings['db_table_prefix'] = 'chv_';
\$settings['db_driver'] = 'mysql';
\$settings['db_pdo_attrs'] = [];
\$settings['debug_level'] = 1;";
        $php = strtr($template, $settings);
        put($params['filePath'], $php);
        $this->code = 200;
        $this->response = 'Settings file OK';
    }

    public function submitInstallFormAction(array $params)
    {
        $installUrl = $this->runtime->rootUrl . 'install';
        if (0 === strpos($this->runtime->server['SERVER_SOFTWARE'], 'PHP')) {
            throw new Exception('Unable to submit the installation form under PHP development server. Go to ' . $installUrl . ' to complete the process.', 501);
        }
        $post = $this->curl($installUrl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
        ]);
        if ($post->json->error) {
            throw new Exception($post->json->error->message, $post->json->error->code);
        }
        if (preg_match('/system error/i', $post->raw)) {
            throw new Exception('System error :(', 400);
        }
        if (preg_match('/<p class="highlight\s.*">(.*)<\/p>/', $post->raw, $post_errors)) {
            throw new Exception(strip_tags(str_replace('<br><br>', ' ', $post_errors[1])), 400);
        }
        $this->code = 200;
        $this->response = 'Setup complete';
    }

    public function selfDestructAction()
    {
        $filePath = $this->runtime->installerFilepath;
        $basename = basename($filePath);
        $isDone = 'app.php' == $basename ?: @unlink($filePath);
        if ($isDone) {
            $this->code = 200;
            $this->response = 'Installer removed';
        } else {
            $this->code = 503;
            $this->response = 'Unable to remove installer file at ' . $filePath;
        }
    }

    /**
     * @param string $url      Target download URL
     * @param string $params   Request params
     * @param string $filePath Location to save the downloaded file
     * @param bool   $post     TRUE to download using a POST request
     * @param return curl handle
     */
    public function downloadFile(string $url, array $params = null, string $filePath, bool $post = true)
    {
        $fp = @fopen($filePath, 'wb+');
        if (!$fp) {
            throw new Exception("Can't open temp file " . $filePath . ' (wb+)');
        }
        $ops = [
            CURLOPT_FILE => $fp,
        ];
        if ($params) {
            $ops[CURLOPT_POSTFIELDS] = http_build_query($params);
        }
        if ($post) {
            $ops[CURLOPT_POST] = true;
        }
        $curl = $this->curl($url, $ops);
        fclose($fp);

        return $curl;
    }

    /**
     * @return array [transfer =>, tmp_file_path =>, raw =>, json =>,]
     */
    public function curl(string $url, array $curlOpts = [])
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_AUTOREFERER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_FAILONERROR, 0);
        curl_setopt($ch, CURLOPT_VERBOSE, 0);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Chevereto Installer');
        foreach ($curlOpts as $k => $v) {
            if (CURLOPT_FILE == $k) {
                $fp = $v;
            }
            curl_setopt($ch, $k, $v);
        }
        $file_get_contents = @curl_exec($ch);
        $transfer = curl_getinfo($ch);
        if (curl_errno($ch)) {
            $curl_error = curl_error($ch);
            curl_close($ch);
            throw new Exception('Curl error ' . $curl_error, 503);
        }
        curl_close($ch);
        $return = new stdClass();
        if (is_resource($fp)) {
            rewind($fp);
            $return->raw = stream_get_contents($fp);
        } else {
            $return->raw = $file_get_contents;
        }
        if (false !== strpos($transfer['content_type'], 'application/json')) {
            $return->json = json_decode($return->raw);
            if (is_resource($fp)) {
                $meta_data = stream_get_meta_data($fp);
                @unlink($meta_data['uri']);
            }
        }
        $this->code = $transfer['http_code'];
        if (200 != $this->code && !isset($return->json)) {
            $return->json = new stdClass();
            $return->json->error = new stdClass();
            $return->json->error->message = 'Error performing HTTP request';
            $return->json->error->code = $this->code;
        }
        $return->transfer = $transfer;

        return $return;
    }

    /**
     * @param string $bytes bytes to be formatted
     * @param int    $round how many decimals you want to get, default 1
     *
     * @return string formatted size string like 10 MB
     */
    public function getFormatBytes($bytes, $round = 1)
    {
        if (!is_numeric($bytes)) {
            return false;
        }
        if ($bytes < 1000) {
            return "$bytes B";
        }
        $units = array('KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
        foreach ($units as $k => $v) {
            $multiplier = pow(1000, $k + 1);
            $threshold = $multiplier * 1000;
            if ($bytes < $threshold) {
                $size = round($bytes / $multiplier, $round);

                return "$size $v";
            }
        }
    }

    /**
     * Converts bytes to MB.
     *
     * @param string $bytes bytes to be formatted
     *
     * @return float MB representation
     */
    public function getBytesToMb($bytes, $round = 2)
    {
        $mb = $bytes / pow(10, 6);
        if ($round) {
            $mb = round($mb, $round);
        }

        return $mb;
    }
}
 ?>
