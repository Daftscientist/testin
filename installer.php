<?php
declare(strict_types=1);
require 'utils.php';
require 'config.php';
require 'logger.php';
require 'requirements.php';
require 'requirementscheck.php';
require 'ziparchiveexternal.php';
require 'runtime.php';
require 'controller.php';
require 'jsonresponse.php';
require 'cpanel.php';

set_error_handler(function (int $severity, string $message, string $file, int $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
}, $phpSettings['error_reporting']);

set_exception_handler(function (Throwable $e) {
    $trace = $e->getTrace();
    $traceTemplate = "#%k% %file%:%line%\n%class%%type%%function%%args%";
    $argsTemplate = "Arg#%k%\n%arg%";

    switch (true) {
        case $e instanceof ErrorException:
            $type = ERROR_TABLE[$e->getSeverity()];
            $thrown = $e->getMessage();

            array_shift($trace);
            array_shift($trace);
            break;
        case $e instanceof Error:
            $type = 'PHP';
            $thrown = $e->getMessage();
            break;
        default:
            $type = 'Exception';
            $thrown = $type . ' thrown';
            break;
    }
    $message = 'in ' . $e->getFile() . ':' . $e->getLine();
    $retrace = [];
    foreach ($trace as $k => $v) {
        $args = [];
        foreach ($v['args'] as $ak => $av) {
            $arg = var_export($av, true);
            $args[] = strtr($argsTemplate, [
                '%k%' => $ak,
                '%arg%' => $arg,
            ]);
        }
        $retrace[] = strtr($traceTemplate, [
            '%k%' => $k,
            '%file%' => $v['file'] ?? '',
            '%line%' => $v['line'] ?? '',
            '%class%' => $v['class'] ?? '',
            '%type%' => $v['type'] ?? '',
            '%function%' => $v['function'] ?? '',
            '%args%' => empty($args) ? '' : ("\n--\n" . implode("\n--\n", $args)),
        ]);
    }
    $cols = 80;
    $hypens = str_repeat('-', $cols);
    $halfHypens = substr($hypens, 0, $cols / 2);
    $stack = implode("\n$halfHypens\n", $retrace);
    $tags = [
        '%type%' => $type,
        '%datetime%' => date('Y-m-d H:i:s'),
        '%thrown%' => $thrown,
        '%message%' => $message,
        '%stack%' => $stack,
        '%trace%' => empty($retrace) ? '' : "Trace:\n"
    ];
    $screenTpl = '<h1>[%type%] %thrown%</h1><p>%message%</p>' . "\n\n" . "%trace%<pre><code>%stack%</code></pre>";
    $textTpl = "%datetime% [%type%] %thrown%: %message%\n\n%trace%%stack%";

    $text = "$hypens\n" . strtr($textTpl, $tags) . "\n$hypens\n\n";

    append(ERROR_LOG_FILEPATH, $text);

    echo strtr($screenTpl, $tags);
    die();
});

$logger = new Logger(APP_NAME . ' ' . APP_VERSION);
$requirements = new Requirements([PHP_VERSION_MIN, PHP_VERSION_RECOMMENDED]);
$requirements->setPHPExtensions($phpExtensions);
$requirements->setPHPClasses($phpClasses);

$runtime = new Runtime($logger);
$runtime->setSettings($phpSettings);
// $runtime->setServer([
//     'SCRIPT_NAME' => $_SERVER['SCRIPT_NAME'],
//     'HTTPS' => 'On',
//     'SERVER_SOFTWARE' => 'php-cli',
//     'SERVER_PROTOCOL' => 'PHP/CLI',
//     'HTTP_HOST' => 'php-cli',
//     'HTTP_X_FORWARDED_PROTO' => null,
// ]);
$runtime->run();

$requirementsCheck = new RequirementsCheck($requirements, $runtime);

