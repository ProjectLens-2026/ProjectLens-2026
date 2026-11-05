<?php
declare(strict_types=1);
require_once __DIR__.'/inc/core.php';
$page = (string)($_GET['page'] ?? 'register');
if ($page==='home') $page='register';
$id = max(0,(int)($_GET['id']??0));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ensure_csrf();
    $action=(string)($_POST['action']??'');
    try {
        switch ($action) {
            case 'signup':
                $role=(string)($_POST['role']??'');
                $name=trim((string)($_POST['full_name']??''));
                $email=strtolower(trim((string)($_POST['email']??'')));
                $org=trim((string)($_POST['organization']??''));
                $job=trim((string)($_POST['job_title']??''));
                $phone=trim((string)($_POST['phone']??''));
                $pass=(string)($_POST['password']??'');
                if (!in_array($role,['manager','client'],true)) throw new RuntimeException('Choose a valid account type.');
                if(strlen($name)<2 || strlen($name)>140 || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>190 || strlen($org)<2 || strlen($org)>190) throw new RuntimeException('Enter a valid name, email address and organization.');
                if ($role==='manager' && $job==='') throw new RuntimeException('Enter your job title.');
                if ($role==='client' && $phone==='') throw new RuntimeException('Enter your contact phone number.');
                if(strlen($job)>120 || strlen($phone)>40) throw new RuntimeException('One of the fields is too long.');
                if (strlen($pass)<8 || strlen($pass)>128) throw new RuntimeException('Your password must contain between 8 and 128 characters.');
                if ($pass !== ($_POST['confirm_password']??'')) throw new RuntimeException('The passwords do not match.');
                if (one('SELECT id FROM users WHERE email=?',[$email])) throw new RuntimeException('That email address already has an account. Please log in.');
                query('INSERT INTO users (full_name,email,organization,job_title,phone,password_hash,role) VALUES (?,?,?,?,?,?,?)',[$name,$email,$org,$job?:null,$phone?:null,password_hash($pass,PASSWORD_DEFAULT),$role]);
                $new=(int)db()->lastInsertId();
                session_regenerate_id(true);
                $_SESSION['user']=['id'=>$new,'full_name'=>$name,'email'=>$email,'organization'=>$org,'role'=>$role];
                flash('Welcome to ProjectLens! Your '.$role.' account has been created.');
                go('dashboard');
            case 'login':
                $email=strtolower(trim((string)($_POST['email']??'')));
                $pass=(string)($_POST['password']??'');
                $row=one('SELECT * FROM users WHERE email=?',[$email]);
                if (!$row || !password_verify($pass,$row['password_hash'])) throw new RuntimeException('Incorrect email or password.');
                session_regenerate_id(true);
                $_SESSION['user']=['id'=>(int)$row['id'],'full_name'=>$row['full_name'],'email'=>$row['email'],'organization'=>$row['organization'],'role'=>$row['role']];
                $_SESSION['message_login_notice']=true;
                flash('You are now signed in.');go('dashboard');
            case 'logout':
                $_SESSION=[]; session_regenerate_id(true); flash('You have signed out.');go('register');
            case 'project_save':
                auth('manager');
                $pid=(int)($_POST['project_id']??0);
                $title=trim((string)($_POST['title']??''));
                $desc=trim((string)($_POST['description']??''));
                $location=trim((string)($_POST['location']??''));
                $category=trim((string)($_POST['category']??''));
                $status=(string)($_POST['status']??'on_track');
                $start=(string)($_POST['start_date']??''); $end=(string)($_POST['end_date']??'');
                if (!$title || !$desc || !$location || !$category || strlen($title)>190 || strlen($location)>190 || strlen($category)>90) throw new RuntimeException('Complete all project fields with valid lengths.');
                if (!in_array($status,['on_track','delayed','completed','on_hold'],true)) throw new RuntimeException('Invalid project status.');
                foreach([$start,$end] as $date) if ($date!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) throw new RuntimeException('Please use valid project dates.');
                if ($start && $end && $end<$start) throw new RuntimeException('The end date must not be earlier than the start date.');
                if($pid) {
                    need_project($pid);
                    query('UPDATE projects SET title=?,description=?,location=?,category=?,status=?,start_date=?,end_date=? WHERE id=? AND manager_id=?',[$title,$desc,$location,$category,$status,$start?:null,$end?:null,$pid,uid()]);
                    audit('project_updated','Project details updated',$pid);flash('Project updated successfully.');
                } else {
                    $created=false;
                    for ($attempt=0;$attempt<12;$attempt++) {
                        $code=project_code();
                        try {
                            query('INSERT INTO projects (manager_id,project_code,title,description,location,category,status,start_date,end_date) VALUES (?,?,?,?,?,?,?,?,?)',[uid(),$code,$title,$desc,$location,$category,$status,$start?:null,$end?:null]);
                            $created=true;break;
                        } catch (PDOException $ex) {
                            if ($ex->getCode()!=='23000') throw $ex;
                        }
                    }
                    if (!$created) throw new RuntimeException('Could not create a unique project code. Try again.');
                    $pid=(int)db()->lastInsertId(); audit('project_created','Project created with code '.$code,$pid);flash('Project created. Share project code '.$code.' with your client so they can request access.');
                }
                go('project',['id'=>$pid]);
            case 'activity_save':
                auth('manager');
                $pid=(int)($_POST['project_id']??0);need_project($pid);
                $aid=(int)($_POST['activity_id']??0);
                $title=trim((string)($_POST['title']??''));$target=trim((string)($_POST['target_description']??''));
                $raw=(string)($_POST['progress_percent']??'');
                if (!$title || !$target || strlen($title)>190 || strlen($target)>255 || !ctype_digit($raw) || (int)$raw>100) throw new RuntimeException('Enter an activity title, a target and progress from 0 to 100.');
                if($aid) {
                    $a=one('SELECT id FROM activities WHERE id=? AND project_id=?',[$aid,$pid]); if(!$a) throw new RuntimeException('Activity not found.');
                    query('UPDATE activities SET title=?,target_description=?,progress_percent=? WHERE id=?',[$title,$target,(int)$raw,$aid]);
                    audit('activity_updated',$title.' set to '.$raw.'%',$pid);
                } else {
                    query('INSERT INTO activities (project_id,title,target_description,progress_percent) VALUES (?,?,?,?)',[$pid,$title,$target,(int)$raw]);
                    audit('activity_created','Activity: '.$title,$pid);
                }
                flash('Activity saved.');go('project',['id'=>$pid]);
            case 'client_assign':
                auth('manager');
                $pid=(int)($_POST['project_id']??0); need_project($pid);
                $cid=(int)($_POST['client_id']??0);
                $client=one("SELECT id FROM users WHERE id=? AND role='client'",[$cid]);
                if(!$client) throw new RuntimeException('Choose an existing ProjectLens client account.');
                query('INSERT IGNORE INTO project_clients (project_id,client_id) VALUES (?,?)',[$pid,$cid]);
                query("UPDATE project_access_requests SET status='approved',reviewed_at=NOW() WHERE project_id=? AND client_id=? AND status='pending'",[$pid,$cid]);
                audit('client_assigned','Client account #'.$cid.' linked',$pid);flash('Client assigned to the project.');go('project',['id'=>$pid]);
            case 'client_remove':
                auth('manager');
                $pid=(int)($_POST['project_id']??0);need_project($pid);
                $cid=(int)($_POST['client_id']??0);
                query('DELETE FROM project_clients WHERE project_id=? AND client_id=?',[$pid,$cid]);
                query("UPDATE project_access_requests SET status='declined',reviewed_at=NOW() WHERE project_id=? AND client_id=?",[$pid,$cid]);
                audit('client_removed','Client account #'.$cid.' unlinked',$pid);flash('Client removed.');go('project',['id'=>$pid]);
            case 'request_project_access':
                auth('client');
                $code=strtoupper(trim((string)($_POST['project_code']??'')));
                if (!preg_match('/^[A-Z2-9]{6}$/',$code)) throw new RuntimeException('Enter a valid six-character project code.');
                $target=one('SELECT id,title FROM projects WHERE project_code=?',[$code]);
                if (!$target) throw new RuntimeException('No project matches that code. Please check with the manager.');
                $pid=(int)$target['id'];
                if (one('SELECT project_id FROM project_clients WHERE project_id=? AND client_id=?',[$pid,uid()])) {
                    flash('You already have access to this project.');go('project',['id'=>$pid]);
                }
                $note=trim((string)($_POST['note']??''));
                if(strlen($note)<10 || strlen($note)>1000) throw new RuntimeException('Please write a brief request (10–1000 characters).');
                query("INSERT INTO project_access_requests (project_id,client_id,note,status,reviewed_at) VALUES (?,?,?,'pending',NULL) ON DUPLICATE KEY UPDATE note=VALUES(note),status='pending',reviewed_at=NULL",[$pid,uid(),$note]);
                audit('access_requested','Client requested project access',$pid);
                flash('Your request was sent to the project manager. The project will appear when they approve it.');go('find-project',['code'=>$code]);
            case 'access_decision':
                auth('manager');
                $rid=(int)($_POST['request_id']??0);
                $decision=(string)($_POST['decision']??'');
                if(!in_array($decision,['approved','declined'],true)) throw new RuntimeException('Invalid request decision.');
                $request=one('SELECT r.* FROM project_access_requests r JOIN projects p ON p.id=r.project_id WHERE r.id=? AND p.manager_id=?',[$rid,uid()]);
                if(!$request || $request['status']!=='pending') throw new RuntimeException('Request not found or already reviewed.');
                db()->beginTransaction();
                try {
                    $stmt=db()->prepare("UPDATE project_access_requests SET status=?,reviewed_at=NOW() WHERE id=? AND status='pending'");
                    $stmt->execute([$decision,$rid]);
                    if ($decision==='approved') {
                        $stmt=db()->prepare('INSERT IGNORE INTO project_clients (project_id,client_id) VALUES (?,?)');
                        $stmt->execute([$request['project_id'],$request['client_id']]);
                    }
                    db()->commit();
                } catch(Throwable $tx) { db()->rollBack();throw $tx; }
                audit('access_'.$decision,'Request #'.$rid.' reviewed',(int)$request['project_id']);
                flash($decision==='approved'?'Client access approved. They can now view your project and message you.':'Access request declined.');
                go('access-requests');
            case 'project_message_send':
                auth();
                $pid=(int)($_POST['project_id']??0);$p=need_project($pid);
                $cid=me()['role']==='client'?uid():(int)($_POST['client_id']??0);
                if(!one('SELECT 1 FROM project_clients WHERE project_id=? AND client_id=?',[$pid,$cid])) throw new RuntimeException('This client is not assigned to the project.');
                $body=trim((string)($_POST['body']??''));
                if(strlen($body)<2 || strlen($body)>3000) throw new RuntimeException('Write a message between 2 and 3000 characters.');
                query('INSERT INTO project_messages (project_id,client_id,sender_id,body) VALUES (?,?,?,?)',[$pid,$cid,uid(),$body]);
                flash('Your message was saved in the project conversation.');
                go('conversation',['id'=>$pid,'client'=>$cid]);
            case 'upload':
                auth('manager');
                require_once __DIR__.'/inc/image_similarity.php';
                $pid=(int)($_POST['project_id']??0);need_project($pid);
                $aid=(int)($_POST['activity_id']??0);
                if (!one('SELECT id FROM activities WHERE id=? AND project_id=?',[$aid,$pid])) throw new RuntimeException('Choose an activity belonging to this project.');
                $date=(string)($_POST['evidence_date']??'');
                if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) throw new RuntimeException('Select an evidence date.');
                $file=$_FILES['photo']??null;
                if(!$file || $file['error']!==UPLOAD_ERR_OK) throw new RuntimeException('Upload failed. Select a photo under 8 MB and try again.');
                if($file['size']>8*1024*1024 || $file['size']<1) throw new RuntimeException('Image files must be smaller than 8 MB.');
                $tmp=$file['tmp_name'];
                if(!is_uploaded_file($tmp)) throw new RuntimeException('Invalid uploaded file.');
                $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
                $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??null;
                $size=@getimagesize($tmp);
                if(!$ext || !$size || $size[0]>8000 || $size[1]>8000 || $size[0]*$size[1]>16000000) throw new RuntimeException('Choose a JPG, PNG or WebP photo of up to 16 megapixels (maximum 8000 pixels per side).');
                $dh=image_dhash($tmp,$mime);$sha=hash_file('sha256',$tmp);
                $matched=null; $best=0;
                foreach(all('SELECT ev.id,ev.sha256,ev.dhash FROM evidence ev JOIN projects p ON p.id=ev.project_id WHERE p.manager_id=?',[uid()]) as $prior) {
                    $score=hash_equals($sha,$prior['sha256']) ? 100.0 : image_similarity($dh,$prior['dhash']);
                    if($score>$best) { $best=$score; $matched=(int)$prior['id']; }
                }
                $status=$best>=95 ? 'pending_review' : 'unique_ready';
                $filename=bin2hex(random_bytes(18)).'.'.$ext;
                $dest=__DIR__.'/storage/evidence/'.$filename;
                if(!move_uploaded_file($tmp,$dest)) throw new RuntimeException('Could not save the image. Check storage/evidence folder permissions.');
                try {
                    query('INSERT INTO evidence (project_id,activity_id,uploader_id,image_file,original_name,mime_type,evidence_date,sha256,dhash,max_similarity,matched_evidence_id,review_status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',[$pid,$aid,uid(),$filename,mbsafe((string)$file['name'],180),$mime,$date,$sha,$dh,$best,$matched,$status]);
                } catch(Throwable $ex) { @unlink($dest); throw $ex; }
                $eid=(int)db()->lastInsertId();
                audit('evidence_uploaded','Image #'.$eid.'; similarity '.$best.'%; '.$status,$pid);
                flash($status==='pending_review'?'Potential duplicate detected ('.number_format($best,1).'% similar). Review before approval.':'No high-similarity match found. Confirm the image in the review queue.',$status==='pending_review'?'error':'good');
                go('queue',['focus'=>$eid]);
            case 'evidence_decision':
                auth('manager');
                $eid=(int)($_POST['evidence_id']??0);$decision=(string)($_POST['decision']??'');
                $ev=one('SELECT ev.* FROM evidence ev JOIN projects p ON p.id=ev.project_id WHERE ev.id=? AND p.manager_id=?',[$eid,uid()]);
                if(!$ev || !in_array($decision,['approved','rejected'],true)) throw new RuntimeException('Evidence or decision is invalid.');
                if(!in_array($ev['review_status'],['pending_review','unique_ready'],true)) throw new RuntimeException('This image has already been reviewed.');
                $note=trim((string)($_POST['review_note']??''));
                if(strlen($note)>500) throw new RuntimeException('Review note must not exceed 500 characters.');
                if($ev['review_status']==='pending_review' && $decision==='approved' && $note==='') throw new RuntimeException('Explain why you are accepting this possible duplicate.');
                query('UPDATE evidence SET review_status=?,review_note=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?',[$decision,$note?:null,uid(),$eid]);
                audit('evidence_'.$decision,'Image #'.$eid.'; '.$note,(int)$ev['project_id']);
                flash($decision==='approved'?'Image approved and linked to its activity.':'Image marked rejected.');go('queue');
            case 'settings_save':
                auth();
                $name=trim((string)($_POST['full_name']??''));
                $org=trim((string)($_POST['organization']??''));
                if(strlen($name)<2 || strlen($name)>140 || strlen($org)<2 || strlen($org)>190) throw new RuntimeException('Enter a valid name and organization.');
                query('UPDATE users SET full_name=?,organization=? WHERE id=?',[$name,$org,uid()]);
                $_SESSION['user']['full_name']=$name;$_SESSION['user']['organization']=$org;
                flash('Profile updated.');go('settings');
            case 'password_save':
                auth();
                $user=one('SELECT password_hash FROM users WHERE id=?',[uid()]);
                $old=(string)($_POST['old_password']??'');$new=(string)($_POST['new_password']??'');
                if(!password_verify($old,$user['password_hash'])) throw new RuntimeException('Current password is incorrect.');
                if(strlen($new)<8 || strlen($new)>128 || $new!==($_POST['confirm_password']??'')) throw new RuntimeException('The new passwords do not match or are shorter than 8 characters.');
                query('UPDATE users SET password_hash=? WHERE id=?',[password_hash($new,PASSWORD_DEFAULT),uid()]);
                flash('Password changed successfully.');go('settings');
            case 'contact':
                $name=trim((string)($_POST['sender_name']??''));$email=trim((string)($_POST['sender_email']??''));
                $subject=trim((string)($_POST['subject']??''));$message=trim((string)($_POST['message']??''));
                if(strlen($name)<2 || strlen($name)>140 || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>190 || strlen($subject)<2 || strlen($subject)>190 || strlen($message)<10 || strlen($message)>5000) throw new RuntimeException('Complete the message form with a valid name, email, subject and message (at least 10 characters).');
                query('INSERT INTO contact_messages (sender_name,sender_email,subject,message) VALUES (?,?,?,?)',[$name,$email,$subject,$message]);
                flash('Your message was saved. A ProjectLens manager can view it in the local workspace.');go('contact');
            default: throw new RuntimeException('Invalid form action.');
        }
    } catch (Throwable $ex) {
        $message=$ex instanceof PDOException ? 'Database request failed. Check that MySQL is running and that installation was completed. Details: '.$ex->getMessage() : $ex->getMessage();
        flash($message,'error');
        $fallback=['signup'=>'register','login'=>'login','project_save'=>'projects','activity_save'=>'project','client_assign'=>'project','client_remove'=>'project','request_project_access'=>'find-project','access_decision'=>'access-requests','project_message_send'=>'conversations','upload'=>'evidence','evidence_decision'=>'queue','settings_save'=>'settings','password_save'=>'settings','contact'=>'contact'][$action]??'register';
        if(in_array($action,['activity_save','client_assign','client_remove'],true)) go($fallback,['id'=>(int)($_POST['project_id']??0)]);
        if($action==='project_message_send') go('conversation',['id'=>(int)($_POST['project_id']??0),'client'=>(int)($_POST['client_id']??uid())]);
        if($action==='request_project_access') go('find-project',['code'=>strtoupper(trim((string)($_POST['project_code']??'')))]);
        if($action==='signup') go('register',['role'=>(string)($_POST['role']??'manager')]);
        go($fallback);
    }
}
function mbsafe(string $s,int $max): string { return substr(basename($s),0,$max); }
require __DIR__.'/inc/views.php';
