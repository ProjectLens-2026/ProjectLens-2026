<?php
require_once __DIR__.'/inc/core.php';
require_once __DIR__.'/inc/migrations.php';
$error='';$success=false;$found=false;$needsUpgrade=false;$hasAccounts=false;
try {
    $found=(bool)(one("SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema=? AND table_name='users'",[$config['database']])['c']??0);
    if ($found) {
        $hasAccounts=(int)(one('SELECT COUNT(*) AS c FROM users')['c']??0)>0;
        $column=one("SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema=? AND table_name='projects' AND column_name='project_code'",[$config['database']]);
        $reads=one("SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema=? AND table_name='project_message_reads'",[$config['database']]);
        $needsUpgrade=((int)($column['c']??0)===0 || (int)($reads['c']??0)===0);
    }
} catch(Throwable $ignored) {  }
$allowed=!$hasAccounts || (me() && me()['role']==='manager');
if ($_SERVER['REQUEST_METHOD']==='POST') {
    ensure_csrf();
    if (!$allowed) $error='For an existing ProjectLens installation, please log in as an NGO Manager before updating the database.';
    else { try { $count=pl_setup_database($config);$success=true;$found=true;$needsUpgrade=false; } catch(Throwable $ex) {$error=$ex->getMessage();} }
}
page_start('Database setup');
echo '<section class="install-wrap"><div class="content-card"><span class="eyebrow">PROJECTLENS SETUP</span><h1>'.($found?'Update your existing database':'Install ProjectLens database').'</h1>';
echo '<p>This non-destructive setup adds or updates project codes, client messaging and unread-message tracking. Your existing users, projects and uploaded photos are preserved.</p>';
if ($success) echo '<div class="alert alert-good">Database ready! Your existing records have been kept.</div><a class="btn" href="'.url(me()?'dashboard':'register').'">Continue to ProjectLens</a>';
elseif (!$allowed) echo '<div class="info-note">An existing installation needs a logged-in Manager to update its database.</div><a class="btn" href="'.url('login').'">Log in as Manager</a>';
else {
    if ($error) echo '<div class="alert alert-error">'.e($error).'</div>';
    if (!$found || $needsUpgrade) echo '<div class="info-note">'.($found?'Upgrade available: unread-message notifications need the latest database tables.':'This looks like a fresh installation.').'</div>';
    echo '<form method="post">'.csrf_input().'<button class="btn" type="submit">'.($found?'Update database safely':'Install ProjectLens database').'</button></form>';
}
echo '<p class="small muted">Local address: <code>http://localhost/ProjectLens/</code></p></div></section>';
page_end();
