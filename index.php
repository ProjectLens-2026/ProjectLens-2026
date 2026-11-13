<?php
declare(strict_types=1);
require_once __DIR__.'/inc/core.php';
require_once __DIR__.'/inc/handlers.php';
$page=(string)($_GET['page']??'register');
if($page==='home')$page='register';
$id=max(0,(int)($_GET['id']??0));
if($_SERVER['REQUEST_METHOD']==='POST')handle_action((string)($_POST['action']??''));
require __DIR__.'/inc/views.php';
