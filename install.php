<?php
declare(strict_types=1);
require_once __DIR__.'/inc/core.php';
require_once __DIR__.'/inc/migrations.php';
$error='';$ready=false;$accounts=0;$owners=0;$needUpdate=true;
try {
    $accounts=(int)(one('SELECT COUNT(*) total FROM users')['total']??0);
    $owners=(int)(one("SELECT COUNT(*) total FROM users WHERE role='super_admin'")['total']??0);
    $ready=true;
    $check=one("SELECT COUNT(*) total FROM information_schema.columns WHERE table_schema=? AND table_name='activities' AND column_name='approval_status'",[$config['database']]);
    $userNgo=one("SELECT COUNT(*) total FROM information_schema.columns WHERE table_schema=? AND table_name='users' AND column_name='ngo_id'",[$config['database']]);
    $fundingTable=one("SELECT COUNT(*) total FROM information_schema.tables WHERE table_schema=? AND table_name='project_finances'",[$config['database']]);
    $needUpdate=(int)($check['total']??0)===0||(int)($userNgo['total']??0)===0||(int)($fundingTable['total']??0)===0;
} catch(Throwable $ex) {$ready=false;}
$canSetup=($accounts===0)||($owners===0&&me()&&role()==='manager')||(me()&&role()==='super_admin');
if($_SERVER['REQUEST_METHOD']==='POST'){
    ensure_csrf();$action=(string)($_POST['action']??'');
    try {
        if(!$canSetup)throw new RuntimeException('Database setup is restricted to the Super Admin or an existing manager during the first upgrade.');
        if($action==='setup'){
            pl_setup_database($config);$ready=true;
            $accounts=(int)(one('SELECT COUNT(*) total FROM users')['total']??0);
            $owners=(int)(one("SELECT COUNT(*) total FROM users WHERE role='super_admin'")['total']??0);
            $needUpdate=false;
            if(me() && role()==='manager') {
                $fresh=one('SELECT ngo_id FROM users WHERE id=?',[uid()]);
                $_SESSION['user']['ngo_id']=$fresh['ngo_id']??null;
            }
            flash('Database updated. Existing accounts, projects and photographs remain in place.');
            header('Location: install.php');exit;
        }
        if($action==='owner'){
            if(!$ready||$owners!==0)throw new RuntimeException('Super Admin setup is not available.');
            $name=trim((string)($_POST['full_name']??''));$email=strtolower(trim((string)($_POST['email']??'')));
            $pass=(string)($_POST['password']??'');$confirm=(string)($_POST['confirm_password']??'');
            if(strlen($name)<2||strlen($name)>140||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190||strlen($pass)<12||strlen($pass)>128||$pass!==$confirm)throw new RuntimeException('Provide a valid name, email and matching passwords of at least 12 characters.');
            if(one('SELECT id FROM users WHERE email=?',[$email]))throw new RuntimeException('Use a new email address for the software owner.');
            query("INSERT INTO users(full_name,email,organization,password_hash,role,ngo_id,is_active) VALUES(?,?,?,?,'super_admin',NULL,1)",[$name,$email,'ProjectLens',password_hash($pass,PASSWORD_DEFAULT)]);
            session_regenerate_id(true);
            $_SESSION['user']=['id'=>(int)db()->lastInsertId(),'full_name'=>$name,'email'=>$email,'organization'=>'ProjectLens','role'=>'super_admin','ngo_id'=>null,'is_active'=>1];
            flash('Owner account created. Add NGOs and assign your teams.');go('admin');
        }
        throw new RuntimeException('Unknown setup action.');
    } catch(Throwable $ex){$error=$ex->getMessage();}
}
page_start('ProjectLens database setup');
echo '<section class="install-wrap"><div class="content-card narrow-card"><span class="eyebrow">PROJECTLENS SETUP</span><h1>Database and owner account</h1>';
if($error)echo '<div class="alert alert-error">'.e($error).'</div>';
if(!$canSetup)echo '<p>Existing installations can only be upgraded by the current Super Admin. If no owner exists yet, an existing manager can perform the initial upgrade.</p><a class="btn" href="'.url('login').'">Log in</a>';
else {
    echo '<form method="post" class="form-stack">'.csrf_input().'<input type="hidden" name="action" value="setup"><p>'.($ready?'Use this to add any missing roles, financial tables and NGO controls without deleting existing rows.':'Create the ProjectLens database and its tables.').'</p><button class="btn" type="submit">'.($ready?'Apply safe database upgrade':'Install database').'</button></form>';
    if($ready&&!$needUpdate&&$owners===0){
        echo '<div class="add-form"><h2>Create the first Super Admin</h2><p>The owner account is not available through public registration. Create it once with a strong password.</p><form method="post" class="form-stack">'.csrf_input().'<input type="hidden" name="action" value="owner"><div class="field"><label>Full name</label><input name="full_name" maxlength="140" required></div><div class="field"><label>Email address</label><input name="email" type="email" maxlength="190" required></div><div class="field"><label>Password (12 characters minimum)</label><input name="password" type="password" minlength="12" required></div><div class="field"><label>Confirm password</label><input name="confirm_password" type="password" minlength="12" required></div><button class="btn">Create owner account</button></form></div>';
    } elseif($ready&&$needUpdate)echo '<div class="info-note">Apply the database upgrade before creating the owner account.</div>';
    elseif($ready&&$owners>0)echo '<p>The Super Admin account already exists.</p><a class="btn btn-outline" href="'.url('dashboard').'">Open ProjectLens</a>';
}
echo '<p class="small muted">Once installed, restrict or remove install.php on any publicly accessible server.</p></div></section>';page_end();
