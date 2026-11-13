<?php
declare(strict_types=1);
require_once __DIR__.'/inc/core.php';
auth();
$id=max(0,(int)($_GET['id']??0));
$ev=one('SELECT ev.*,a.deleted_at AS activity_deleted_at FROM evidence ev JOIN activities a ON a.id=ev.activity_id WHERE ev.id=?',[$id]);
if(!$ev || !project((int)$ev['project_id']) || ($ev['activity_deleted_at']!==null && role()!=='super_admin') || (me()['role']==='client' && $ev['review_status']!=='approved')) {http_response_code(404);exit('Image not available.');}
$path=__DIR__.'/storage/evidence/'.basename($ev['image_file']);
if(!is_file($path)) {http_response_code(404);exit('File not found.');}
header('Content-Type: '.$ev['mime_type']);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="evidence-'.$id.'.'.(['image/png'=>'png','image/webp'=>'webp'][$ev['mime_type']]??'jpg').'"');
header('Cache-Control: private, max-age=120');
header('Content-Length: '.filesize($path));
readfile($path);
