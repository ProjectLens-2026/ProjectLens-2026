<?php
declare(strict_types=1);

function pl_setup_database(array $cfg): int {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $cfg['database'])) throw new RuntimeException('Invalid database name.');
    $pdo = new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4',$cfg['host'],$cfg['port']),$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.$cfg['database'].'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `'.$cfg['database'].'`');
    // Old projects may not yet have a project_code field. CREATE TABLE IF NOT EXISTS never changes existing tables.
    $schema = file_get_contents(dirname(__DIR__).'/schema.sql');
    foreach (explode(';',$schema) as $statement) {
        if (trim($statement) !== '') $pdo->exec(trim($statement));
    }
    $column = $pdo->query("SHOW COLUMNS FROM projects LIKE 'project_code'")->fetch();
    if (!$column) $pdo->exec('ALTER TABLE projects ADD COLUMN project_code CHAR(6) NULL AFTER manager_id');
    $existing = $pdo->query("SELECT id FROM projects WHERE project_code IS NULL OR project_code='' ORDER BY id")->fetchAll();
    foreach ($existing as $row) {
        for ($tries=0;$tries<40;$tries++) {
            $code = pl_new_project_code($pdo);
            try { $stmt=$pdo->prepare('UPDATE projects SET project_code=? WHERE id=?'); $stmt->execute([$code,$row['id']]); break; }
            catch (PDOException $e) { if ($e->getCode() !== '23000' || $tries===39) throw $e; }
        }
    }
    $pdo->exec('ALTER TABLE projects MODIFY project_code CHAR(6) NOT NULL');
    $index=$pdo->query("SHOW INDEX FROM projects WHERE Key_name='uq_project_code'")->fetch();
    $anyUnique = $pdo->query("SHOW INDEX FROM projects WHERE Column_name='project_code' AND Non_unique=0")->fetch();
    if (!$index && !$anyUnique) $pdo->exec('ALTER TABLE projects ADD UNIQUE KEY uq_project_code (project_code)');
    return count($existing);
}
function pl_new_project_code(PDO $pdo): string {
    $chars='ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($i=0;$i<60;$i++) {
        $code='';for($j=0;$j<6;$j++) $code.=$chars[random_int(0,strlen($chars)-1)];
        $stmt=$pdo->prepare('SELECT id FROM projects WHERE project_code=? LIMIT 1');$stmt->execute([$code]);
        if (!$stmt->fetch()) return $code;
    }
    throw new RuntimeException('Could not create a unique project code.');
}
