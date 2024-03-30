<?php

define('ROOT_PATH', realpath(__DIR__) . '/');

/* Autoload for vendor */
require_once ROOT_PATH . 'vendor/autoload.php';

/* Potential error checks */
if(empty($_POST)) {
    die();
}

$required = [
    'type',
    'target',
    'port',
    'settings'
];

foreach($required as $required_field) {
    if(!isset($_POST[$required_field])) {
        die();
    }
}

/* Define some needed vars */
$_POST['settings'] = json_decode($_POST['settings']);

switch($_POST['type']) {

    /* Fsockopen */
    case 'port':

        $ping = new \JJG\Ping($_POST['target']);
        $ping->setTimeout($_POST['settings']->timeout_seconds);
        $ping->setPort($_POST['port']);
        $latency = $ping->ping('fsockopen');

        if($latency !== false) {

            $response_status_code = 0;
            $response_time = $latency;

            /*  :)  */
            $is_ok = 1;
        } else {

            $response_status_code = 0;
            $response_time = 0;

            /*  :)  */
            $is_ok = 0;

        }

        break;

    /* Ping check */
    case 'ping':

        $ping = new \JJG\Ping($_POST['target']);
        $ping->setTimeout($_POST['settings']->timeout_seconds);
        $latency = $ping->ping($_POST['ping_method']);

        if($latency !== false) {

            $response_status_code = 0;
            $response_time = $latency;

            /*  :)  */
            $is_ok = 1;
        } else {

            $response_status_code = 0;
            $response_time = 0;

            /*  :)  */
            $is_ok = 0;

        }

        break;

    /* Websites check */
case 'website':

    /* Set timeout */
    \Unirest\Request::timeout($_POST['settings']->timeout_seconds);

    /* Set follow redirects */
    \Unirest\Request::curlOpts([
        CURLOPT_FOLLOWLOCATION => $_POST['settings']->follow_redirects ?? true,
        CURLOPT_MAXREDIRS => 5,
    ]);

    try {

        /* Set auth */
        \Unirest\Request::auth($_POST['settings']->request_basic_auth_username ?? '', $_POST['settings']->request_basic_auth_password ?? '');

        /* Make the request to the website */
        $method = mb_strtolower($_POST['settings']->request_method);

        /* Prepare request headers */
        $request_headers = [];

        /* Set custom user agent */
        if($_POST['settings']->user_agent) {
            $request_headers['User-Agent'] = $_POST['settings']->user_agent;
        }

        foreach($_POST['settings']->request_headers as $request_header) {
            $request_headers[$request_header->name] = $request_header->value;
        }

        /* Bugfix on Unirest php library for Head requests */
        if($method == 'head') {
            \Unirest\Request::curlOpt(CURLOPT_NOBODY, true);
        }

        if(in_array($method, ['post', 'put', 'patch'])) {
            $response = \Unirest\Request::{$method}($_POST['target'], $request_headers, $_POST['settings']->request_body ?? []);
        } else {
            $response = \Unirest\Request::{$method}($_POST['target'], $request_headers);
        }

        /* Clear custom settings */
        \Unirest\Request::clearCurlOpts();

        /* Get info after the request */
        $info = \Unirest\Request::getInfo();

        /* Some needed variables */
        $response_status_code = $info['http_code'];
        $response_time = $info['total_time'] * 1000;

        /* Check the response to see how we interpret the results */
        $is_ok = 1;

        /* Check against response code */
        if(
            (is_array($_POST['settings']->response_status_code) && !in_array($response_status_code, $_POST['settings']->response_status_code))
            || (!is_array($_POST['settings']->response_status_code) && $response_status_code != ($_POST['settings']->response_status_code ?? 200))
        ) {
            $is_ok = 0;
            $error = ['type' => 'response_status_code'];
        }

        if(isset($_POST['settings']->response_body) && $_POST['settings']->response_body && mb_strpos($response->raw_body, $_POST['settings']->response_body) === false) {
            $is_ok = 0;
            $error = ['type' => 'response_body'];
        }

        if(isset($_POST['settings']->response_headers)) {
            foreach ($_POST['settings']->response_headers as $response_header) {
                $response_header->name = mb_strtolower($response_header->name);

                if(!isset($response->headers[$response_header->name]) || (isset($response->headers[$response_header->name]) && $response->headers[$response_header->name] != $response_header->value)) {
                    $is_ok = 0;
                    $error = ['type' => 'response_header'];
                    break;
                }
            }
        }

    } catch (\Exception $exception) {
        $response_status_code = 0;
        $response_time = 0;
        $curl_info = curl_getinfo(\Unirest\Request::getCurlHandle());
        $error = [
            'type' => 'exception',
            'code' => curl_errno(\Unirest\Request::getCurlHandle()),
            'message' => curl_error(\Unirest\Request::getCurlHandle()),
            'curl_info' => $curl_info,
        ];
    
        /*  :)  */
        $is_ok = 0;
    }

    break;
    
}

if($is_ok != 1) {
    $url = urlencode($_POST['target']);
    $screenshotUrl = "https://screenshot-72mn.onrender.com/screenshot?url=$url";
    $screenshot = file_get_contents($screenshotUrl);
} else {
    $screenshot = null;
}

$response = [
    'is_ok' => $is_ok,
    'response_time' => $response_time,
    'response_status_code' => $response_status_code,
    'error' => $error ?? null,
    'screenshot' => $screenshot
];

echo json_encode($response);

die();