<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// REST API (all JSON). Order matters: literal routes before the ([^/]+) job-id routes.
$route['default_controller'] = 'api_root';
$route['404_override'] = '';
$route['translate_uri_dashes'] = FALSE;

$route['api/health']['get'] = 'health/index';
$route['api/about']['get'] = 'health/about';
$route['api/video/info']['post'] = 'video/info';
$route['api/search']['post'] = 'video/search';

$route['api/download']['post'] = 'download/create';
$route['api/download/history']['get'] = 'history/index';
$route['api/download/history/([^/]+)']['delete'] = 'history/remove/$1';
$route['api/download/([^/]+)/cancel']['post'] = 'download/cancel/$1';
$route['api/download/([^/]+)/file']['get'] = 'download/file/$1';
$route['api/download/([^/]+)']['get'] = 'download/status/$1';
