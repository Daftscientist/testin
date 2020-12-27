<?php
/* --- Begins: Dev editable --- */
const APP_NAME = 'Chevereto Installer';
const APP_VERSION = '2.0.1';
const APP_URL = 'https://github.com/chevereto/installer';

const PHP_VERSION_MIN = '7.2';
const PHP_VERSION_RECOMMENDED = '7.4';

const VENDOR = [
    'name' => 'Chevereto',
    'url' => 'https://chevereto.com',
    'apiUrl' => 'https://chevereto.com/api',
    'apiLicense' => 'https://chevereto.com/api/license/check',
];

const APPLICATIONS = [
    'chevereto' => [
        'name' => 'Chevereto',
        'license' => 'Paid',
        'url' => 'https://chevereto.com',
        'zipball' => 'https://chevereto.com/api/download/latest',
        'folder' => 'chevereto',
        'vendor' => VENDOR,
    ],
    'chevereto-free' => [
        'name' => 'Chevereto-Free',
        'license' => 'Open Source',
        'url' => 'https://github.com/Chevereto/Chevereto-Free',
        'zipball' => 'https://api.github.com/repos/Chevereto/Chevereto-Free/releases/latest',
        'folder' => 'Chevereto/Chevereto-Free-',
        'vendor' => VENDOR,
    ],
];

$patterns = [
    'username_pattern' => '^[\w]{3,16}$',
    'user_password_pattern' => '^.{6,128}$',
    'email_pattern' => "^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+$",
];

$phpSettings = [
    'error_reporting' => E_ALL ^ E_NOTICE,
    'log_errors' => true,
    'display_errors' => true,
    'error_log' => __DIR__ . '/installer.error.log',
    'time_limit' => 0,
    'default_charset' => 'utf-8',
    'LC_ALL' => 'en_US.UTF8',
];

$phpExtensions = [
    'curl',
    'hash',
    'json',
    'mbstring',
    'PDO',
    'PDO_MYSQL',
    'session',
];

$phpClasses = [
    'DateTime',
    'DirectoryIterator',
    'Exception',
    'PDO',
    'PDOException',
    'RegexIterator',
    'RecursiveIteratorIterator',
    'ZipArchive',
];

$themeColor = '#ecf0f1';

/** @var string a base64 image */
$shortcutIcon = 'data:image/png;base64,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';

/* --- Ends: Dev editable --- */

define('ERROR_LOG_FILEPATH', $phpSettings['error_log']);
const INSTALLER_FILEPATH = __FILE__;

const ERROR_TABLE = [
    E_ERROR => 'Fatal error',
    E_WARNING => 'Warning',
    E_PARSE => 'Parse error',
    E_NOTICE => 'Notice',
    E_CORE_ERROR => 'Core error',
    E_CORE_WARNING => 'Core warning',
    E_COMPILE_ERROR => 'Compile error',
    E_COMPILE_WARNING => 'Compile warning',
    E_USER_ERROR => 'Fatal error',
    E_USER_WARNING => 'Warning',
    E_USER_NOTICE => 'Notice',
    E_STRICT => 'Strict standars',
    E_RECOVERABLE_ERROR => 'Recoverable error',
    E_DEPRECATED => 'Deprecated',
    E_USER_DEPRECATED => 'Deprecated',
];

 ?>
