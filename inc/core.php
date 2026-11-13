<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
    session_start();
}
$config = require dirname(__DIR__) . '/config.php';
function db(): PDO {
    static $pdo = null;
    global $config;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['database']);
        $pdo = new PDO($dsn, $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    }
    return $pdo;
}
function query(string $sql, array $params = []): PDOStatement {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}
function one(string $sql, array $params = []): ?array { $row = query($sql, $params)->fetch(); return $row ?: null; }
function all(string $sql, array $params = []): array { return query($sql, $params)->fetchAll(); }
function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $page, array $args = []): string { return 'index.php?' . http_build_query(array_merge(['page' => $page], $args)); }
function go(string $page, array $args = []): never { header('Location: ' . url($page, $args)); exit; }
function csrf(): string { if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24)); return $_SESSION['csrf']; }
function csrf_input(): string { return '<input type="hidden" name="csrf" value="' . e(csrf()) . '">'; }
function ensure_csrf(): void { if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Invalid security token. Refresh the page and retry.'); } }
function flash(string $msg, string $type='good'): void { $_SESSION['flash'] = [$msg,$type]; }
function show_flash(): void { if (!empty($_SESSION['flash'])) { [$text,$kind] = $_SESSION['flash']; unset($_SESSION['flash']); echo '<div class="alert ' . ($kind === 'error' ? 'alert-error' : 'alert-good') . '" role="alert">' . e($text) . '</div>'; } }
function me(): ?array {
    if (empty($_SESSION['user']['id'])) return null;
    static $checked=false;
    if (!$checked) {
        $checked=true;
        try {
            $row=one('SELECT id,full_name,email,organization,role,ngo_id,is_active FROM users WHERE id=? AND deleted_at IS NULL',[(int)$_SESSION['user']['id']]);
            if (!$row || !(int)$row['is_active']) { unset($_SESSION['user']);return null; }
            $_SESSION['user']=$row;
        } catch(PDOException $ex) { }
    }
    return $_SESSION['user'] ?? null;
}
function uid(): int { return (int)(me()['id']??0); }
function ngo_id(): int { return (int)(me()['ngo_id']??0); }
function role(): string { return (string)(me()['role']??''); }
function role_label(string $value): string { return ['super_admin'=>'Super Admin','manager'=>'Project Manager','engineer'=>'Site Engineer','client'=>'Overall Management (Client)'][$value]??ucfirst($value); }
function auth(string|array|null $roles=null): void {
    if (!me()) {flash('Please sign in to continue.','error');go('login');}
    if ($roles!==null && !in_array(role(),(array)$roles,true)) {
        http_response_code(403);page_start('Access denied');echo '<section class="content-card narrow-card"><h1>Access denied</h1><p>Your account is not authorised to use this feature.</p><a class="btn" href="'.url('dashboard').'">Return to dashboard</a></section>';page_end();exit;
    }
    if (in_array(role(),['manager','engineer'],true) && ngo_id()===0) {
        http_response_code(403);page_start('Awaiting NGO assignment');echo '<section class="content-card narrow-card"><h1>NGO assignment required</h1><p>The Super Admin must assign your account to an NGO before you can manage projects.</p></section>';page_end();exit;
    }
}
function project_progress_sql(): string {
    return "COALESCE((SELECT ROUND(SUM(CASE WHEN a.approval_status='approved' THEN a.weight_percent ELSE 0 END),2) FROM activities a WHERE a.project_id=p.id AND a.deleted_at IS NULL),0)";
}
function project(int $id): ?array {
    if (!me() || $id<1) return null;
    $select='SELECT p.*,'.project_progress_sql().' AS progress FROM projects p ';
    if(role()==='super_admin') return one($select.' WHERE p.id=?',[$id]);
    if(role()==='manager') return one($select.' WHERE p.id=? AND p.manager_id=? AND p.ngo_id=?',[$id,uid(),ngo_id()]);
    if(role()==='engineer') return one($select.' JOIN project_engineers pe ON pe.project_id=p.id WHERE p.id=? AND pe.engineer_id=? AND p.ngo_id=?',[$id,uid(),ngo_id()]);
    if(role()==='client') return one($select.' JOIN project_clients pc ON pc.project_id=p.id WHERE p.id=? AND pc.client_id=?',[$id,uid()]);
    return null;
}
function need_project(int $id): array {
    $p=project($id);
    if (!$p) {http_response_code(404);page_start('Project unavailable');echo '<section class="content-card narrow-card"><h1>Project unavailable</h1><p>The project does not exist or you have not been given access.</p></section>';page_end();exit;}
    return $p;
}
function can_manage_project(array $p): bool { return role()==='manager' && (int)$p['manager_id']===uid() && (int)$p['ngo_id']===ngo_id(); }
function my_projects(): array {
    if (!me()) return [];
    $select='SELECT p.*,'.project_progress_sql().' AS progress FROM projects p ';
    if(role()==='super_admin') return all($select.' ORDER BY p.updated_at DESC');
    if(role()==='manager') return all($select.' WHERE p.manager_id=? AND p.ngo_id=? ORDER BY p.updated_at DESC',[uid(),ngo_id()]);
    if(role()==='engineer') return all($select.' JOIN project_engineers pe ON pe.project_id=p.id WHERE pe.engineer_id=? AND p.ngo_id=? ORDER BY p.updated_at DESC',[uid(),ngo_id()]);
    if(role()==='client') return all($select.' JOIN project_clients pc ON pc.project_id=p.id WHERE pc.client_id=? ORDER BY p.updated_at DESC',[uid()]);
    return [];
}
function project_finance(int $pid): array {
    $row=one("SELECT p.budget_total,COALESCE(SUM(CASE WHEN f.approval_status='approved' AND f.entry_type='funding' THEN f.amount ELSE 0 END),0) AS funding,COALESCE(SUM(CASE WHEN f.approval_status='approved' AND f.entry_type='expense' THEN f.amount ELSE 0 END),0) AS expenses FROM projects p LEFT JOIN project_finances f ON f.project_id=p.id WHERE p.id=? GROUP BY p.id,p.budget_total",[$pid]);
    $row=$row?:['budget_total'=>0,'funding'=>0,'expenses'=>0];
    $row['remaining']=(float)$row['budget_total']-(float)$row['expenses'];
    $row['cash_balance']=(float)$row['funding']-(float)$row['expenses'];
    $row['utilization']=(float)$row['budget_total']>0 ? round(100*(float)$row['expenses']/(float)$row['budget_total'],1):0;
    return $row;
}
function schedule_progress(array $p): ?float {
    $start=$p['start_date']??null;$end=$p['end_date']??null;
    if(!$start||!$end||$end<$start)return null;
    $today=new DateTimeImmutable('today');$s=new DateTimeImmutable($start);$e=new DateTimeImmutable($end);
    if($today<$s)return 0.0;
    if($today>=$e)return 100.0;
    $days=max(1,(int)$s->diff($e)->days+1);
    return round(min(100,100*((int)$s->diff($today)->days+1)/$days),2);
}
function schedule_card(array $p): string {
    $expected=schedule_progress($p);
    if($expected===null)return '<div class="metric-card"><span>Schedule expectation</span><strong>Not scheduled</strong><small>Add project start and end dates.</small></div>';
    $gap=$expected-(float)$p['progress'];
    $status=$gap>5?'Behind schedule':($gap<(-5)?'Ahead of schedule':'Near schedule');
    return '<div class="metric-card"><span>Expected progress from dates</span><strong>'.number_format($expected,2).'%</strong>'.progress_bar((int)$expected).'<small>'.e($status).'. Actual progress uses manager-approved activity weights, not dates or images.</small></div>';
}
function pending_expense_total(int $pid): float {
    return (float)(one("SELECT COALESCE(SUM(amount),0) total FROM project_finances WHERE project_id=? AND entry_type='expense' AND approval_status='submitted'",[$pid])['total']??0);
}
function engineer_can_access_code(array $p): bool {return role()==='engineer' && ngo_id()>0 && (int)$p['ngo_id']===ngo_id();}
function engineer_can_message(array $p,int $eid): bool {
    if(!in_array(role(),['manager','engineer'],true))return false;
    if(role()==='manager'&&!can_manage_project($p))return false;
    if(role()==='engineer'&&uid()!==$eid)return false;
    return (bool)one('SELECT 1 FROM project_engineers WHERE project_id=? AND engineer_id=?',[(int)$p['id'],$eid]);
}
function money(float|int|string $amount): string { return '₦'.number_format((float)$amount,2); }
function notification_count(): int {
    if(!me())return 0;
    try {return (int)(one('SELECT COUNT(*) AS total FROM notifications WHERE user_id=? AND read_at IS NULL',[uid()])['total']??0);} catch(PDOException $ex){return 0;}
}
function notify_user(int $userId,string $title,string $body,string $target): void {
    query('INSERT INTO notifications(user_id,title,body,target) VALUES(?,?,?,?)',[$userId,substr($title,0,190),substr($body,0,500),substr($target,0,255)]);
}
function activity_status(string $state): string {return ['pending'=>'Not submitted','submitted'=>'Awaiting manager approval','approved'=>'Confirmed complete','rejected'=>'Returned for correction'][$state]??$state;}
function status_text(string $s): string { return ['on_track'=>'On track','delayed'=>'Delayed','completed'=>'Completed','on_hold'=>'On hold','unique_ready'=>'Unique — confirm','pending_review'=>'Review required','approved'=>'Approved','rejected'=>'Rejected'][$s] ?? ucfirst(str_replace('_',' ',$s)); }
function status_badge(string $s): string { return '<span class="status status-' . e($s) . '">' . e(status_text($s)) . '</span>'; }
function progress_bar(int $num): string { $num=max(0,min(100,$num));return '<div class="progress" role="progressbar" aria-valuenow="'.$num.'" aria-valuemin="0" aria-valuemax="100"><span style="width:'.$num.'%"></span></div>'; }
function audit(string $action,string $details,?int $projectId=null): void { query('INSERT INTO audit_events (user_id,project_id,action,details) VALUES (?,?,?,?)', [uid(),$projectId,$action,$details]); }
function unread_message_count(): int {
    if (!me() || !in_array(role(),['manager','client'],true)) return 0;
    $scope = role()==='manager' ? 'p.manager_id=?' : 'pc.client_id=?';
    try {
        $row=one('SELECT COUNT(*) AS total FROM project_messages m
            JOIN projects p ON p.id=m.project_id
            JOIN project_clients pc ON pc.project_id=m.project_id AND pc.client_id=m.client_id
            LEFT JOIN project_message_reads r ON r.project_id=m.project_id AND r.client_id=m.client_id AND r.reader_id=?
            WHERE m.sender_id<>? AND m.id>COALESCE(r.last_read_message_id,0) AND '.$scope,
            [uid(),uid(),uid()]);
        return (int)($row['total']??0);
    } catch (PDOException $ex) {
        return 0;
    }
}
function unread_thread_count(int $projectId, int $clientId): int {
    if (!me()) return 0;
    try {
        $r=one('SELECT COUNT(*) AS total FROM project_messages m
            LEFT JOIN project_message_reads r ON r.project_id=m.project_id AND r.client_id=m.client_id AND r.reader_id=?
            WHERE m.project_id=? AND m.client_id=? AND m.sender_id<>? AND m.id>COALESCE(r.last_read_message_id,0)',
            [uid(),$projectId,$clientId,uid()]);
        return (int)($r['total']??0);
    } catch (PDOException $ex) { return 0; }
}
function mark_conversation_read(int $projectId,int $clientId,int $lastMessageId): void {
    if (!me() || $lastMessageId<1) return;
    query('INSERT INTO project_message_reads (project_id,client_id,reader_id,last_read_message_id)
      VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE
      last_read_message_id=GREATEST(last_read_message_id,VALUES(last_read_message_id))',
      [$projectId,$clientId,uid(),$lastMessageId]);
}
function message_badge(int $count): string {
    return $count>0 ? '<span class="message-count" aria-label="'.(int)$count.' unread messages">'.($count>99?'99+':(string)$count).'</span>' : '';
}
function project_code(): string {
    $chars='ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($i=0;$i<60;$i++) {
        $code='';for($j=0;$j<6;$j++) $code.=$chars[random_int(0,strlen($chars)-1)];
        if (!one('SELECT id FROM projects WHERE project_code=?',[$code])) return $code;
    }
    throw new RuntimeException('Unable to create an unused project code. Please try again.');
}
function brand(): string { return '<a class="brand" href="index.php" aria-label="ProjectLens get started"><span class="brand-mark">◈</span><span>Project<span class="brand-accent">Lens</span></span></a>'; }
function page_start(string $title,bool $app=false): void {
    $u=me();$unread=$u?unread_message_count():0;$alerts=$u?notification_count():0;
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#153f31"><meta name="description" content="ProjectLens multi-NGO project monitoring and reporting"><title>'.e($title).' • ProjectLens</title><link rel="icon" type="image/svg+xml" href="assets/favicon.svg"><link rel="stylesheet" href="assets/style.css"><script src="assets/app.js" defer></script></head><body data-role="'.e($u['role']??'visitor').'">';
    echo '<header class="site-header"><div class="nav-container">'.brand().'<button class="mobile-toggle" type="button" aria-label="Open navigation" aria-expanded="false">☰</button><nav class="nav-links" aria-label="Main navigation">';
    if($u) {
        echo '<a href="'.url('dashboard').'">Dashboard</a><a href="'.url('projects').'">Projects</a>';
        if(role()==='super_admin') echo '<a href="'.url('admin').'">NGOs & accounts</a>';
        if(role()==='manager') echo '<a href="'.url('new-project').'">New project</a><a href="'.url('evidence').'">Evidence</a><a href="'.url('queue').'">AI queue</a>';
        if(role()==='engineer') echo '<a href="'.url('evidence').'">Evidence</a><a href="'.url('engineer-find').'">Join project</a>';
        if(role()==='client') echo '<a href="'.url('find-project').'">Find by code</a>';
        if(in_array(role(),['manager','client'],true)) echo '<a href="'.url('conversations').'">Messages'.message_badge($unread).'</a>';
        if(in_array(role(),['manager','engineer'],true)) echo '<a href="'.url('engineer-chats').'">Site messages</a>';
        echo '<a href="'.url('support').'">Help and support</a><a href="'.url('notifications').'">Notifications'.message_badge($alerts).'</a>'.(role()==='engineer'?'':'<a href="'.url('reports').'">Reports</a>').'<a href="'.url('settings').'">Settings</a><span class="nav-user">'.e($u['full_name']).' · '.e(role_label(role())).'</span><form method="post" action="'.url('dashboard').'" class="inline-form">'.csrf_input().'<input type="hidden" name="action" value="logout"><button class="nav-logout" type="submit">Log out</button></form>';
    } else echo '<a href="'.url('features').'">Features</a><a href="'.url('about').'">About us</a><a href="'.url('contact').'">Contact</a><a href="'.url('login').'">Log in</a><a href="'.url('register').'" class="nav-cta">Get started</a>';
    echo '</nav></div></header>';
    if($app && $u) {
        echo '<div class="app-frame"><aside class="sidebar"><p class="sidebar-label">WORKSPACE</p><a href="'.url('dashboard').'">▦ &nbsp; Overview</a><a href="'.url('projects').'">▤ &nbsp; Projects</a>';
        if(role()==='super_admin') echo '<a href="'.url('admin').'">▤ &nbsp; NGO administration</a>';
        if(role()==='manager') echo '<a href="'.url('new-project').'">✚ &nbsp; New project</a><a href="'.url('evidence').'">▧ &nbsp; Upload evidence</a><a href="'.url('queue').'">◎ &nbsp; AI image recognition</a>';
        if(role()==='engineer') echo '<a href="'.url('evidence').'">▧ &nbsp; Upload evidence</a><a href="'.url('engineer-find').'">⌕ &nbsp; Join project by code</a>';
        if(role()==='client') echo '<a href="'.url('find-project').'">⌕ &nbsp; Find project by code</a>';
        if(in_array(role(),['manager','client'],true)) echo '<a href="'.url('conversations').'">✉ &nbsp; Project messages'.message_badge($unread).'</a>';
        if(in_array(role(),['manager','engineer'],true)) echo '<a href="'.url('engineer-chats').'">✉ &nbsp; Site messages</a>';
        if(role()==='super_admin') echo '<a href="'.url('admin-oversight').'">▤ &nbsp; Oversight and audit</a>';
        echo '<a href="'.url('support').'">? &nbsp; Support requests</a><a href="'.url('notifications').'">♧ &nbsp; Notifications'.message_badge($alerts).'</a>'.(role()==='engineer'?'':'<a href="'.url('reports').'">▤ &nbsp; Reports</a>').'<a href="'.url('settings').'">⚙ &nbsp; Settings</a></aside><main class="app-main">';
    } else echo '<main>';
    show_flash();
}
function page_end(bool $app=false): void {
 echo '</main>'; if($app && me()) echo '</div>';
 echo '</body></html>';
}
function report_button(int $id, string $label='Export report'): string { return '<button type="button" class="btn btn-outline report-open" data-project="'.$id.'">'.e($label).'</button>'; }
function report_modal(): void {
 echo '<dialog id="reportDialog" class="report-dialog"><form method="post" action="report.php" class="report-form"><div class="modal-head"><div><span class="eyebrow">CUSTOM REPORT</span><h2>Generate project report</h2></div><button type="button" class="icon-close modal-close" aria-label="Close">✕</button></div>'.csrf_input().'<input name="project_id" id="reportProjectId" type="hidden"><label for="reportType">Report content</label><select id="reportType" name="report_type"><option value="summary">Progress summary</option><option value="full">Full performance detail</option><option value="audit">Evidence audit trail</option></select><label class="checkline"><input type="checkbox" name="include_ai" value="1" checked> Include visual similarity metadata</label><label for="reportFormat">File format</label><select id="reportFormat" name="format"><option value="pdf">PDF document</option><option value="docx">Word document (DOCX)</option><option value="xlsx">Excel workbook (XLSX)</option></select><div class="modal-actions"><button class="btn btn-muted modal-close" type="button">Cancel</button><button class="btn" type="submit">Generate & download</button></div></form></dialog>';
}
function safe_date(?string $value): string { return $value ? e($value) : 'Not set'; }
