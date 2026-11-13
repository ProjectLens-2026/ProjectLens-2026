<?php
declare(strict_types=1);

function posted_text(string $name,int $limit=255): string {
    $value=trim((string)($_POST[$name]??''));
    if(strlen($value)>$limit) throw new RuntimeException('The '.$name.' field is too long.');
    return $value;
}
function valid_date(string $value): bool {
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$value))return false;
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    return $d!==false && $d->format('Y-m-d')===$value;
}
function decimal_amount(string $value,bool $allowZero=true): string {
    if(!preg_match('/^\d{1,12}(?:\.\d{1,2})?$/',$value))throw new RuntimeException('Enter a valid amount with at most two decimal places.');
    $amount=(float)$value;
    if(!$allowZero && $amount<=0)throw new RuntimeException('The amount must be greater than zero.');
    return number_format($amount,2,'.','');
}
function log_activity(int $aid,int $pid,string $event,string $note): void {
    query('INSERT INTO activity_events(activity_id,project_id,actor_id,event_type,note) VALUES(?,?,?,?,?)',[$aid,$pid,uid(),$event,$note]);
    audit('activity_'.$event,'Activity #'.$aid.'; '.$note,$pid);
}
function handle_action(string $action): never {
    ensure_csrf();
    try {
        switch($action) {
            case 'signup':
                $r=posted_text('role',20);$name=posted_text('full_name',140);$email=strtolower(posted_text('email',190));$org=posted_text('organization',190);
                $job=posted_text('job_title',120);$phone=posted_text('phone',40);$pass=(string)($_POST['password']??'');
                if(!in_array($r,['manager','engineer','client'],true))throw new RuntimeException('Select a valid role.');
                if(!one("SELECT id FROM users WHERE role='super_admin' LIMIT 1"))throw new RuntimeException('The installation owner must create the Super Admin account before new users register. Open install.php.');
                if(strlen($name)<2||strlen($org)<2||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($pass)<8||strlen($pass)>128||$pass!==($_POST['confirm_password']??''))throw new RuntimeException('Check your name, organization, email address and matching passwords (8–128 characters).');
                if(one('SELECT id FROM users WHERE email=?',[$email]))throw new RuntimeException('This email address already has an account.');
                query('INSERT INTO users(full_name,email,organization,job_title,phone,password_hash,role,ngo_id) VALUES(?,?,?,?,?,?,?,NULL)',[$name,$email,$org,$job?:null,$phone?:null,password_hash($pass,PASSWORD_DEFAULT),$r]);
                $new=(int)db()->lastInsertId();
                $_SESSION['user']=['id'=>$new,'full_name'=>$name,'email'=>$email,'organization'=>$org,'role'=>$r,'ngo_id'=>null,'is_active'=>1];
                session_regenerate_id(true);
                audit('account_registered','New '.role_label($r).' account');
                if($r!=='client') {
                    foreach(all("SELECT id FROM users WHERE role='super_admin' AND is_active=1") as $a)notify_user((int)$a['id'],'Account awaiting NGO assignment',$name.' registered as '.role_label($r).'.',url('admin'));
                    flash('Account created. A Super Admin must assign you to an NGO before you can access project work.');
                } else flash('Your client account is ready. Enter a project code to begin.');
                go('dashboard');
            case 'login':
                $email=strtolower(posted_text('email',190));$pass=(string)($_POST['password']??'');
                $row=one('SELECT * FROM users WHERE email=?',[$email]);
                $legacy=$row!==null&&!array_key_exists('is_active',$row);
                if(!$row||!empty($row['deleted_at'])||!password_verify($pass,$row['password_hash'])||(!$legacy&&(int)$row['is_active']!==1))throw new RuntimeException('Incorrect credentials or inactive account.');
                if($legacy&&$row['role']!=='manager')throw new RuntimeException('An existing Project Manager must upgrade the database before this account can sign in.');
                session_regenerate_id(true);
                $_SESSION['user']=array_intersect_key($row,array_flip(['id','full_name','email','organization','role','ngo_id','is_active']));
                $_SESSION['message_login_notice']=true;
                if(!$legacy)audit('login_success','Logged in as '.role_label($row['role']));
                if($legacy){flash('Login successful. Apply the safe database upgrade to continue.');header('Location: install.php');exit;}
                $newSchema=(int)(one("SELECT COUNT(*) total FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='project_finances' AND column_name='approval_status'")['total']??0)>0;
                if(!$newSchema){flash('Version 5 needs the safe database upgrade. Sign in as Super Admin to apply it.');header('Location: install.php');exit;}
                flash('Signed in as '.role_label($row['role']).'.');go('dashboard');
            case 'logout':
                if(me())audit('logout','Signed out');
                $_SESSION=[];session_regenerate_id(true);flash('You have signed out.');go('register');
            case 'admin_ngo_create':
                auth('super_admin');$name=posted_text('ngo_name',190);$description=posted_text('ngo_description',1000);
                if(strlen($name)<3)throw new RuntimeException('Enter an NGO name of at least three characters.');
                query('INSERT INTO ngos(name,description) VALUES(?,?)',[$name,$description]);
                audit('ngo_created','NGO '.$name.' created');flash('NGO added successfully.');go('admin');
            case 'admin_account_assign':
                auth('super_admin');$target=(int)($_POST['user_id']??0);$newRole=posted_text('user_role',20);$ngo=(int)($_POST['ngo_id']??0);$active=(int)($_POST['is_active']??1);
                if($target===uid()||!in_array($newRole,['manager','engineer','client'],true)||!in_array($active,[0,1],true))throw new RuntimeException('Invalid account assignment.');
                $user=one("SELECT * FROM users WHERE id=? AND role<>'super_admin' AND deleted_at IS NULL",[$target]);
                if(!$user)throw new RuntimeException('Account not found or protected.');
                if($newRole!=='client'&&(!$ngo||!one('SELECT id FROM ngos WHERE id=?',[$ngo])))throw new RuntimeException('Managers and engineers must belong to an existing NGO.');
                if($ngo && !one('SELECT id FROM ngos WHERE id=?',[$ngo]))throw new RuntimeException('Unknown NGO.');
                if($user['role']==='manager'&&((int)$user['ngo_id']!==$ngo||$newRole!=='manager')&&one('SELECT id FROM projects WHERE manager_id=? LIMIT 1',[$target]))throw new RuntimeException('This project manager owns projects. Transfer or archive their projects before changing NGO or role.');
                if($user['role']==='engineer'&&((int)$user['ngo_id']!==$ngo||$newRole!=='engineer')&&one('SELECT project_id FROM project_engineers WHERE engineer_id=? LIMIT 1',[$target]))throw new RuntimeException('Remove the engineer from assigned projects before changing their NGO or role.');
                query('UPDATE users SET role=?,ngo_id=?,is_active=? WHERE id=?',[$newRole,$ngo?:null,$active,$target]);
                notify_user($target,'Account assignment updated','Your role is '.role_label($newRole).'. '.($ngo?'You have been assigned to an NGO.':'Your NGO assignment is pending.'),url('dashboard'));
                audit('account_assigned','Account #'.$target.' role '.$newRole.' NGO '.$ngo);
                flash('Account assignment saved.');go('admin');
            case 'project_save':
                auth('manager');$pid=(int)($_POST['project_id']??0);
                $title=posted_text('title',190);$desc=posted_text('description',10000);$loc=posted_text('location',190);$cat=posted_text('category',90);
                $status=posted_text('status',30);$start=posted_text('start_date',12);$end=posted_text('end_date',12);
                $budget=decimal_amount(posted_text('budget_total',30));
                if(!$title||!$desc||!$loc||!$cat||!in_array($status,['on_track','delayed','completed','on_hold'],true))throw new RuntimeException('Complete the required project information.');
                if(($start&&!valid_date($start))||($end&&!valid_date($end))||($start&&$end&&$end<$start))throw new RuntimeException('Enter valid project dates in chronological order.');
                if($pid) {
                    $p=need_project($pid);if(!can_manage_project($p))throw new RuntimeException('Only the assigned project manager can edit this project.');
                    if($status==='completed'&&(float)$p['progress']<99.999)throw new RuntimeException('A project cannot be marked complete until its confirmed activity weights total 100%.');
                    query('UPDATE projects SET title=?,description=?,location=?,category=?,status=?,start_date=?,end_date=?,budget_total=? WHERE id=?',[$title,$desc,$loc,$cat,$status,$start?:null,$end?:null,$budget,$pid]);
                    audit('project_updated','Project details and budget updated',$pid);flash('Project updated.');
                } else {
                    if($status==='completed')throw new RuntimeException('Add and confirm activities before marking a new project complete.');
                    $created=false;
                    for($attempt=0;$attempt<15;$attempt++) {
                        $code=project_code();
                        try {query('INSERT INTO projects(manager_id,ngo_id,project_code,title,description,location,category,status,start_date,end_date,budget_total) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[uid(),ngo_id(),$code,$title,$desc,$loc,$cat,$status,$start?:null,$end?:null,$budget]);$created=true;break;}
                        catch(PDOException $ex){if($ex->getCode()!=='23000')throw $ex;}
                    }
                    if(!$created)throw new RuntimeException('Unable to generate a unique project code.');
                    $pid=(int)db()->lastInsertId();audit('project_created','Created project code '.$code,$pid);
                    flash('Project created. Share code '.$code.' with clients for read-only access.');
                }
                go('project',['id'=>$pid]);
            case 'engineer_assign':
            case 'engineer_unassign':
                auth('manager');$pid=(int)($_POST['project_id']??0);$p=need_project($pid);if(!can_manage_project($p))throw new RuntimeException('Not your project.');
                $eid=(int)($_POST['engineer_id']??0);
                if($action==='engineer_assign') {
                    if(!one("SELECT id FROM users WHERE id=? AND role='engineer' AND ngo_id=? AND is_active=1",[$eid,ngo_id()]))throw new RuntimeException('The site engineer must belong to the same NGO.');
                    query('INSERT IGNORE INTO project_engineers(project_id,engineer_id) VALUES(?,?)',[$pid,$eid]);
                    notify_user($eid,'New project assignment','You have been assigned to '.$p['title'].'.',url('project',['id'=>$pid]));
                    flash('Site engineer assigned.');
                } else {query('DELETE FROM project_engineers WHERE project_id=? AND engineer_id=?',[$pid,$eid]);flash('Engineer removed from this project.');}
                audit($action,'Engineer #'.$eid,$pid);go('project',['id'=>$pid]);
            case 'activity_archive':
                auth(['manager','super_admin']);
                $aid=(int)($_POST['activity_id']??0);$reason=posted_text('reason',500);
                if($aid<1||strlen($reason)<5||empty($_POST['confirm_archive']))throw new RuntimeException('Select an activity, enter a reason of at least five characters and confirm the removal.');
                $target=one('SELECT project_id FROM activities WHERE id=? AND deleted_at IS NULL',[$aid]);
                if(!$target)throw new RuntimeException('Activity not found or already deleted.');
                $pid=(int)$target['project_id'];$p=need_project($pid);
                if(role()==='manager'&&!can_manage_project($p))throw new RuntimeException('Only the assigned Project Manager can delete an activity.');
                db()->beginTransaction();
                try {
                    one('SELECT id FROM projects WHERE id=? FOR UPDATE',[$pid]);
                    $activity=one('SELECT * FROM activities WHERE id=? AND project_id=? AND deleted_at IS NULL FOR UPDATE',[$aid,$pid]);
                    if(!$activity)throw new RuntimeException('This activity has already been deleted.');
                    if(role()==='manager'&&$activity['approval_status']==='approved')throw new RuntimeException('Reopen this confirmed activity before deleting it, or ask the Super Admin to correct it.');
                    $changed=query('UPDATE activities SET deleted_at=NOW(),deleted_by=?,delete_reason=? WHERE id=? AND project_id=? AND deleted_at IS NULL',[uid(),$reason,$aid,$pid]);
                    if($changed->rowCount()!==1)throw new RuntimeException('Activity changed before the deletion. Refresh and retry.');
                    $remaining=(float)(one("SELECT COALESCE(SUM(CASE WHEN approval_status='approved' THEN weight_percent ELSE 0 END),0) progress FROM activities WHERE project_id=? AND deleted_at IS NULL",[$pid])['progress']??0);
                    if($remaining<99.999)query("UPDATE projects SET status='on_track' WHERE id=? AND status='completed'",[$pid]);
                    log_activity($aid,$pid,'archived','Activity deleted: '.$activity['title'].'; weight '.number_format((float)$activity['weight_percent'],2).'%; reason: '.$reason);
                    if(role()==='super_admin'&&(int)$p['manager_id']!==uid())notify_user((int)$p['manager_id'],'Activity removed by Super Admin',$activity['title'].' was removed from '.$p['title'].'.',url('project',['id'=>$pid]));
                    if((int)($activity['submitted_by']??0)>0&&(int)$activity['submitted_by']!==uid())notify_user((int)$activity['submitted_by'],'Activity removed',$activity['title'].' was removed from '.$p['title'].'.',url('project',['id'=>$pid]));
                    if($activity['approval_status']==='approved')foreach(all('SELECT client_id FROM project_clients WHERE project_id=?',[$pid]) as $client)notify_user((int)$client['client_id'],'Project progress adjusted','An activity was removed from '.$p['title'].' and confirmed progress has been recalculated.',url('project',['id'=>$pid]));
                    db()->commit();
                } catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
                flash('Activity deleted from the active project. Its photographs and approval history have been preserved for audit.');
                if(role()==='super_admin'&&($_POST['return_to']??'')==='admin-oversight')go('admin-oversight');
                go('project',['id'=>$pid]);
            case 'activity_restore':
                auth('super_admin');$aid=(int)($_POST['activity_id']??0);$reason=posted_text('reason',500);
                if($aid<1||strlen($reason)<5)throw new RuntimeException('Choose an archived activity and give a restoration reason of at least five characters.');
                $target=one('SELECT project_id FROM activities WHERE id=? AND deleted_at IS NOT NULL',[$aid]);
                if(!$target)throw new RuntimeException('Archived activity not found.');
                $pid=(int)$target['project_id'];$p=need_project($pid);
                db()->beginTransaction();
                try {
                    one('SELECT id FROM projects WHERE id=? FOR UPDATE',[$pid]);
                    $activity=one('SELECT * FROM activities WHERE id=? AND project_id=? AND deleted_at IS NOT NULL FOR UPDATE',[$aid,$pid]);
                    if(!$activity)throw new RuntimeException('The activity has already been restored.');
                    $weight=(float)(one('SELECT COALESCE(SUM(weight_percent),0) total FROM activities WHERE project_id=? AND deleted_at IS NULL',[$pid])['total']??0);
                    if($weight+(float)$activity['weight_percent']>100.001)throw new RuntimeException('Restoring this activity would exceed the project weight limit of 100%. Reduce existing weights first.');
                    $changed=query("UPDATE activities SET deleted_at=NULL,deleted_by=NULL,delete_reason=NULL,approval_status='pending',progress_percent=0,submitted_by=NULL,submitted_at=NULL,confirmed_by=NULL,confirmed_at=NULL,manager_note=? WHERE id=? AND project_id=? AND deleted_at IS NOT NULL",['Restored by Super Admin: '.$reason,$aid,$pid]);
                    if($changed->rowCount()!==1)throw new RuntimeException('Activity changed before restoration. Refresh and retry.');
                    log_activity($aid,$pid,'restored','Activity restored for new approval: '.$activity['title'].'; reason: '.$reason);
                    notify_user((int)$p['manager_id'],'Activity restored by Super Admin',$activity['title'].' was restored to '.$p['title'].' and must be reviewed again.',url('project',['id'=>$pid]));
                    db()->commit();
                } catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
                flash('Activity restored as pending. Any earlier approval does not automatically count toward progress.');go('admin-oversight');
            case 'activity_save':
                auth('manager');$pid=(int)($_POST['project_id']??0);$p=need_project($pid);if(!can_manage_project($p))throw new RuntimeException('Not your project.');
                $aid=(int)($_POST['activity_id']??0);$title=posted_text('title',190);$target=posted_text('target_description',255);
                $weight=(float)decimal_amount(posted_text('weight_percent',12),false);$days=filter_var($_POST['duration_days']??null,FILTER_VALIDATE_INT);$start=posted_text('activity_start',12);
                if(!$title||!$target||$weight>100||$days===false||$days<1||$days>36500||($start&&!valid_date($start)))throw new RuntimeException('Set a title, target, weight (0.01–100%), duration (1–36500 days), and valid start date.');
                $end=$start?(new DateTimeImmutable($start))->modify('+'.($days-1).' days')->format('Y-m-d'):null;
                db()->beginTransaction();
                try {
                    one('SELECT id FROM projects WHERE id=? FOR UPDATE',[$pid]);
                    $other=one('SELECT COALESCE(SUM(weight_percent),0) total FROM activities WHERE project_id=? AND deleted_at IS NULL AND id<>?',[$pid,$aid]);
                    if((float)$other['total']+$weight>100.001)throw new RuntimeException('Activity weights cannot exceed 100% of the project. Available weight: '.number_format(max(0,100-(float)$other['total']),2).'%');
                    if($aid) {
                        $act=one('SELECT * FROM activities WHERE id=? AND project_id=? AND deleted_at IS NULL',[$aid,$pid]);if(!$act)throw new RuntimeException('Activity not found.');
                        if($act['approval_status']==='approved')throw new RuntimeException('Reopen this activity before editing an approved item.');
                        query('UPDATE activities SET title=?,target_description=?,weight_percent=?,duration_days=?,start_date=?,end_date=? WHERE id=?',[$title,$target,$weight,$days,$start?:null,$end,$aid]);
                        log_activity($aid,$pid,'edited','Details, weight and duration changed');
                    } else {
                        query('INSERT INTO activities(project_id,title,target_description,progress_percent,weight_percent,duration_days,start_date,end_date,approval_status) VALUES(?,?,?,0,?,?,?,? ,\'pending\')',[$pid,$title,$target,$weight,$days,$start?:null,$end]);
                        $aid=(int)db()->lastInsertId();log_activity($aid,$pid,'created','Activity created with weight '.$weight.'%');
                    }
                    db()->commit();
                } catch(Throwable $ex) {if(db()->inTransaction())db()->rollBack();throw $ex;}
                flash('Activity saved. Overall progress changes only after confirmation.');go('project',['id'=>$pid]);
            case 'activity_submit':
                auth('engineer');$pid=(int)($_POST['project_id']??0);$p=need_project($pid);
                if(!one('SELECT 1 FROM project_engineers WHERE project_id=? AND engineer_id=?',[$pid,uid()]))throw new RuntimeException('You are not assigned to this project.');
                $aid=(int)($_POST['activity_id']??0);$act=one('SELECT * FROM activities WHERE project_id=? AND id=? AND deleted_at IS NULL',[$pid,$aid]);
                if(!$act||$act['approval_status']==='approved')throw new RuntimeException('This activity is not available for submission.');
                if(empty($_POST['work_completed']))throw new RuntimeException('Tick the completion confirmation before submitting.');
                $note=posted_text('engineer_note',1000);if(strlen($note)<3)throw new RuntimeException('Briefly describe the completed work.');
                $submitStmt=query("UPDATE activities SET approval_status='submitted',submitted_by=?,submitted_at=NOW(),engineer_note=?,manager_note=NULL,progress_percent=0 WHERE id=? AND deleted_at IS NULL AND approval_status<>'approved'",[uid(),$note,$aid]);
                if(!$submitStmt->rowCount())throw new RuntimeException('This activity was changed. Refresh the project page.');
                log_activity($aid,$pid,'submitted',$note);
                notify_user((int)$p['manager_id'],'Activity awaiting approval',$act['title'].' has been submitted by a site engineer.',url('project',['id'=>$pid]));
                flash('Completion submitted. Project progress will update after the manager confirms.');go('project',['id'=>$pid]);
            case 'activity_decision':
                auth('manager');$pid=(int)($_POST['project_id']??0);$p=need_project($pid);if(!can_manage_project($p))throw new RuntimeException('Not your project.');
                $aid=(int)($_POST['activity_id']??0);$act=one('SELECT * FROM activities WHERE id=? AND project_id=? AND deleted_at IS NULL',[$aid,$pid]);
                $decision=posted_text('decision',20);$note=posted_text('manager_note',1000);
                if(!$act||!in_array($decision,['approved','rejected','reopened'],true))throw new RuntimeException('Invalid activity decision.');
                if($decision==='reopened'&&$act['approval_status']!=='approved')throw new RuntimeException('Only approved activities can be reopened.');
                if($decision==='rejected'&&$act['approval_status']!=='submitted')throw new RuntimeException('Only submitted activities can be rejected.');
                if($decision==='approved'&&$act['approval_status']!=='submitted')throw new RuntimeException('Only site engineer submissions can be approved.');
                if($decision==='approved' && (int)(one("SELECT COUNT(*) total FROM evidence WHERE project_id=? AND activity_id=? AND review_status='approved'",[$pid,$aid])['total']??0)<1)throw new RuntimeException('Review and approve at least one site photograph for this activity before confirming work.');
                if(in_array($decision,['rejected','reopened'],true)&&strlen($note)<3)throw new RuntimeException('Explain the reason for returning or reopening the activity.');
                $state=$decision==='reopened'?'pending':$decision;
                $decisionStmt=query('UPDATE activities SET approval_status=?,progress_percent=?,confirmed_by=?,confirmed_at=?,manager_note=? WHERE id=? AND deleted_at IS NULL AND approval_status=?',[$state,$state==='approved'?100:0,$state==='approved'?uid():null,$state==='approved'?date('Y-m-d H:i:s'):null,$note?:null,$aid,$act['approval_status']]);
                if(!$decisionStmt->rowCount())throw new RuntimeException('The activity changed before this decision. Please refresh.');
                $confirmed=one("SELECT COALESCE(SUM(CASE WHEN approval_status='approved' THEN weight_percent ELSE 0 END),0) AS completed FROM activities WHERE project_id=? AND deleted_at IS NULL",[$pid]);
                if((float)$confirmed['completed']>=99.999)query("UPDATE projects SET status='completed' WHERE id=?",[$pid]);
                else query("UPDATE projects SET status='on_track' WHERE id=? AND status='completed'",[$pid]);
                log_activity($aid,$pid,$decision,$note?:$decision);
                if($act['submitted_by'])notify_user((int)$act['submitted_by'],'Activity '.$decision,$act['title'].' was '.$decision.' by the project manager.',url('project',['id'=>$pid]));
                flash($decision==='approved'?'Activity confirmed. Project completion has been recalculated.':'Activity returned to an unconfirmed status.');go('project',['id'=>$pid]);
            case 'engineer_join':
                auth('engineer');$code=strtoupper(posted_text('project_code',6));
                if(!preg_match('/^[A-HJ-NP-Z2-9]{6}$/',$code))throw new RuntimeException('Invalid project code.');
                $target=one('SELECT id,ngo_id,title,manager_id FROM projects WHERE project_code=?',[$code]);
                if(!$target||!engineer_can_access_code($target))throw new RuntimeException('The code does not match a project in your assigned NGO.');
                query('INSERT IGNORE INTO project_engineers(project_id,engineer_id) VALUES(?,?)',[$target['id'],uid()]);
                notify_user((int)$target['manager_id'],'Site engineer connected',me()['full_name'].' joined '.$target['title'].' using its project code.',url('project',['id'=>(int)$target['id']]));
                audit('engineer_joined_code','Joined using project code',(int)$target['id']);
                flash('Project added to your workspace.');go('project',['id'=>(int)$target['id']]);
            case 'engineer_finance_submit':
                auth('engineer');$pid=(int)($_POST['project_id']??0);$pr=need_project($pid);
                $amount=decimal_amount(posted_text('amount',30),false);$date=posted_text('entry_date',12);
                $desc=posted_text('description',255);$ref=posted_text('reference',100);
                if(!valid_date($date)||strlen($desc)<3)throw new RuntimeException('Provide a valid date and expense description.');
                query("INSERT INTO project_finances(project_id,entered_by,entry_type,amount,entry_date,description,reference,approval_status) VALUES(?,?,'expense',?,?,?,?,'submitted')",[$pid,uid(),$amount,$date,$desc,$ref?:null]);
                audit('expense_submitted','Expense awaiting approval: NGN '.$amount.'; '.$desc,$pid);
                notify_user((int)$pr['manager_id'],'Site expense needs approval','An expense was submitted for '.$pr['title'].'.',url('project',['id'=>$pid]));
                flash('Expense sent to your Project Manager for approval. It is not yet visible to clients.');go('project',['id'=>$pid]);
            case 'finance_decision':
                auth('manager');$pid=(int)($_POST['project_id']??0);$pr=need_project($pid);
                if(!can_manage_project($pr))throw new RuntimeException('Only the project manager can review expenses.');
                $fid=(int)($_POST['finance_id']??0);$decision=posted_text('decision',20);$note=posted_text('manager_note',1000);
                if(!in_array($decision,['approved','rejected'],true))throw new RuntimeException('Invalid decision.');
                if($decision==='rejected'&&strlen($note)<3)throw new RuntimeException('A rejection reason is required.');
                $expense=one("SELECT * FROM project_finances WHERE id=? AND project_id=? AND entry_type='expense' AND approval_status='submitted'",[$fid,$pid]);
                if(!$expense)throw new RuntimeException('This expense is no longer awaiting review.');
                query("UPDATE project_finances SET approval_status=?,reviewed_by=?,reviewed_at=NOW(),manager_note=? WHERE id=? AND approval_status='submitted'",[$decision,uid(),$note?:null,$fid]);
                audit('expense_'.$decision,'Finance entry #'.$fid.' '.$note,$pid);
                notify_user((int)$expense['entered_by'],'Expense '.$decision,'Your site expense has been '.$decision.'.',url('project',['id'=>$pid]));
                if($decision==='approved')foreach(all('SELECT client_id FROM project_clients WHERE project_id=?',[$pid]) as $c)notify_user((int)$c['client_id'],'Approved financial update','An expense has been approved for '.$pr['title'].'.',url('project',['id'=>$pid]));
                flash('Expense '.$decision.'.');go('project',['id'=>$pid]);
            case 'finance_add':
                auth('manager');$pid=(int)($_POST['project_id']??0);$p=need_project($pid);if(!can_manage_project($p))throw new RuntimeException('Not your project.');
                $type=posted_text('entry_type',20);$amount=decimal_amount(posted_text('amount',30),false);$date=posted_text('entry_date',12);$desc=posted_text('description',255);$ref=posted_text('reference',100);
                if(!in_array($type,['funding','expense'],true)||!valid_date($date)||strlen($desc)<3)throw new RuntimeException('Enter a valid transaction type, date and description.');
                query("INSERT INTO project_finances(project_id,entered_by,entry_type,amount,entry_date,description,reference,approval_status,reviewed_by,reviewed_at) VALUES(?,?,?,?,?,?,?,'approved',?,NOW())",[$pid,uid(),$type,$amount,$date,$desc,$ref?:null,uid()]);
                audit('finance_'.$type,$amount.' NGN; '.$desc,$pid);
                foreach(all('SELECT client_id FROM project_clients WHERE project_id=?',[$pid]) as $c)notify_user((int)$c['client_id'],'Project financial update',ucfirst($type).' recorded for '.$p['title'].'.',url('project',['id'=>$pid]));
                flash('Financial entry saved and reflected in the project dashboard.');go('project',['id'=>$pid]);
            case 'client_join':
                auth('client');$code=strtoupper(posted_text('project_code',6));
                if(!preg_match('/^[A-HJ-NP-Z2-9]{6}$/',$code))throw new RuntimeException('Enter a valid six-character project code.');
                $p=one('SELECT id,manager_id,title FROM projects WHERE project_code=?',[$code]);if(!$p)throw new RuntimeException('No project matches this code.');
                query('INSERT IGNORE INTO project_clients(project_id,client_id) VALUES(?,?)',[$p['id'],uid()]);
                notify_user((int)$p['manager_id'],'Client connected to project',me()['full_name'].' used the shared code to follow '.$p['title'].'.',url('project',['id'=>(int)$p['id']]));
                audit('client_joined','Client linked through project code',(int)$p['id']);
                flash('Project linked to your account. Progress and financial figures update when the manager records changes.');go('project',['id'=>(int)$p['id']]);
            case 'notification_read':
                auth();$nid=(int)($_POST['notification_id']??0);
                query('UPDATE notifications SET read_at=NOW() WHERE id=? AND user_id=? AND read_at IS NULL',[$nid,uid()]);
                go('notifications');
            case 'client_assign':
            case 'client_remove':
                auth('manager');$pid=(int)($_POST['project_id']??0);$p=need_project($pid);if(!can_manage_project($p))throw new RuntimeException('Not your project.');
                $cid=(int)($_POST['client_id']??0);
                if(!one("SELECT id FROM users WHERE id=? AND role='client' AND is_active=1",[$cid]))throw new RuntimeException('Client account is invalid.');
                if($action==='client_assign'){query('INSERT IGNORE INTO project_clients(project_id,client_id) VALUES(?,?)',[$pid,$cid]);notify_user($cid,'Project access granted','You can view '.$p['title'].'.',url('project',['id'=>$pid]));flash('Client connected.');}
                else {query('DELETE FROM project_clients WHERE project_id=? AND client_id=?',[$pid,$cid]);flash('Client access removed.');}
                audit($action,'Client #'.$cid,$pid);go('project',['id'=>$pid]);
            case 'engineer_message_send':
                auth(['manager','engineer']);$pid=(int)($_POST['project_id']??0);$pr=need_project($pid);
                $eid=role()==='engineer'?uid():(int)($_POST['engineer_id']??0);
                if(!engineer_can_message($pr,$eid))throw new RuntimeException('The site engineer is not assigned to this project.');
                $body=posted_text('body',3000);if(strlen($body)<2)throw new RuntimeException('Enter your message.');
                query('INSERT INTO engineer_messages(project_id,engineer_id,sender_id,body) VALUES(?,?,?,?)',[$pid,$eid,uid(),$body]);
                $recipient=role()==='engineer'?(int)$pr['manager_id']:$eid;
                notify_user($recipient,'New site message','You received a message about '.$pr['title'].'.',url('engineer-chat',['id'=>$pid,'engineer'=>$eid]));
                audit('site_message_sent','Site message sent',$pid);
                flash('Message sent.');go('engineer-chat',['id'=>$pid,'engineer'=>$eid]);
            case 'project_message_send':
                auth(['client','manager']);$pid=(int)($_POST['project_id']??0);$p=need_project($pid);
                $cid=role()==='client'?uid():(int)($_POST['client_id']??0);
                if(!one('SELECT 1 FROM project_clients WHERE project_id=? AND client_id=?',[$pid,$cid]))throw new RuntimeException('Client is not linked to this project.');
                $body=posted_text('body',3000);if(strlen($body)<2)throw new RuntimeException('Enter a message.');
                query('INSERT INTO project_messages(project_id,client_id,sender_id,body) VALUES(?,?,?,?)',[$pid,$cid,uid(),$body]);
                $recipient=role()==='manager'?$cid:(int)$p['manager_id'];
                notify_user($recipient,'New project message','You have received a message about '.$p['title'].'.',url('conversation',['id'=>$pid,'client'=>$cid]));
                flash('Message sent.');go('conversation',['id'=>$pid,'client'=>$cid]);
            case 'upload':
                auth(['manager','engineer']);require_once dirname(__DIR__).'/inc/image_similarity.php';
                $pid=(int)($_POST['project_id']??0);$p=need_project($pid);
                $aid=(int)($_POST['activity_id']??0);
                if(!one('SELECT id FROM activities WHERE id=? AND project_id=? AND deleted_at IS NULL',[$aid,$pid]))throw new RuntimeException('Choose an activity belonging to your project.');
                $date=posted_text('evidence_date',12);if(!valid_date($date))throw new RuntimeException('Choose a valid image date.');
                $file=$_FILES['photo']??null;
                if(!$file||$file['error']!==UPLOAD_ERR_OK||$file['size']>8*1024*1024||$file['size']<1||!is_uploaded_file($file['tmp_name']))throw new RuntimeException('Choose an image smaller than 8 MB.');
                $tmp=$file['tmp_name'];$mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);$ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??null;$size=@getimagesize($tmp);
                if(!$ext||!$size||$size[0]>8000||$size[1]>8000||$size[0]*$size[1]>16000000)throw new RuntimeException('Choose a valid JPG, PNG or WebP image up to 16 megapixels.');
                $dh=image_dhash($tmp,$mime);$sha=hash_file('sha256',$tmp);$matched=null;$best=0;
                foreach(all('SELECT ev.id,ev.sha256,ev.dhash FROM evidence ev JOIN projects p ON p.id=ev.project_id WHERE p.ngo_id=?',[ (int)$p['ngo_id'] ]) as $prior) {
                    $score=hash_equals($sha,$prior['sha256'])?100.0:image_similarity($dh,$prior['dhash']);
                    if($score>$best){$best=$score;$matched=(int)$prior['id'];}
                }
                $status=$best>=95?'pending_review':'unique_ready';$name=bin2hex(random_bytes(18)).'.'.$ext;$path=dirname(__DIR__).'/storage/evidence/'.$name;
                if(!move_uploaded_file($tmp,$path))throw new RuntimeException('Unable to save image. Check storage permissions.');
                try {query('INSERT INTO evidence(project_id,activity_id,uploader_id,image_file,original_name,mime_type,evidence_date,sha256,dhash,max_similarity,matched_evidence_id,review_status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$pid,$aid,uid(),$name,substr(basename((string)$file['name']),0,180),$mime,$date,$sha,$dh,$best,$matched,$status]);}
                catch(Throwable $ex){@unlink($path);throw $ex;}
                audit('evidence_uploaded','Image similarity '.number_format($best,2).'%',$pid);
                if(role()==='engineer')notify_user((int)$p['manager_id'],'Image awaiting review','New site evidence for '.$p['title'].'.',url('queue'));
                flash('Image saved. Similarity: '.number_format($best,1).'%. Manager review is required.');go(role()==='manager'?'queue':'project',role()==='manager'?[]:['id'=>$pid]);
            case 'evidence_decision':
                auth('manager');$eid=(int)($_POST['evidence_id']??0);$decision=posted_text('decision',20);
                $ev=one('SELECT ev.* FROM evidence ev JOIN projects p ON p.id=ev.project_id JOIN activities a ON a.id=ev.activity_id AND a.deleted_at IS NULL WHERE ev.id=? AND p.manager_id=? AND p.ngo_id=?',[$eid,uid(),ngo_id()]);
                if(!$ev||!in_array($decision,['approved','rejected'],true)||!in_array($ev['review_status'],['pending_review','unique_ready'],true))throw new RuntimeException('Invalid image or decision.');
                $note=posted_text('review_note',500);
                if($ev['review_status']==='pending_review'&&$decision==='approved'&&!$note)throw new RuntimeException('Explain why the similar image is acceptable.');
                query('UPDATE evidence SET review_status=?,review_note=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?',[$decision,$note?:null,uid(),$eid]);
                audit('evidence_'.$decision,'Evidence #'.$eid,(int)$ev['project_id']);
                if((int)$ev['uploader_id']!==uid())notify_user((int)$ev['uploader_id'],'Image '.$decision,'Your uploaded project image was '.$decision.'.',url('project',['id'=>(int)$ev['project_id']]));
                flash('Evidence '.$decision.'.');go('queue');
            case 'admin_account_delete':
                auth('super_admin');$target=(int)($_POST['user_id']??0);$reason=posted_text('reason',500);
                if($target===uid()||strlen($reason)<5)throw new RuntimeException('Choose a different account and give a reason.');
                $u=one("SELECT id,role FROM users WHERE id=? AND role<>'super_admin' AND deleted_at IS NULL",[$target]);
                if(!$u)throw new RuntimeException('Account not available.');
                if($u['role']==='manager'&&one('SELECT id FROM projects WHERE manager_id=? LIMIT 1',[$target]))throw new RuntimeException('Transfer all projects to another Project Manager before removing this account.');
                query('UPDATE users SET is_active=0,deleted_at=NOW() WHERE id=?',[$target]);
                audit('account_archived','Account #'.$target.' archived. Reason: '.$reason);
                flash('Account disabled and removed from active accounts. Its historical records remain available for audit.');go('admin');
            case 'admin_account_restore':
                auth('super_admin');$target=(int)($_POST['user_id']??0);$reason=posted_text('reason',500);
                if(strlen($reason)<5)throw new RuntimeException('Give a reason for restoring the account.');
                query("UPDATE users SET is_active=1,deleted_at=NULL WHERE id=? AND role<>'super_admin' AND deleted_at IS NOT NULL",[$target]);
                audit('account_restored','Account #'.$target.' restored. Reason: '.$reason);
                flash('Account restored.');go('admin');
            case 'admin_finance_void':
                auth('super_admin');$fid=(int)($_POST['finance_id']??0);$reason=posted_text('reason',500);
                if(strlen($reason)<5)throw new RuntimeException('Give a reason for the financial correction.');
                $entry=one("SELECT * FROM project_finances WHERE id=? AND approval_status='approved'",[$fid]);
                if(!$entry)throw new RuntimeException('Approved financial entry not found.');
                query("UPDATE project_finances SET approval_status='rejected',reviewed_by=?,reviewed_at=NOW(),manager_note=? WHERE id=? AND approval_status='approved'",[uid(),'Owner correction: '.$reason,$fid]);
                audit('finance_voided','Financial record #'.$fid.' voided. Reason: '.$reason,(int)$entry['project_id']);
                $projectInfo=one('SELECT manager_id,title FROM projects WHERE id=?',[$entry['project_id']]);
                if($projectInfo)notify_user((int)$projectInfo['manager_id'],'Owner financial correction','An entry was voided for '.$projectInfo['title'].'.',url('project',['id'=>(int)$entry['project_id']]));
                flash('Entry removed from approved financial totals. Audit record retained.');go('admin-oversight');
            case 'admin_transfer_project':
                auth('super_admin');$pid=(int)($_POST['project_id']??0);$newManager=(int)($_POST['manager_id']??0);$reason=posted_text('reason',500);
                $pr=need_project($pid);
                if(strlen($reason)<5)throw new RuntimeException('Explain the project transfer.');
                $u=one("SELECT id FROM users WHERE id=? AND role='manager' AND ngo_id=? AND is_active=1 AND deleted_at IS NULL",[$newManager,$pr['ngo_id']]);
                if(!$u)throw new RuntimeException('Choose an active Project Manager in the same NGO.');
                if((int)$pr['manager_id']===$newManager)throw new RuntimeException('That manager already owns the project.');
                query('UPDATE projects SET manager_id=? WHERE id=?',[$newManager,$pid]);
                audit('manager_transferred','Project Manager #'.$pr['manager_id'].' to #'.$newManager.'; '.$reason,$pid);
                notify_user($newManager,'Project ownership transferred','You are now the manager of '.$pr['title'].'.',url('project',['id'=>$pid]));
                notify_user((int)$pr['manager_id'],'Project transferred','Your project '.$pr['title'].' has been assigned to another manager.',url('dashboard'));
                flash('Project Manager assignment updated.');go('admin-oversight');
            case 'admin_budget_adjust':
                auth('super_admin');$pid=(int)($_POST['project_id']??0);$pr=need_project($pid);
                $budget=decimal_amount(posted_text('budget_total',30));$reason=posted_text('reason',500);
                if(strlen($reason)<5)throw new RuntimeException('Explain the budget correction.');
                query('UPDATE projects SET budget_total=? WHERE id=?',[$budget,$pid]);
                audit('budget_corrected','Budget changed from NGN '.$pr['budget_total'].' to NGN '.$budget.'; '.$reason,$pid);
                notify_user((int)$pr['manager_id'],'Owner adjusted budget','Budget for '.$pr['title'].' was corrected.',url('project',['id'=>$pid]));
                flash('Budget corrected with an audit record.');go('admin-oversight');
            case 'admin_activity_fix':
                auth('super_admin');$pid=(int)($_POST['project_id']??0);$pr=need_project($pid);
                $aid=(int)($_POST['activity_id']??0);$decision=posted_text('new_status',20);$reason=posted_text('reason',500);
                if(strlen($reason)<5||!in_array($decision,['pending','approved'],true))throw new RuntimeException('Provide a valid correction and reason.');
                $activity=one('SELECT id,title,approval_status FROM activities WHERE id=? AND project_id=? AND deleted_at IS NULL',[$aid,$pid]);
                if(!$activity)throw new RuntimeException('Activity not found.');
                query('UPDATE activities SET approval_status=?,progress_percent=?,confirmed_by=?,confirmed_at=?,manager_note=? WHERE id=? AND deleted_at IS NULL',[$decision,$decision==='approved'?100:0,$decision==='approved'?uid():null,$decision==='approved'?date('Y-m-d H:i:s'):null,'Owner correction: '.$reason,$aid]);
                if($decision==='approved'){ $summary=one("SELECT COALESCE(SUM(CASE WHEN approval_status='approved' THEN weight_percent ELSE 0 END),0) total FROM activities WHERE project_id=? AND deleted_at IS NULL",[$pid]);if((float)$summary['total']>=99.999)query("UPDATE projects SET status='completed' WHERE id=?",[$pid]); }
                else query("UPDATE projects SET status='on_track' WHERE id=? AND status='completed'",[$pid]);
                audit('activity_corrected','Activity #'.$aid.' '.($activity['approval_status']).' to '.$decision.'; '.$reason,$pid);
                notify_user((int)$pr['manager_id'],'Owner corrected an activity','Activity '.$activity['title'].' has been corrected.',url('project',['id'=>$pid]));
                flash('Activity status corrected with an audit record.');go('admin-oversight');
            case 'support_send':
                auth();$subject=posted_text('subject',190);$body=posted_text('body',3000);
                if(strlen($subject)<3||strlen($body)<10)throw new RuntimeException('Enter a subject and describe the issue in at least ten characters.');
                query('INSERT INTO support_tickets(user_id,subject,body) VALUES(?,?,?)',[uid(),$subject,$body]);
                $ticketId=(int)db()->lastInsertId();audit('support_requested','Support ticket #'.$ticketId.' created');
                foreach(all("SELECT id FROM users WHERE role='super_admin' AND is_active=1 AND deleted_at IS NULL") as $a)notify_user((int)$a['id'],'New support request',$subject,url('admin-oversight'));
                flash('Your support request has been sent to the Super Admin.');go('support');
            case 'support_resolve':
                auth('super_admin');$ticketId=(int)($_POST['ticket_id']??0);$reply=posted_text('owner_reply',3000);
                if(strlen($reply)<3)throw new RuntimeException('Enter a response for the user.');
                $ticket=one('SELECT * FROM support_tickets WHERE id=?',[$ticketId]);
                if(!$ticket)throw new RuntimeException('Support request not found.');
                query("UPDATE support_tickets SET status='resolved',owner_reply=?,resolved_by=? WHERE id=?",[$reply,uid(),$ticketId]);
                audit('support_resolved','Support ticket #'.$ticketId.' resolved');
                notify_user((int)$ticket['user_id'],'Support request answered','The Super Admin responded to your request.',url('support'));
                flash('Support response recorded.');go('admin-oversight');
            case 'admin_finance_correct':
                auth('super_admin');$pid=(int)($_POST['project_id']??0);$pr=need_project($pid);
                $type=posted_text('entry_type',20);$amount=decimal_amount(posted_text('amount',30),false);
                $date=posted_text('entry_date',12);$desc=posted_text('description',255);$reason=posted_text('reason',500);
                if(!in_array($type,['funding','expense'],true)||!valid_date($date)||strlen($desc)<3||strlen($reason)<5)throw new RuntimeException('Provide valid correction details and a reason.');
                query("INSERT INTO project_finances(project_id,entered_by,entry_type,amount,entry_date,description,reference,approval_status,reviewed_by,reviewed_at,manager_note) VALUES(?,?,?,?,?,?,?,'approved',?,NOW(),?)",[$pid,uid(),$type,$amount,$date,$desc,'OWNER-CORRECTION',uid(),$reason]);
                audit('finance_corrected','Approved owner correction: '.$type.' NGN '.$amount.'; '.$reason,$pid);
                notify_user((int)$pr['manager_id'],'Owner financial correction','Financial information was corrected for '.$pr['title'].'.',url('project',['id'=>$pid]));
                flash('Correction saved and audited.');go('admin-oversight');
            case 'settings_save':
                auth();$name=posted_text('full_name',140);$org=posted_text('organization',190);
                if(strlen($name)<2||strlen($org)<2)throw new RuntimeException('Enter a valid name and organisation.');
                query('UPDATE users SET full_name=?,organization=? WHERE id=?',[$name,$org,uid()]);
                $_SESSION['user']['full_name']=$name;$_SESSION['user']['organization']=$org;
                flash('Profile updated.');go('settings');
            case 'password_save':
                auth();$user=one('SELECT password_hash FROM users WHERE id=?',[uid()]);
                $old=(string)($_POST['old_password']??'');$new=(string)($_POST['new_password']??'');
                if(!password_verify($old,$user['password_hash'])||strlen($new)<8||strlen($new)>128||$new!==($_POST['confirm_password']??''))throw new RuntimeException('Check the current password and matching new passwords.');
                query('UPDATE users SET password_hash=? WHERE id=?',[password_hash($new,PASSWORD_DEFAULT),uid()]);flash('Password updated.');go('settings');
            case 'contact':
                $name=posted_text('sender_name',140);$email=posted_text('sender_email',190);$subject=posted_text('subject',190);$body=posted_text('message',5000);
                if(strlen($name)<2||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($subject)<2||strlen($body)<10)throw new RuntimeException('Complete the contact form with valid information.');
                query('INSERT INTO contact_messages(sender_name,sender_email,subject,message) VALUES(?,?,?,?)',[$name,$email,$subject,$body]);
                foreach(all("SELECT id FROM users WHERE role='super_admin' AND is_active=1") as $a)notify_user((int)$a['id'],'New contact enquiry',$subject,url('notifications'));
                flash('Message received.');go('contact');
            default: throw new RuntimeException('Invalid operation.');
        }
    } catch(Throwable $ex) {
        $msg=$ex instanceof PDOException?'Database action failed. Check XAMPP, database installation and field values.':$ex->getMessage();
        flash($msg,'error');
        $fallback=match($action) {
            'signup'=>'register','login'=>'login','project_save'=>'projects','activity_save','activity_submit','activity_decision','engineer_assign','engineer_unassign','finance_add','client_assign','client_remove'=>'project',
            'admin_ngo_create','admin_account_assign','admin_account_delete','admin_account_restore'=>'admin','client_join'=>'find-project','engineer_join'=>'engineer-find','project_message_send'=>'conversation','engineer_message_send'=>'engineer-chat','evidence_decision'=>'queue','upload'=>'evidence',
            'engineer_finance_submit','finance_decision'=>'project','activity_archive'=>role()==='super_admin'?'admin-oversight':'project','activity_restore'=>'admin-oversight','admin_finance_void','admin_finance_correct','admin_transfer_project','admin_budget_adjust','admin_activity_fix','support_resolve'=>'admin-oversight','support_send'=>'support','settings_save','password_save'=>'settings','notification_read'=>'notifications','contact'=>'contact',default=>'dashboard'
        };
        $args=in_array($fallback,['project','conversation','engineer-chat'],true)?['id'=>(int)($_POST['project_id']??0)]:[];
        if($fallback==='register')$args=['role'=>(string)($_POST['role']??'client')];
        if($fallback==='conversation')$args['client']=(int)($_POST['client_id']??uid());
        if($fallback==='engineer-chat')$args['engineer']=(int)($_POST['engineer_id']??uid());
        go($fallback,$args);
    }
}
