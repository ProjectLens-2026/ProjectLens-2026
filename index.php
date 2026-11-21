<?php
declare(strict_types=1);
require_once __DIR__.'/inc/core.php';
require_once __DIR__.'/inc/handlers.php';
$page=(string)($_GET['page']??'register');
if($page==='home')$page='register';
$id=max(0,(int)($_GET['id']??0));
if(me() && !in_array((string)($_POST['action']??''),['login','logout'],true) && !activity_budget_ready()) {
    if(role()==='super_admin'||role()==='manager'){header('Location: install.php');exit;}
    http_response_code(503);page_start('Database upgrade required');echo '<section class="container content-card"><h1>Database upgrade required</h1><p>Ask the Super Admin to apply the ProjectLens database upgrade before continuing.</p><form method="post">'.csrf_input().'<input type="hidden" name="action" value="logout"><button class="btn">Log out</button></form></section>';page_end();exit;
}
if($_SERVER['REQUEST_METHOD']==='POST')handle_action((string)($_POST['action']??''));
require __DIR__.'/inc/views.php';
