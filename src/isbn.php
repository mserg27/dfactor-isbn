<?php

const DB_HOST = '127.0.0.1';
const DB_DATABASE   = 'ruslania';
const DB_USER = 'ruslania';
const DB_PASSWORD = 'ruslania';
const DB_CHARSET = 'utf8';

const REPORT_FILE = 'report.xlsx';

require_once __DIR__ . '/../vendor/autoload.php';

use Isbn\IsbnHandler;

$isbn = new IsbnHandler(DB_HOST, DB_DATABASE, DB_USER, DB_PASSWORD, DB_CHARSET, REPORT_FILE);
$isbn->handler();

