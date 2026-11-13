<?php
declare(strict_types=1);

function pl_has_column(PDO $pdo, string $table, string $column): bool {
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
    $stmt->execute([$table,$column]);
    return (int)$stmt->fetchColumn()>0;
}
function pl_add_column(PDO $pdo, string $table, string $column, string $definition): void {
    if (!pl_has_column($pdo,$table,$column)) $pdo->exec('ALTER TABLE `'.$table.'` ADD COLUMN `'.$column.'` '.$definition);
}
function pl_setup_database(array $cfg): int {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $cfg['database'])) throw new RuntimeException('Invalid database name.');
    $pdo=new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4',$cfg['host'],$cfg['port']),$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.$cfg['database'].'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `'.$cfg['database'].'`');
    $schema=file_get_contents(dirname(__DIR__).'/schema.sql');
    foreach(explode(';',$schema) as $statement) if(trim($statement)!=='') $pdo->exec(trim($statement));
    $pdo->exec("ALTER TABLE users MODIFY role ENUM('super_admin','manager','engineer','client') NOT NULL");
    pl_add_column($pdo,'users','ngo_id','INT UNSIGNED NULL AFTER role');
    pl_add_column($pdo,'users','is_active','TINYINT(1) NOT NULL DEFAULT 1 AFTER ngo_id');
    pl_add_column($pdo,'users','deleted_at','DATETIME NULL AFTER is_active');
    pl_add_column($pdo,'projects','project_code','CHAR(6) NULL AFTER manager_id');
    pl_add_column($pdo,'projects','ngo_id','INT UNSIGNED NULL AFTER manager_id');
    pl_add_column($pdo,'project_finances','approval_status',"ENUM('submitted','approved','rejected') NOT NULL DEFAULT 'approved' AFTER entry_type");
    pl_add_column($pdo,'project_finances','reviewed_by','INT UNSIGNED NULL AFTER approval_status');
    pl_add_column($pdo,'project_finances','reviewed_at','DATETIME NULL AFTER reviewed_by');
    pl_add_column($pdo,'project_finances','manager_note','VARCHAR(1000) NULL AFTER reviewed_at');
    pl_add_column($pdo,'projects','budget_total','DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER status');
    pl_add_column($pdo,'activities','weight_percent','DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER progress_percent');
    $newWorkflow=!pl_has_column($pdo,'activities','approval_status');
    pl_add_column($pdo,'activities','duration_days','INT UNSIGNED NOT NULL DEFAULT 1 AFTER weight_percent');
    pl_add_column($pdo,'activities','start_date','DATE NULL AFTER duration_days');
    pl_add_column($pdo,'activities','end_date','DATE NULL AFTER start_date');
    pl_add_column($pdo,'activities','approval_status',"ENUM('pending','submitted','approved','rejected') NOT NULL DEFAULT 'pending' AFTER end_date");
    pl_add_column($pdo,'activities','submitted_by','INT UNSIGNED NULL AFTER approval_status');
    pl_add_column($pdo,'activities','submitted_at','DATETIME NULL AFTER submitted_by');
    pl_add_column($pdo,'activities','confirmed_by','INT UNSIGNED NULL AFTER submitted_at');
    pl_add_column($pdo,'activities','confirmed_at','DATETIME NULL AFTER confirmed_by');
    pl_add_column($pdo,'activities','engineer_note','VARCHAR(1000) NULL AFTER confirmed_at');
    pl_add_column($pdo,'activities','manager_note','VARCHAR(1000) NULL AFTER engineer_note');
    pl_add_column($pdo,'activities','deleted_at','DATETIME NULL AFTER manager_note');
    pl_add_column($pdo,'activities','deleted_by','INT UNSIGNED NULL AFTER deleted_at');
    pl_add_column($pdo,'activities','delete_reason','VARCHAR(500) NULL AFTER deleted_by');
    $pdo->exec("INSERT INTO ngos (name,description) SELECT 'Legacy NGO','Migrated projects from the previous ProjectLens installation' WHERE NOT EXISTS (SELECT 1 FROM ngos WHERE name='Legacy NGO')");
    $legacy=(int)$pdo->query("SELECT id FROM ngos WHERE name='Legacy NGO' LIMIT 1")->fetchColumn();
    $stmt=$pdo->prepare("UPDATE users SET ngo_id=? WHERE role IN ('manager','engineer') AND ngo_id IS NULL");$stmt->execute([$legacy]);
    $stmt=$pdo->prepare('UPDATE projects p JOIN users u ON u.id=p.manager_id SET p.ngo_id=u.ngo_id WHERE p.ngo_id IS NULL');$stmt->execute();
    $stmt=$pdo->prepare('UPDATE projects SET ngo_id=? WHERE ngo_id IS NULL');$stmt->execute([$legacy]);
    $missing=$pdo->query("SELECT id FROM projects WHERE project_code IS NULL OR project_code='' ORDER BY id")->fetchAll();
    foreach($missing as $row) for($try=0;$try<60;$try++) {
        $code=pl_new_project_code($pdo);
        try { $stmt=$pdo->prepare('UPDATE projects SET project_code=? WHERE id=?');$stmt->execute([$code,$row['id']]); break; }
        catch(PDOException $ex) { if ($ex->getCode()!=='23000' || $try===59) throw $ex; }
    }
    $pdo->exec('ALTER TABLE projects MODIFY project_code CHAR(6) NOT NULL');
    if(!(int)$pdo->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='projects' AND column_name='project_code' AND non_unique=0")->fetchColumn()) $pdo->exec('ALTER TABLE projects ADD UNIQUE KEY uq_project_code (project_code)');
    if($newWorkflow) {
        $projects=$pdo->query('SELECT id FROM projects')->fetchAll();
        foreach($projects as $pr) {
            $get=$pdo->prepare('SELECT id FROM activities WHERE project_id=? ORDER BY id');$get->execute([$pr['id']]);$ids=$get->fetchAll(PDO::FETCH_COLUMN);
            $n=count($ids);if(!$n)continue;
            $base=floor(10000/$n);$remaining=10000;
            foreach($ids as $idx=>$aid){$units=$idx===$n-1?$remaining:$base;$remaining-=$units;$set=$pdo->prepare('UPDATE activities SET weight_percent=?,approval_status=? WHERE id=?');$set->execute([number_format($units/100,2,'.',''),'pending',$aid]);}
        }
    }
    return count($missing);
}
function pl_new_project_code(PDO $pdo): string {
    $chars='ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for($i=0;$i<60;$i++) {
        $code='';for($j=0;$j<6;$j++)$code.=$chars[random_int(0,strlen($chars)-1)];
        $stmt=$pdo->prepare('SELECT id FROM projects WHERE project_code=? LIMIT 1');$stmt->execute([$code]);
        if(!$stmt->fetch())return $code;
    }
    throw new RuntimeException('Could not create an unused project code.');
}
