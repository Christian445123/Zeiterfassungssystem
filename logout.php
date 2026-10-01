<?php
declare(strict_types=1);
require __DIR__ . '/lib/web.php';
$_SESSION = [];
session_destroy();
redirect('index.php');
