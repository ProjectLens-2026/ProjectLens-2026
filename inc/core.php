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
function me(): ?array { return $_SESSION['user'] ?? null; }
function uid(): int { return (int)(me()['id'] ?? 0); }
function auth(?string $role=null): void {
    if (!me()) { flash('Please sign in to continue.', 'error'); go('login'); }
    if ($role !== null && me()['role'] !== $role) { http_response_code(403); page_start('Access denied'); echo '<section class="content-card"><h1>Access denied</h1><p>Your account does not have permission to use this feature.</p><a class="btn" href="'.url('dashboard').'">Back to dashboard</a></section>'; page_end(); exit; }
}
function project(int $id): ?array {
    if (!me() || $id < 1) return null;
    if (me()['role'] === 'manager') {
        return one('SELECT p.*, COALESCE((SELECT ROUND(AVG(a.progress_percent)) FROM activities a WHERE a.project_id=p.id),0) progress FROM projects p WHERE p.id=? AND p.manager_id=?', [$id,uid()]);
    }
    return one('SELECT p.*, COALESCE((SELECT ROUND(AVG(a.progress_percent)) FROM activities a WHERE a.project_id=p.id),0) progress FROM projects p JOIN project_clients pc ON pc.project_id=p.id WHERE p.id=? AND pc.client_id=?', [$id,uid()]);
}
function need_project(int $id): array { $p=project($id); if (!$p) { http_response_code(404); page_start('Project not found'); echo '<section class="content-card"><h1>Project not available</h1><p>This project does not exist or has not been shared with you.</p></section>'; page_end(); exit; } return $p; }
function status_text(string $s): string { return ['on_track'=>'On track','delayed'=>'Delayed','completed'=>'Completed','on_hold'=>'On hold','unique_ready'=>'Unique — confirm','pending_review'=>'Review required','approved'=>'Approved','rejected'=>'Rejected'][$s] ?? ucfirst(str_replace('_',' ',$s)); }
function status_badge(string $s): string { return '<span class="status status-' . e($s) . '">' . e(status_text($s)) . '</span>'; }
function progress_bar(int $num): string { $num=max(0,min(100,$num));return '<div class="progress" role="progressbar" aria-valuenow="'.$num.'" aria-valuemin="0" aria-valuemax="100"><span style="width:'.$num.'%"></span></div>'; }
function audit(string $action,string $details,?int $projectId=null): void { query('INSERT INTO audit_events (user_id,project_id,action,details) VALUES (?,?,?,?)', [uid(),$projectId,$action,$details]); }
function my_projects(): array {
 if (me()['role'] === 'manager') return all('SELECT p.*, COALESCE((SELECT ROUND(AVG(a.progress_percent)) FROM activities a WHERE a.project_id=p.id),0) progress FROM projects p WHERE p.manager_id=? ORDER BY p.updated_at DESC', [uid()]);
 return all('SELECT p.*, COALESCE((SELECT ROUND(AVG(a.progress_percent)) FROM activities a WHERE a.project_id=p.id),0) progress FROM projects p JOIN project_clients pc ON p.id=pc.project_id WHERE pc.client_id=? ORDER BY p.updated_at DESC',[uid()]);
}
/** Count received messages that this user has not opened in each project conversation. */
function unread_message_count(): int {
    if (!me()) return 0;
    $scope = me()['role']==='manager' ? 'p.manager_id=?' : 'pc.client_id=?';
    try {
        $row=one('SELECT COUNT(*) AS total FROM project_messages m
            JOIN projects p ON p.id=m.project_id
            JOIN project_clients pc ON pc.project_id=m.project_id AND pc.client_id=m.client_id
            LEFT JOIN project_message_reads r ON r.project_id=m.project_id AND r.client_id=m.client_id AND r.reader_id=?
            WHERE m.sender_id<>? AND m.id>COALESCE(r.last_read_message_id,0) AND '.$scope,
            [uid(),uid(),uid()]);
        return (int)($row['total']??0);
    } catch (PDOException $ex) {
        // Old installations can still open the dashboard before running the safe database upgrade.
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
function page_start(string $title, bool $app=false): void {
 $u = me();
 $unread = $u ? unread_message_count() : 0;
 echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#153f31"><meta name="description" content="ProjectLens — transparent construction project monitoring with visual image evidence checks and reporting."><title>'.e($title).' • ProjectLens</title><link rel="icon" type="image/svg+xml" href="assets/favicon.svg"><link rel="stylesheet" href="assets/style.css"><script src="assets/app.js" defer></script></head><body>';
 echo '<header class="site-header"><div class="nav-container">'.brand().'<button class="mobile-toggle" type="button" aria-label="Open navigation" aria-expanded="false">☰</button><nav class="nav-links" aria-label="Main navigation">';
 if ($u) {
  echo '<a href="'.url('dashboard').'">Dashboard</a><a href="'.url('projects').'">Projects</a>';
  if ($u['role']==='client') echo '<a href="'.url('find-project').'">Find project</a><a href="'.url('conversations').'">Messages'.message_badge($unread).'</a>';
  if ($u['role']==='manager') echo '<a href="'.url('access-requests').'">Access requests</a><a href="'.url('conversations').'">Messages'.message_badge($unread).'</a>';
  if ($u['role']==='manager') echo '<a href="'.url('evidence').'">Evidence</a><a href="'.url('queue').'">AI queue</a>';
  echo '<a href="'.url('reports').'">Reports</a><a href="'.url('settings').'">Settings</a><span class="nav-user">'.e($u['full_name']).' · '.e(ucfirst($u['role'])).'</span><form method="post" action="'.url('dashboard').'" class="inline-form">'.csrf_input().'<input type="hidden" name="action" value="logout"><button class="nav-logout" type="submit">Log out</button></form>';
 } else {
  echo '<a href="'.url('features').'">Features</a><a href="'.url('about').'">About us</a><a href="'.url('contact').'">Contact</a><a href="'.url('login').'">Log in</a><a href="'.url('register').'" class="nav-cta">Get started</a>';
 }
 echo '</nav></div></header>';
 if ($app && $u) {
  echo '<div class="app-frame"><aside class="sidebar"><p class="sidebar-label">WORKSPACE</p><a href="'.url('dashboard').'">▦ &nbsp; Overview</a><a href="'.url('projects').'">▤ &nbsp; My projects</a>';
  if ($u['role'] === 'manager') echo '<a href="'.url('new-project').'">✚ &nbsp; New project</a><a href="'.url('evidence').'">▧ &nbsp; Upload evidence</a><a href="'.url('queue').'">◎ &nbsp; AI review queue</a><a href="'.url('messages').'">✉ &nbsp; Contact messages</a>';
  if ($u['role']==='client') echo '<a href="'.url('find-project').'">⌕ &nbsp; Find by code</a>';
  if ($u['role']==='manager') echo '<a href="'.url('access-requests').'">✓ &nbsp; Access requests</a>';
  echo '<a href="'.url('conversations').'">✉ &nbsp; Project messages'.message_badge($unread).'</a><a href="'.url('reports').'">▤ &nbsp; Reports</a><a href="'.url('settings').'">⚙ &nbsp; Settings</a></aside><main class="app-main">';
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