if ('POST' === $_SERVER['REQUEST_METHOD']) {
    $jsonResponse = new JsonResponse();
    if ($requirementsCheck->errors) {
        $errorsPlain = array_map(function ($v) {
            return trim(strip_tags($v));
        }, $requirementsCheck->errors);
        $jsonResponse->setResponse('Missing server requirements', 500);
        $jsonResponse->addData('errors', $errorsPlain);
    } else {
        try {
            $controller = new Controller($_POST, $runtime);
            $jsonResponse->setResponse($controller->response, $controller->code);
            if ($controller->data) {
                $jsonResponse->setData($controller->data);
            }
        } catch (Throwable $e) {
            $jsonResponse->setResponse($e->getMessage(), $e->getCode());
        }
    }
    $jsonResponse->send();
    die();
} else {
    if (isset($_GET['getNginxRules'])) {
        header('Content-Type: text/plain');
printf('# Chevereto NGINX generated rules for ' . $runtime->rootUrl . '

# Context limits
client_max_body_size 20M;

# Disable access to sensitive files
location ~* ' . $runtime->relPath . '(app|content|lib)/.*\.(po|php|lock|sql)$ {
  deny all;
}

# Image not found replacement
location ~ \.(jpe?g|png|gif|webp)$ {
    log_not_found off;
    error_page 404 ' . $runtime->relPath . 'content/images/system/default/404.gif;
}

# CORS header (avoids font rendering issues)
location ~* ' . $runtime->relPath . '.*\.(ttf|ttc|otf|eot|woff|woff2|font.css|css|js)$ {
  add_header Access-Control-Allow-Origin "*";
}

# Pretty URLs
location ' . $runtime->relPath . ' {
  index index.php;
  try_files $uri $uri/ /index.php$is_args$query_string;
}

# END Chevereto NGINX rules
');
die();
    }
    $pageId = $requirementsCheck->errors ? 'error' : 'install';
    $doctitle = APP_NAME;
    $css = './index.css';
    $script = './app.js';
    $svgLogo = '<svg xmlns="http://www.w3.org/2000/svg" width="501.76" height="76.521" viewBox="0 0 501.76 76.521"><path d="M500.264 40.068c-.738 0-1.422.36-1.814.963-1.184 1.792-2.36 3.53-3.713 5.118-1.295 1.514-5.34 4.03-8.7 4.662l-1.33.25.16-1.35.15-1.28c.11-.91.22-1.78.29-2.65.55-6.7-.03-11.69-1.89-16.2-1.68-4.08-3.94-6.57-7.11-7.85-1.18-.48-2.28-.72-3.26-.72-2.17 0-3.93 1.17-5.39 3.58-.15.25-.29.5-.46.78l-.67 1.18-.91-.75c-.42-.34-.82-.67-1.23-1.01-.95-.79-1.86-1.54-2.8-2.26-.76-.57-1.64-1.07-2.56-1.59-2-1.13-4.09-1.71-6.23-1.71-3.87 0-7.81 1.898-10.81 5.22-4.91 5.42-7.86 12.11-8.77 19.86-.11.988-.39 2.278-1.48 3.478-3.63 3.98-7.97 8.45-13.69 11.29-1.23.61-2.73 1.01-4.34 1.18-.18.02-.36.03-.52.03-.85 0-1.5-.26-1.95-.76-.48-.54-.66-1.3-.55-2.32.26-2.26.59-4.67 1.26-6.99 1.08-3.75 2.27-7.53 3.43-11.19.6-1.91 1.2-3.83 1.79-5.74.33-1.09 1.01-1.6 2.2-1.648 1.47-.06 2.89-.13 4.23-.45 1.96-.45 3.37-1.37 4.08-2.65.72-1.31.75-3.03.09-4.99-.06-.17-.12-.33-.19-.49l-7.18.69.28-1.33c.13-.65.27-1.27.4-1.88.3-1.36.58-2.66.8-3.94.38-2.22.59-4.81-.65-7.19-1.38-2.64-4.22-4.28-7.42-4.28-.71 0-1.43.08-2.14.25-5.3 1.24-9.3 4.58-12.23 7.472l1.76 9.7-1 .16c-.5.09-.96.16-1.39.22-.86.13-1.6.24-2.31.42-1.852.46-3.04 1.23-3.55 2.29-.51 1.05-.36 2.47.43 4.22.14.33.31.64.47.94l6.39-1.15-.26 1.42c-.15.82-.28 1.63-.41 2.42-.5 3.15-.98 6.13-2.72 8.97-5.55 9.07-11.52 15.36-18.76 19.79-2.17 1.33-5.11 2.91-8.52 3.33-.73.09-1.45.14-2.14.14-3.55 0-6.56-1.14-8.7-3.29-2.12-2.13-3.22-5.13-3.2-8.69l.01-1.33 1.28.38c.4.13.8.25 1.2.38.75.23 1.48.46 2.23.67 1.58.432 3.22.65 4.85.65 10.22-.01 18.46-8.11 18.76-18.46.18-6.32-2.4-10.77-7.66-13.25-2.14-1-4.41-1.49-6.97-1.49-1.3 0-2.69.14-4.13.4-7.34 1.35-13.38 5.54-18.48 12.83-1.97 2.81-3.57 6.02-5.18 10.42-.58 1.58-1.48 3.22-2.75 5.01-2.09 2.96-4.72 6.32-8.29 8.82-1.36.96-2.86 1.65-4.33 2.01-.34.08-.69.12-1.02.12-1.04 0-1.96-.4-2.61-1.12-.65-.73-.94-1.73-.81-2.81.31-2.67.858-4.9 1.67-6.84.9-2.15 1.938-4.27 2.95-6.32.818-1.66 1.67-3.37 2.42-5.08 1.42-3.2 1.96-6.22 1.648-9.21-.51-4.88-3.73-7.79-8.6-7.79-.23 0-.46.01-.69.02-4.13.23-7.65 2.102-10.89 3.99-1.23.72-2.44 1.51-3.73 2.36-.62.41-1.26.83-1.94 1.27l-3.05 1.96 1.61-3.25.3-.62c.16-.33.29-.59.43-.84 1.98-3.67 3.93-7.67 4.76-11.97.28-1.43.35-2.91.21-4.26-.21-2.16-1.398-3.34-3.34-3.34-.43 0-.9.06-1.39.18-2.14.52-4.19 1.67-6.26 3.51-5.9 5.27-8.87 11.09-9.07 17.81-.1 3.61.95 6.16 3.63 8.812l.55.55-.39.67c-.41.7-.82 1.41-1.22 2.12-.91 1.59-1.84 3.23-2.87 4.8-4.81 7.33-10.32 12.82-16.84 16.77-2.35 1.43-5.21 2.93-8.53 3.32-.71.08-1.42.12-2.1.12-7.03 0-11.61-4.38-11.96-11.44-.01-.22.02-.39.05-.53l.03-.16.19-1.12 1.09.33c.41.13.82.26 1.22.39.85.272 1.65.53 2.46.73 1.51.38 3.04.57 4.57.57 5.5 0 10.75-2.47 14.39-6.78 3.57-4.23 5.1-9.76 4.18-15.17-1-5.92-5.9-10.45-11.92-11.01-.89-.08-1.77-.13-2.64-.13-7.96 0-14.79 3.6-20.89 11-2.38 2.88-4.05 6.21-5.83 9.95-1.62 3.4-4.72 5.48-6.9 6.75-2.02 1.16-3.8 1.7-5.61 1.7-.19 0-.38 0-.57-.01l-1.25-.08.35-1.2c.25-.82.5-1.64.74-2.44.55-1.79 1.07-3.47 1.5-5.2 1.29-5.29 1.44-9.6.47-13.57-1.08-4.36-3.94-6.77-8.07-6.77-.44 0-.9.03-1.37.09-2.13.24-3.89 1.46-5.36 3.71-2.4 3.69-3.45 8.14-3.28 14.02.16 5.512 1.48 10.012 4.03 13.73.36.53.52 1.48.16 2.12-1.64 2.79-3.59 5.6-6.77 7.2-1.34.67-2.68 1.01-3.99 1.01-2.72 0-5.11-1.44-6.74-4.06-1.76-2.83-2.68-6.14-2.82-10.13-.27-7.69 1.44-14.86 5.08-21.33l.06-.11c.09-.19.23-.48.5-.71.89-.77.87-1.33-.1-3-1.64-2.85-4.5-4.55-7.66-4.55-2.64 0-5.19 1.17-7.16 3.28-2.98 3.19-4.91 7.32-6.08 12.99-.34 1.65-.54 3.37-.74 5.04-.1.9-.21 1.8-.33 2.69-.08.52-.2 1.12-.53 1.63-5.58 8.48-11.85 14.45-19.18 18.28-2.98 1.55-5.75 2.31-8.48 2.31-1.44 0-2.88-.22-4.3-.64-4.8-1.46-7.88-6.03-7.65-11.38l.06-1.29 1.24.37c.39.12.77.24 1.16.37.75.23 1.5.47 2.26.68 1.58.43 3.21.65 4.84.65 10.23-.01 18.47-8.11 18.77-18.45.18-6.33-2.4-10.78-7.66-13.25-2.14-1.01-4.41-1.5-6.97-1.5-1.3 0-2.69.14-4.12.4-7.35 1.35-13.39 5.54-18.49 12.818-2.24 3.2-3.94 6.66-5.05 10.28-.91 2.93-2.81 5.13-4.66 7.26l-.08.1c-2.25 2.6-4.84 4.94-6.83 6.68-.8.69-2.03 1.15-3.67 1.35-.18.03-.34.04-.5.04-.99 0-1.56-.408-1.86-.76-.47-.54-.64-1.28-.51-2.2.31-2.228.71-3.988 1.25-5.54.71-2.028 1.49-4.068 2.24-6.04.92-2.398 1.87-4.89 2.69-7.358 1.65-4.92 1.24-9.02-1.24-12.56-2.04-2.92-5.1-4.28-9.62-4.28h-.25c-5.89.07-12.67.82-18.42 6.23-.22.21-.43.55-.67.87-.31.44-.14.21-.51.76l-.62.87-.01.05-.01-.02.02-.03.15-.56 1.02-3.63c.78-2.772 1.58-5.63 2.28-8.46l.31-1.24c.67-2.65 1.36-5.392 1.53-8.07.28-4.2-2.6-7.6-6.83-8.08-.21-.02-.38-.09-.52-.17h-2.23c-4.61 1.09-8.87 3.61-13.03 7.7-.06.06-.14.19-.18.29 1.58 4.22 1.42 8.61 1.05 12.35-.6 6.12-1.43 12.64-2.6 20.49-.25 1.64-1.26 3.12-2.17 4.46-5.48 8.01-11.74 13.82-19.14 17.75-3.46 1.84-6.46 2.71-9.5 2.72-5.04 0-9.46-3.61-10.51-8.6-1.06-4.98-.4-10.14 2.08-16.21 1.23-3.04 3.11-6.9 6.67-9.73.94-.75 2.14-1.34 3.38-1.66.5-.12.99-.19 1.45-.19 1.22 0 2.28.46 2.97 1.29.77.92 1.04 2.23.78 3.7-.37 2.04-1.07 4.02-1.82 6.04-.45 1.21-1.12 2.49-1.98 3.8-.24.36-.29.48.16.96 1.09 1.16 2.45 1.73 4.17 1.73.38 0 .8-.03 1.22-.09 3.31-.47 6.13-2.16 7.95-4.76 1.84-2.64 2.47-5.93 1.76-9.26-1.59-7.46-7.19-11.73-15.35-11.73-.24 0-.49 0-.74.01-7.16.22-13.41 3.26-18.56 9.05-7.46 8.37-10.91 17.96-10.26 28.49.5 8.02 4.09 13.48 10.67 16.21 2.57 1.07 5.31 1.59 8.38 1.59 1.5 0 3.11-.13 4.78-.38 8.69-1.33 16.43-5.43 24.38-12.88.89-.83 1.8-1.63 2.61-2.34l.93-.82 1.8-1.6-.14 2.41c-.03.51-.07 1.07-.12 1.65-.11 1.398-.23 2.978-.19 4.52.05 1.59.33 3.17.81 4.58.96 2.77 3.34 4.29 6.78 4.29 2.56-.01 4.76-.71 6.51-2.06.26-.2.44-.49.46-.61.47-2.51.91-5.03 1.36-7.54.69-3.92 1.41-7.98 2.2-11.95.63-3.16 1.42-6.33 2.19-9.39.28-1.09.55-2.19.82-3.29.11-.43.38-1.22.99-1.66 3.13-2.23 6.01-3.27 9.09-3.27h.12c1.6.02 2.93.54 3.86 1.5.88.908 1.33 2.158 1.29 3.59-.07 2.39-.39 4.85-.95 7.318-.51 2.23-1.1 4.46-1.67 6.62-.65 2.45-1.32 4.98-1.86 7.49-.63 2.9-.41 5.83.65 8.47 1.18 2.95 3.54 4.55 7 4.76.3.02.59.03.89.03 3.36 0 6.64-1.12 10.33-3.53 3.9-2.54 7.44-5.94 11.48-11.02l.15-.19c.14-.19.29-.37.45-.56.25-.28.56-.35.62-.36l.95-.34.33.96c.2.61.39 1.21.58 1.82.41 1.32.79 2.56 1.33 3.73 2.65 5.75 7.27 8.94 14.11 9.78 1.26.16 2.53.23 3.78.23 5.41 0 10.79-1.392 16.45-4.26 6.83-3.472 12.86-8.602 17.92-15.25.19-.262.4-.5.58-.71l1.07-1.312.63 1.58c.41 1.03.8 2.08 1.2 3.14.88 2.35 1.8 4.79 2.9 7.08 1.67 3.45 4.11 6.07 7.24 7.81 2.49 1.37 5.1 2.07 7.77 2.07 2.29 0 4.7-.51 7.17-1.53 5.5-2.26 9.33-6.57 12.06-10.08.94-1.2 1.81-2.52 2.65-3.79.54-.82 1.08-1.64 1.64-2.44.09-.12.86-1.17 1.94-1.17h.01c.61.04 1.22.07 1.83.07 3.92 0 7.35-.87 10.49-2.66l1.3-.74.19 1.48c.09.73.17 1.45.24 2.16.16 1.5.3 2.92.63 4.28 2.12 8.97 8.068 13.76 17.69 14.23.538.03 1.068.04 1.59.04 5.51 0 11.048-1.44 16.468-4.27 11.81-6.18 20.342-15.86 26.06-29.59.23-.54.41-1.1.612-1.69.18-.55.36-1.09.568-1.63.23-.57.8-1.25 1.49-1.38.54-.1 1.08-.21 1.61-.32 1.75-.35 3.55-.71 5.38-.76l.17-.01c1.56 0 2.92.6 3.83 1.68.94 1.12 1.29 2.65 1 4.3-.36 2.01-.96 4.02-1.78 5.96-1.85 4.39-3.65 9.16-4.21 14.26-.48 4.28.14 7.26 2 9.67 1.7 2.21 4.05 3.24 7.4 3.24.52 0 1.07-.02 1.64-.07 3.51-.31 6.9-1.66 11-4.4 3.74-2.49 7.25-5.69 10.73-9.79.22-.26.45-.51.7-.81l1.65-1.87.5 1.78c.13.46.24.92.36 1.35.23.88.45 1.72.73 2.5 2.45 6.92 7.36 10.73 15 11.64 1.21.14 2.44.21 3.65.21 5.38 0 10.77-1.39 16.46-4.27 6.108-3.09 11.47-7.45 16.4-13.32.14-.17.278-.33.49-.56l2.188-2.49v2.65c0 .7-.02 1.38-.038 2.03-.04 1.34-.08 2.61.08 3.8.3 2.17.67 4.46 1.53 6.45 1.43 3.3 4.288 5.2 7.83 5.2 1.458 0 2.968-.32 4.49-.96 6.548-2.75 11.858-7.34 15.76-11.03 1.708-1.61 3.298-3.28 4.99-5.05.76-.8 1.52-1.59 2.288-2.39l1.13-1.16.53 1.54c.19.54.37 1.08.54 1.63.39 1.18.79 2.39 1.25 3.54 2.75 6.78 6.98 11.11 12.94 13.24 2.44.87 4.93 1.31 7.4 1.31 2.648 0 5.33-.5 7.98-1.51 7.84-2.97 13.78-8.08 17.68-15.21.88-1.6 2.01-2.06 3.45-2.24 6.88-.89 11.662-7.093 14.27-11.316.683-1.117 1.253-2.35 1.804-3.55.244-.526.482-1.054.738-1.567v-.334c-.324-.462-.86-.725-1.488-.725zM356.498 45.45c1.54-5.56 3.69-11.22 8.97-15.04.8-.58 1.81-1.05 3.02-1.39.47-.13.89-.19 1.28-.19 1.5 0 2.5.9 2.98 2.68.78 2.92-.09 5.63-.81 7.41-2.1 5.22-6.212 8.09-11.562 8.09-1 0-2.05-.11-3.11-.31l-1.06-.21.292-1.04zm-106.55.09c1.55-5.62 3.71-11.36 9.07-15.19.76-.55 1.76-.99 3.038-1.35.42-.12.82-.18 1.2-.18 1.54 0 2.63.99 2.9 2.63.29 1.76.29 3.49-.01 5.01-1.25 6.33-6.23 10.6-12.41 10.62-.66 0-1.3-.08-1.98-.17-.3-.04-.62-.07-.94-.11l-1.18-.12.31-1.14zm-115.21 0c1.55-5.62 3.72-11.36 9.06-15.19.77-.55 1.77-.99 3.04-1.35.42-.12.83-.18 1.21-.18 1.54 0 2.63.98 2.9 2.62.29 1.77.29 3.5-.01 5.01-1.24 6.34-6.22 10.61-12.4 10.63-.66 0-1.29-.08-1.96-.16-.31-.04-.64-.08-.97-.12l-1.19-.11.32-1.15zm334.02 5.43c-.67 4.82-2.8 8.46-6.32 10.8-1.52 1.01-3.17 1.55-4.77 1.55-3.22 0-5.97-2.19-7.17-5.73-1.48-4.38-1.37-9.13.33-14.54 1.52-4.818 3.93-8.318 7.38-10.71 1.73-1.198 3.92-1.92 5.85-1.92.1 0 .2 0 .3.01l.96.03v.97c0 .772-.01 1.54-.02 2.312-.03 1.67-.062 3.39.05 5.06.23 3.59 1.21 7.03 2.92 10.22.26.488.6 1.208.49 1.948z"/></svg>';
    $svgCpanelLogo = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1136.12 240"><defs><style>.cls-1{fill:#fff;}</style></defs><title>cPanelAsset 14@1x</title><g id="Layer_2" data-name="Layer 2"><g id="Layer_1-2" data-name="Layer 1"><path class="cls-1" d="M89.69,59.1h67.8L147,99.3a25.38,25.38,0,0,1-9,13.5,24.32,24.32,0,0,1-15.3,5.1H91.19a30.53,30.53,0,0,0-19,6.3,33,33,0,0,0-11.55,17.1,31.91,31.91,0,0,0-.45,15.3A33.1,33.1,0,0,0,66,169.35a30.29,30.29,0,0,0,10.8,8.85,31.74,31.74,0,0,0,14.4,3.3h19.2a10.8,10.8,0,0,1,8.85,4.35,10.4,10.4,0,0,1,2,9.75l-12,44.4h-21a84.77,84.77,0,0,1-39.75-9.45A89.78,89.78,0,0,1,18.29,205.5,88.4,88.4,0,0,1,1.94,170,87.51,87.51,0,0,1,3,129l1.2-4.5A88.69,88.69,0,0,1,35.84,77.25a89.91,89.91,0,0,1,25-13.35A87,87,0,0,1,89.69,59.1Z"/><path class="cls-1" d="M123.89,240,183,18.6a25.38,25.38,0,0,1,9-13.5A24.32,24.32,0,0,1,207.29,0H270a84.77,84.77,0,0,1,39.75,9.45,89.21,89.21,0,0,1,46.65,60.6,83.8,83.8,0,0,1-1.2,41l-1.2,4.5a89.88,89.88,0,0,1-12,26.55,87.65,87.65,0,0,1-73.2,39.15h-54.3l10.8-40.5a25.38,25.38,0,0,1,9-13.2,24.32,24.32,0,0,1,15.3-5.1H267a31.56,31.56,0,0,0,30.6-23.7A29.39,29.39,0,0,0,298,84a33.1,33.1,0,0,0-5.85-12.75,31.76,31.76,0,0,0-10.8-9A30.61,30.61,0,0,0,267,58.8h-33.6l-43.8,162.9a25.38,25.38,0,0,1-9,13.2,23.88,23.88,0,0,1-15,5.1Z"/><path class="cls-1" d="M498,121.8l.9-3.3a4.41,4.41,0,0,0-.75-4,4.58,4.58,0,0,0-3.75-1.65h-97.5a24,24,0,0,1-11.4-2.7,24.94,24.94,0,0,1-8.4-7,24.6,24.6,0,0,1-4.5-10,25.5,25.5,0,0,1,.3-11.7l6-22.8h132a47.39,47.39,0,0,1,22.5,5.4,51.93,51.93,0,0,1,17,14.1,50.34,50.34,0,0,1,9.3,20,49.79,49.79,0,0,1-.45,23.25l-23.7,88.2a40.62,40.62,0,0,1-39.6,30.3l-97.5-.3A51.59,51.59,0,0,1,357,219.15a54.4,54.4,0,0,1-9.6-21A49.48,49.48,0,0,1,348,174l1.2-4.5a47.58,47.58,0,0,1,7.05-15.6,54,54,0,0,1,11.55-12.3,52.06,52.06,0,0,1,14.7-7.95,51.14,51.14,0,0,1,17.1-2.85h81.9l-6,22.5a25.49,25.49,0,0,1-9,13.2,23.92,23.92,0,0,1-15,5.1h-36.6q-5.11,0-6.6,5.1a6.13,6.13,0,0,0,1.2,5.85,6.65,6.65,0,0,0,5.4,2.55H474a9.27,9.27,0,0,0,5.7-1.8,7.76,7.76,0,0,0,3-4.8l.6-2.4Z"/><path class="cls-1" d="M672.59,59.1a85.39,85.39,0,0,1,40,9.45,89.82,89.82,0,0,1,30.16,25,88.39,88.39,0,0,1,16.34,35.7,85.78,85.78,0,0,1-1.34,41.1l-15,56.4a16.53,16.53,0,0,1-6.45,9.6,18.22,18.22,0,0,1-11,3.6H693a11,11,0,0,1-10.81-14.1l18-68.1a29.39,29.39,0,0,0,.45-14.7,33.23,33.23,0,0,0-5.84-12.75,32,32,0,0,0-10.8-9,30.67,30.67,0,0,0-14.4-3.45H636L606.88,226.8a16.4,16.4,0,0,1-6.45,9.6,18.65,18.65,0,0,1-11.25,3.6h-32.1a10.78,10.78,0,0,1-8.84-4.35,10.43,10.43,0,0,1-2-9.75l44.4-166.8Z"/><path class="cls-1" d="M849.28,116.25a15.34,15.34,0,0,0-5.1,7.35l-13.5,51a9,9,0,0,0,8.7,11.4h124.2L954,221.7a25.38,25.38,0,0,1-9,13.2,23.88,23.88,0,0,1-15,5.1H816.88a48.43,48.43,0,0,1-22.5-5.25,49.48,49.48,0,0,1-17-14.1,51.48,51.48,0,0,1-9.3-20.1,46,46,0,0,1,.75-23l18.3-68.1a67.5,67.5,0,0,1,9.3-20.4,67.3,67.3,0,0,1,34-26.25,65.91,65.91,0,0,1,22.05-3.75h80.1a47.34,47.34,0,0,1,22.5,5.4,51.83,51.83,0,0,1,17,14.1,48.65,48.65,0,0,1,9.15,20.1,50.2,50.2,0,0,1-.6,23.1l-5.4,20.4A39.05,39.05,0,0,1,960.73,164,40.08,40.08,0,0,1,936,172.2h-90.6l6-22.2a23.78,23.78,0,0,1,8.7-13.2,24.32,24.32,0,0,1,15.3-5.1H912q5.1,0,6.6-5.1l1.2-4.5a6.92,6.92,0,0,0-6.6-8.7h-55.8A12.71,12.71,0,0,0,849.28,116.25Z"/><path class="cls-1" d="M963.28,240l60.3-226.5A17.06,17.06,0,0,1,1030,3.75,18.14,18.14,0,0,1,1041.28,0h32.1a11.11,11.11,0,0,1,9.15,4.35,10.43,10.43,0,0,1,2,9.75l-45,167.1a74.52,74.52,0,0,1-10.65,24,78.66,78.66,0,0,1-17.4,18.45,81.65,81.65,0,0,1-22.35,12A76.85,76.85,0,0,1,963.28,240Z"/><path class="cls-1" d="M1094.83,21.06a20.4,20.4,0,0,1,2.75-10.29A20.6,20.6,0,0,1,1115.48.42a20.39,20.39,0,0,1,10.29,2.74,20.13,20.13,0,0,1,7.58,7.55,20.73,20.73,0,0,1,.11,20.51,20.67,20.67,0,0,1-36,0A20.37,20.37,0,0,1,1094.83,21.06Zm2.88,0a17.76,17.76,0,0,0,8.91,15.39,17.67,17.67,0,0,0,17.73,0,17.89,17.89,0,0,0,6.49-6.47,17.21,17.21,0,0,0,2.4-8.91,17.18,17.18,0,0,0-2.39-8.86,17.89,17.89,0,0,0-6.46-6.5,17.7,17.7,0,0,0-17.78,0,17.87,17.87,0,0,0-6.49,6.46A17.17,17.17,0,0,0,1097.71,21.06Zm26.14-5a6.64,6.64,0,0,1-1.17,3.88,6.79,6.79,0,0,1-3.28,2.51l6.54,10.85h-4.61l-5.69-9.72h-3.7v9.72h-4.07V8.85H1115c3,0,5.26.59,6.68,1.78A6.69,6.69,0,0,1,1123.85,16.07Zm-11.91,4.14h3a5.24,5.24,0,0,0,3.53-1.14,3.63,3.63,0,0,0,1.33-2.89,3.44,3.44,0,0,0-1.18-2.95,6.19,6.19,0,0,0-3.73-.9h-2.91Z"/></g></g></svg>';
    $jsVars = $runtime;
}
?>

<!DOCTYPE html>
<html lang="en" id="<?php echo $pageId; ?>">
<head>
    <meta name="generator" content="<?php echo APP_NAME . ' v' . APP_VERSION; ?>">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,user-scalable=no,maximum-scale=1">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="<?php echo $themeColor; ?>">
    <title><?php echo $doctitle; ?></title>
    <link rel="shortcut icon" type="image/png" href="<?php echo $shortcutIcon; ?>">
    <style>
        <?php echo $css; ?>
    </style>
    <script>
        const appUrl = <?php echo json_encode(APP_URL); ?>;
        const runtime = <?php echo json_encode($jsVars); ?>;
        const patterns = <?php echo json_encode($patterns); ?>;
    </script>
</head>

<body class="body--flex">
    <main>
        <?php if ($pageId == 'error') { ?>
  <div id="screen-error" class="screen screen--error">
    <div class="flex-box error-box">
      <div>
        <h1>Aw, Snap!</h1>
        <p>Your web server lacks some requirements that must be fixed to install Chevereto.</p>
        <p>Please check:</p>
        <ul>
          <?php
            foreach ($requirementsCheck->errors as $v) {
                ?>
            <li><?php echo $v; ?></li>
          <?php
            } ?>
        </ul>
        <p>If you already fixed your web server then make sure to restart it to apply changes. If the problem persists, contact your server administrator.</p>
        <p>Check our <a href="https://chevereto.com/hosting" target="_blank">hosting</a> offer if you don't want to worry about this.</p>
        <p class="error-box-code">Server <?php echo $_SERVER['SERVER_SOFTWARE']; ?></p>
      </div>
    </div>
  </div>
<?php } else { ?>
  <div id="screen-welcome" class="screen screen--show animate animate--slow">
    <div class="header flex-item"><?php echo $svgLogo; ?></div>
    <div class="flex-box flex-item">
      <div>
        <h1>Chevereto Installer</h1>
        <p>This tool will guide you through the process of installing Chevereto. To proceed, check the information below.</p>
        <ul>
          <li>Server path <code><?php echo $runtime->absPath; ?></code></li>
          <li>Website url <code><?php echo $runtime->rootUrl; ?></code></li>
        </ul>
        <p>Confirm that the above details match to where you want to install Chevereto and that there's no other software installed.</p>
        <?php
          if (preg_match('/nginx/i', $runtime->serverSoftware)) { ?>
          <p class="alert">Add the following <a href="<?php echo $runtime->rootUrl . $runtime->installerFilename . '?getNginxRules'; ?>" target="_blank">server rules</a> to your <a href="https://www.digitalocean.com/community/tutorials/understanding-the-nginx-configuration-file-structure-and-configuration-contexts" target="_blank">nginx.conf</a> server block. <b>Restart the server to apply changes</b>. Once done, come back here and continue the process.</p>
        <?php } ?>
        <div>
          <button class="action radius" data-action="show" data-arg="license">Continue</button>
        </div>
      </div>
    </div>
  </div>

  <div id="screen-license" class="screen animate animate--slow">
    <div class="flex-box col-width">
      <div>
        <h1>Enter license key</h1>
        <p>A license key is required to install our main edition. You can purchase a license from our <a href="https://chevereto.com/pricing" target="_blank">website</a> if you don't have one yet.</p>
        <p></p>
        <p>Skip this to install <a href="https://chevereto.com/free" target="_blank">Chevereto-Free</a>, which is our Open Source edition.</p>
        <p class="highlight">The paid edition has more features, gets more frequent updates, and provides additional support assistance.</p>
        <p class="p alert"></p>
        <div class="p input-label">
          <label for="installKey">License key</label>
          <input class="radius width-100p" type="text" name="installKey" id="installKey" placeholder="Paste your license key here" autofill="off" autocomplete="off">
          <div><small>You can find the license key at your <a href="https://chevereto.com/panel/license" target="_blank">client panel</a>.</small></div>
        </div>
        <div>
          <button class="action radius" data-action="setLicense" data-arg="installKey">Enter license key</button>
          <button class=" radius" data-action="setSoftware" data-arg="chevereto-free">Skip – Use Chevereto-Free</button>
        </div>
      </div>
    </div>
  </div>

  <div id="screen-upgrade" class="screen animate animate--slow">
    <div class="header flex-item"><?php echo $svgLogo; ?></div>
    <div class="flex-box col-width">
      <div>
        <h1>Upgrade</h1>
        <p>A license key is required to upgrade to our main edition. You can purchase a license from our <a href="https://chevereto.com/pricing" target="_blank">website</a> if you don't have one yet.</p>
        <p>The system database schema will change, and the system files will get replaced. Don't forget to backup.</p>
        <p>Your system settings, previous uploads, and all user-generated content will remain there.</p>
        <p class="p alert"></p>
        <div class="p input-label">
          <label for="upgradeKey">License key</label>
          <input class="radius width-100p" type="text" name="upgradeKey" id="upgradeKey" placeholder="Paste your license key here">
          <div><small>You can find the license key at your <a href="https://chevereto.com/panel/license" target="_blank">client panel</a>.</small></div>
        </div>
        <div>
          <button class="action radius" data-action="setUpgrade" data-arg="upgradeKey">Enter license key</button>
        </div>
      </div>
    </div>
  </div>

  <div id="screen-cpanel" class="screen animate animate--slow">
    <div class="header flex-item"><?php echo $svgCpanelLogo; ?></div>
    <div class="flex-box col-width">
      <div>
        <h1>cPanel access</h1>
        <p>This installer can connect to a cPanel backend using the <a href="https://documentation.cpanel.net/display/DD/Guide+to+UAPI" target="_blank">cPanel UAPI</a> to create the database, its user, and grant database privileges.</p>
        <?php if ('https' == $runtime->httpProtocol) { ?>
          <p class="highlight">You are not browsing using HTTPS. For extra security, change your cPanel password once the installation gets completed.</p>
        <?php } ?>
        <p>The cPanel credentials won't be stored either transmitted to anyone.</p>
        <p class="highlight">Skip this if you don't run cPanel or if you want to setup the database requirements manually.</p>
        <p class="p alert"></p>
        <div class="p input-label">
          <label for="cpanelUser">User</label>
          <input class="radius width-100p" type="text" name="cpanelUser" id="cpanelUser" placeholder="username" autocomplete="off">
        </div>
        <div class="p input-label">
          <label for="cpanelPassword">Password</label>
          <input class="radius width-100p" type="password" name="cpanelPassword" id="cpanelPassword" placeholder="password" autocomplete="off">
        </div>
        <div>
          <button class="action radius" data-action="cPanelProcess">Connect to cPanel</button>
          <button class="radius" data-action="show" data-arg="db">Skip</button>
        </div>
      </div>
    </div>
  </div>

  <div id="screen-db" class="screen animate animate--slow">
    <div class="flex-box col-width">
      <div>
        <h1>Database</h1>
        <p>Chevereto requires a MySQL 8 (MySQL 5.6 min) database. It will also work with MariaDB 10.</p>
        <form method="post" name="database" data-trigger="setDb" autocomplete="off">
          <p class="p alert"></p>
          <div class="p input-label">
            <label for="dbHost">Host</label>
            <input class="radius width-100p" type="text" name="dbHost" id="dbHost" placeholder="localhost" value="localhost" required>
            <div><small>If you are using Docker, enter the MySQL/MariaDB container hostname or its IP.</small></div>
          </div>
          <div class="p input-label">
            <label for="dbPort">Port</label>
            <input class="radius width-100p" type="number" name="dbPort" id="dbPort" value="3306" placeholder="3306" required>
          </div>
          <div class="p input-label">
            <label for="dbName">Name</label>
            <input class="radius width-100p" type="text" name="dbName" id="dbName" placeholder="mydatabase" required>
          </div>
          <div class="p input-label">
            <label for="dbUser">User</label>
            <input class="radius width-100p" type="text" name="dbUser" id="dbUser" placeholder="username" required>
            <div><small>The database user must have ALL PRIVILEGES on the target database.</small></div>
          </div>
          <div class="p input-label">
            <label for="dbUserPassword">User password</label>
            <input class="radius width-100p" type="password" name="dbUserPassword" id="dbUserPassword" placeholder="password">
          </div>
          <div>
            <button class="action radius">Set database</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div id="screen-admin" class="screen animate animate--slow">
    <div class="flex-box col-width">
      <div>
        <h1>Administrator</h1>
        <p>Fill in your administrator user details. You can edit this account or add more administrators later.</p>
        <form method="post" name="admin" data-trigger="setAdmin" autocomplete="off">
          <p class="p alert"></p>
          <div class="p input-label">
            <label for="adminEmail">Email</label>
            <input class="radius width-100p" type="email" name="adminEmail" id="adminEmail" placeholder="username@domain.com" autocomplete="off" required>
            <div><small>Make sure that this email is working or you won't be able to recover the account if you lost the password.</small></div>
          </div>
          <div class="p input-label">
            <label for="adminUsername">Username</label>
            <input class="radius width-100p" type="text" name="adminUsername" id="adminUsername" placeholder="admin" pattern="<?php echo $patterns['username_pattern']; ?>" autocomplete="off" required>
            <div><small>3 to 16 characters. Letters, numbers and underscore.</small></div>
          </div>
          <div class="p input-label">
            <label for="adminPassword">Password</label>
            <input class="radius width-100p" type="password" name="adminPassword" id="adminPassword" placeholder="password" pattern="<?php echo $patterns['user_password_pattern']; ?>" autocomplete="off" required>
            <div><small>6 to 128 characters.</small></div>
          </div>
          <div>
            <button class="action radius">Set administrator</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div id="screen-emails" class="screen animate animate--slow">
    <div class="flex-box col-width">
      <div>
        <h1>Email addresses</h1>
        <p>Fill in the email addresses that will be used by the system. You can edit this later.</p>
        <form method="post" name="emails" data-trigger="setEmails">
          <p class="p alert"></p>
          <div class="p input-label">
            <label for="no-reply">No-reply</label>
            <input class="radius width-100p" type="email" name="emailNoreply" id="emailNoreply" placeholder="no-reply@domain.com" required>
            <div><small>This address will be used as FROM email address when sending transactional emails (account functions, singup, alerts, etc.)</small></div>
          </div>
          <div class="p input-label">
            <label for="inbox">Inbox</label>
            <input class="radius width-100p" type="email" name="emailInbox" id="emailInbox" placeholder="inbox@domain.com" required>
            <div><small>This address will be used to get contact form messages.</small></div>
          </div>
          <div>
            <button class="action radius">Set emails</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div id="screen-ready" class="screen animate animate--slow">
    <div class="flex-box col-width">
      <div>
        <h1>Ready to install</h1>
        <p>The installer is ready to download and install the latest <span class="chevereto-free--hide">Chevereto</span><span class="chevereto--hide">Chevereto-Free</span> release in <code><?php echo $runtime->absPath; ?></code></p>
        <p class="highlight chevereto-free--hide">By installing is understood that you accept the <a href="https://chevereto.com/license" target="_blank">Chevereto EULA</a>.</p>
        <p class="highlight chevereto--hide">By installing is understood that you accept the Chevereto-Free <a href="<?php echo APPLICATIONS['chevereto-free']['url'] . '/blob/master/LICENSE'; ?>" target="_blank">MIT license</a>.</p>
        <div>
          <button class="action radius" data-action="install">Install <span class="chevereto-free--hide">Chevereto</span><span class="chevereto--hide">Chevereto-Free</span></button>
        </div>
      </div>
    </div>
  </div>

  <div id="screen-ready-upgrade" class="screen animate animate--slow">
    <div class="flex-box col-width">
      <div>
        <h1>Ready to upgrade</h1>
        <p>The installer is ready to download and upgrade to the latest Chevereto release in <code><?php echo $runtime->absPath; ?></code></p>
        <p class="highlight">By upgrading is understood that you accept the <a href="https://chevereto.com/license" target="_blank">Chevereto EULA</a>.</p>
        <div>
          <button class="action radius" data-action="upgrade">Upgrade Chevereto</button>
        </div>
      </div>
    </div>
  </div>

  <div id="screen-installing" class="screen animate animate--slow">
    <div class="flex-box col-width">
      <div>
        <h1>Installing</h1>
        <p>The software is being installed. Don't close this window until the process gets completed.</p>
        <p class="p alert"></p>
        <div class="log log--install p"></div>
      </div>
    </div>
  </div>

  <div id="screen-upgrading" class="screen animate animate--slow">
    <div class="flex-box col-width">
      <div>
        <h1>Upgrading</h1>
        <p>The software is being upgraded. Don't close this window until the process gets completed.</p>
        <p class="p alert"></p>
        <div class="log log--upgrade p"></div>
      </div>
    </div>
  </div>

  <div id="screen-complete" class="screen animate animate--slow">
    <div class="flex-box col-width">
      <div>
        <h1>Installation completed</h1>
        <p>Chevereto has been installed. You can now login to your dashboard panel to configure your website to fit your needs.</p>
        <p class="alert">The installer has self-removed its file at <code><?php echo INSTALLER_FILEPATH; ?></code></p>
        <p>Take note on the installation details below.</p>
        <div class="install-details p highlight font-size-80p"></div>
        <p>Hope you enjoy using Chevereto as much I care in creating it. Help development by providing feedback and recommend my software.</p>
        <div>
          <a class="button action radius" href="<?php echo $runtime->rootUrl; ?>dashboard" target="_blank">Open dashboard</a>
          <a class="button radius" href="<?php echo $runtime->rootUrl; ?>" target="_blank">Open homepage</a>
        </div>
      </div>
    </div>
  </div>

  <div id="screen-complete-upgrade" class="screen animate animate--slow">

    <div class="flex-box col-width">
      <div>
        <h1>Upgrade prepared</h1>
        <p>The system files have been upgraded. You can now install the upgrade which will perform the database changes needed and complete the process.</p>
        <p class="alert">The installer has self-removed its file at <code><?php echo INSTALLER_FILEPATH; ?></code></p>
        <div>
          <a class="button action radius" href="<?php echo $runtime->rootUrl; ?>install">Install upgrade</a>
        </div>
      </div>
    </div>
  </div>

<?php } ?>
    </main>
    <script>
        <?php echo $script; ?>
    </script>
</body>
</html>
