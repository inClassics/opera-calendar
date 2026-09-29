<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../classes/CalendarAnnotations.php';

require_login();

$year = (int)($_POST['year'] ?? date('Y'));
$month = (int)($_POST['month'] ?? date('n'));

if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
    json_response(['success' => false, 'message' => 'Invalid month.'], 400);
}

$from = new DateTime(sprintf('%04d-%02d-01', $year, $month));
$to = (clone $from)->modify('last day of this month');

$data = (new CalendarAnnotations($pdo))->forRange($from, $to);

json_response(['success' => true] + $data);
