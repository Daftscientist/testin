<?php
require 'installer.php';
require 'config.php';
require 'logger.php';
require 'requirements.php';
require 'requirementscheck.php';
require 'ziparchiveexternal.php';
require 'runtime.php';
require 'controller.php';
require 'jsonresponse.php';
require 'cpanel.php';

function password(int $length)
{
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_-=+;:,.?';

    return substr(str_shuffle($chars), 0, $length);
}
function dump()
{
    echo '<pre>';
    foreach (func_get_args() as $value) {
        print_r($value);
    }
    echo '</pre>';
}
function append(string $filename, string $contents)
{
    prepareDirFor($filename);
    if (false === @file_put_contents($filename, $contents, FILE_APPEND)) {
        throw new RuntimeException('Unable to append content to file ' . $filename);
    }
}
function put(string $filename, string $contents)
{
    prepareDirFor($filename);
    if (false === @file_put_contents($filename, $contents)) {
        throw new RuntimeException('Unable to put content to file ' . $filename);
    }
}

function prepareDirFor(string $filename)
{
    if (!file_exists($filename)) {
        $dirname = dirname($filename);
        if (!file_exists($dirname)) {
            createPath($dirname);
        }
    }
}

function createPath(string $path): string
{
    if (!mkdir($path, 0777, true)) {
        throw new RuntimeException('Unable to create path ' . $path);
    }
    return $path;
}
 ?>
