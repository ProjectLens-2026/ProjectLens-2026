<?php
declare(strict_types=1);
require_once __DIR__.'/inc/core.php';
auth(['super_admin','manager','client']);
if($_SERVER['REQUEST_METHOD']!=='POST') {http_response_code(405);exit('Use report export on your dashboard.');}
ensure_csrf();
$pid=(int)($_POST['project_id']??0);$p=project($pid);
if(!$p){http_response_code(403);exit('You do not have access to this project.');}
$type=(string)($_POST['report_type']??'summary');$format=(string)($_POST['format']??'pdf');$ai=isset($_POST['include_ai']);
if(!in_array($type,['summary','full','audit'],true)||!in_array($format,['pdf','docx','xlsx'],true)){http_response_code(400);exit('Invalid export format or report type.');}
$activities=all('SELECT * FROM activities WHERE project_id=? AND deleted_at IS NULL ORDER BY id',[$pid]);
$evidence=all('SELECT e.*,a.title activity_name FROM evidence e JOIN activities a ON a.id=e.activity_id AND a.deleted_at IS NULL WHERE e.project_id=?'.(role()==='client'?" AND e.review_status='approved'":'').' ORDER BY e.id',[$pid]);
$financial=project_finance($pid);
$transactions=all('SELECT * FROM project_finances WHERE project_id=?'.(role()==='client'?" AND approval_status='approved'":'').' ORDER BY entry_date,id',[$pid]);
$approved=count(array_filter($evidence,fn($e)=>$e['review_status']==='approved'));
$pending=role()==='client'?0:count(array_filter($evidence,fn($e)=>in_array($e['review_status'],['pending_review','unique_ready'],true)));
$rows=[];
function add_row(array &$rows,string $section,string $field,string $value): void {$rows[]=[$section,$field,$value];}
add_row($rows,'Project','Name',$p['title']);
add_row($rows,'Project','Code',$p['project_code']??'Not assigned');
add_row($rows,'Project','Location',$p['location']);
add_row($rows,'Project','Category',$p['category']);
add_row($rows,'Project','Description',$p['description']);
add_row($rows,'Project','Status',status_text($p['status']));
add_row($rows,'Project','Period',($p['start_date']?:'Not set').' to '.($p['end_date']?:'Not set'));
$ngo=one('SELECT name FROM ngos WHERE id=?',[$p['ngo_id']]);
add_row($rows,'Project','NGO',$ngo['name']??'Unassigned');
add_row($rows,'Summary','Manager-confirmed project completion',number_format((float)$p['progress'],2).'%');
add_row($rows,'Finance','Approved budget (NGN)',number_format((float)$financial['budget_total'],2));
add_row($rows,'Finance','Funding received (NGN)',number_format((float)$financial['funding'],2));
add_row($rows,'Finance','Recorded expenditure (NGN)',number_format((float)$financial['expenses'],2));
add_row($rows,'Finance','Remaining budget (NGN)',number_format((float)$financial['remaining'],2));
add_row($rows,'Finance','Cash balance (NGN)',number_format((float)$financial['cash_balance'],2));
add_row($rows,'Finance','Budget utilisation',number_format((float)$financial['utilization'],1).'%');
add_row($rows,'Summary','Activities',strval(count($activities)));
add_row($rows,'Summary','Approved images',strval($approved));
if(role()!=='client')add_row($rows,'Summary','Awaiting review',strval($pending));
if($type==='full' || $type==='audit') {
    if($type==='full') foreach($activities as $a) {
        add_row($rows,'Activity #'.$a['id'],$a['title'],number_format((float)$a['weight_percent'],2).'% of project; status: '.(role()==='client' && $a['approval_status']!=='approved'?'Not confirmed':activity_status($a['approval_status'])).'; duration: '.(int)$a['duration_days'].' days; planned: '.($a['start_date']?:'not set').' – '.($a['end_date']?:'not set').'; target: '.$a['target_description']);
    }
    foreach($transactions as $txn) add_row($rows,'Financial ledger #'.$txn['id'],ucfirst($txn['entry_type']).' — '.$txn['entry_date'],$txn['description'].'; reference: '.($txn['reference']?:'none').'; NGN '.number_format((float)$txn['amount'],2));
    foreach($evidence as $ev) {
        $value='Date: '.$ev['evidence_date'].'; activity: '.$ev['activity_name'].'; review: '.status_text($ev['review_status']);
        if($ai) $value.='; max image similarity: '.number_format((float)$ev['max_similarity'],2).'%; matched submission: '.($ev['matched_evidence_id']?:'none');
        if($ev['review_note']) $value.='; review note: '.$ev['review_note'];
        add_row($rows,'Evidence #'.$ev['id'],$ev['original_name'],$value);
    }
    if(in_array(role(),['manager','super_admin'],true)) {
      $audits=all('SELECT ae.*,u.full_name FROM audit_events ae JOIN users u ON u.id=ae.user_id WHERE ae.project_id=? ORDER BY ae.id DESC LIMIT 150',[$pid]);
      foreach($audits as $a) add_row($rows,'Audit event',$a['action'],$a['created_at'].' · '.$a['full_name'].' · '.$a['details']);
    }
}
add_row($rows,'Disclaimer','Photo analysis','Similarity indicates a visual resemblance only; it does not establish that project work is complete. Final photo acceptance is a manager decision.');
$filename='ProjectLens-Project-'.$pid.'-'.date('Ymd').'.'.$format;
function plain(string $s): string { $s=preg_replace('/[\r\n\t]+/u',' ',$s);return trim($s??''); }
function xmlstr(string $s): string {return htmlspecialchars($s,ENT_XML1|ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function docx_bytes(array $rows,string $title): string {
    if(!class_exists('ZipArchive')) throw new RuntimeException('Enable extension=zip in XAMPP php.ini to export DOCX.');
    $tmp=tempnam(sys_get_temp_dir(),'pl_docx_'); $zip=new ZipArchive();
    if($zip->open($tmp,ZipArchive::OVERWRITE)!==true) throw new RuntimeException('Could not create DOCX file.');
    $zip->addFromString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $zip->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $body='<w:p><w:pPr><w:spacing w:after="240"/></w:pPr><w:r><w:rPr><w:b/><w:sz w:val="36"/></w:rPr><w:t>'.xmlstr($title).'</w:t></w:r></w:p>';
    foreach($rows as $r) {
      $body.='<w:p><w:pPr><w:spacing w:after="100"/></w:pPr><w:r><w:rPr><w:b/><w:color w:val="1A5743"/></w:rPr><w:t xml:space="preserve">'.xmlstr($r[0].' — '.$r[1].': ').'</w:t></w:r><w:r><w:t xml:space="preserve">'.xmlstr($r[2]).'</w:t></w:r></w:p>';
    }
    $xml='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134"/></w:sectPr></w:body></w:document>';
    $zip->addFromString('word/document.xml',$xml);$zip->close();$bytes=file_get_contents($tmp);unlink($tmp);return $bytes;
}
function xlsx_bytes(array $rows,string $title): string {
    if(!class_exists('ZipArchive')) throw new RuntimeException('Enable extension=zip in XAMPP php.ini to export XLSX.');
    $tmp=tempnam(sys_get_temp_dir(),'pl_xlsx_');$zip=new ZipArchive();if($zip->open($tmp,ZipArchive::OVERWRITE)!==true) throw new RuntimeException('Could not create XLSX file.');
    $zip->addFromString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
    $zip->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Project Report" sheetId="1" r:id="rId1"/></sheets></workbook>');
    array_unshift($rows,['Section','Field','Value']);array_unshift($rows,['ProjectLens', 'Performance Report', $title]);
    $sheet='<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols><col min="1" max="1" width="24" customWidth="1"/><col min="2" max="2" width="31" customWidth="1"/><col min="3" max="3" width="90" customWidth="1"/></cols><sheetData>';
    foreach($rows as $i=>$r) {$rn=$i+1;$sheet.='<row r="'.$rn.'">';foreach($r as $j=>$v){$ref='ABC'[$j].$rn;$sheet.='<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.xmlstr(plain($v)).'</t></is></c>';}$sheet.='</row>';}
    $sheet.='</sheetData></worksheet>';$zip->addFromString('xl/worksheets/sheet1.xml',$sheet);$zip->close();$bytes=file_get_contents($tmp);unlink($tmp);return $bytes;
}
function pdf_bytes(array $rows,string $title): string {
    
    $lines=[$title,str_repeat('-',60)];
    foreach($rows as $r){$txt=plain($r[0].' | '.$r[1].': '.$r[2]);$txt=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$txt)?:preg_replace('/[^\x20-\x7e]/','?',$txt);$chunks=explode("\n",wordwrap($txt,92,"\n",true));foreach($chunks as $c)$lines[]=$c;$lines[]='';}
    $chunks=array_chunk($lines,48);$objects=[];$pageIds=[];$nextId=3;$fontId=3+2*count($chunks);
    foreach($chunks as $pageLines){$pageId=$nextId++;$contentId=$nextId++;$pageIds[]=$pageId;$stream="BT\n/F1 10 Tf\n14 TL\n50 785 Td\n";foreach($pageLines as $s){$s=str_replace(['\\','(',')'],['\\\\','\\(','\\)'],(string)$s);$stream.='('.$s.") Tj\nT*\n";}$stream.="ET\n";$objects[$pageId]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] /Resources << /Font << /F1 '.$fontId.' 0 R >> >> /Contents '.$contentId.' 0 R >>';$objects[$contentId]='<< /Length '.strlen($stream).' >>' . "\nstream\n".$stream."endstream";}
    $fontId=$nextId;$objects[$fontId]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $objects[1]='<< /Type /Catalog /Pages 2 0 R >>';$objects[2]='<< /Type /Pages /Kids ['.implode(' ',array_map(fn($i)=>$i.' 0 R',$pageIds)).'] /Count '.count($pageIds).' >>';
    $max=max(array_keys($objects));$pdf="%PDF-1.4\n";$offsets=[0];for($i=1;$i<=$max;$i++){ $offsets[$i]=strlen($pdf);$pdf.=$i." 0 obj\n".$objects[$i]."\nendobj\n"; }
    $xref=strlen($pdf);$pdf.="xref\n0 ".($max+1)."\n0000000000 65535 f \n";
    for($i=1;$i<=$max;$i++)$pdf.=sprintf('%010d 00000 n ', $offsets[$i])."\n";
    $pdf.="trailer\n<< /Size ".($max+1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";return $pdf;
}
try {
  $bytes=match($format){'pdf'=>pdf_bytes($rows,'ProjectLens — '.$p['title']), 'docx'=>docx_bytes($rows,'ProjectLens — '.$p['title']), 'xlsx'=>xlsx_bytes($rows,$p['title'])};
  header('Content-Type: '.['pdf'=>'application/pdf','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'][$format]);
  header('Content-Disposition: attachment; filename="'.$filename.'"');header('X-Content-Type-Options: nosniff');header('Cache-Control: no-store');header('Content-Length: '.strlen($bytes));echo $bytes;
} catch(Throwable $ex) {http_response_code(500);header('Content-Type: text/plain; charset=UTF-8');echo 'Report creation failed: '.$ex->getMessage();}
