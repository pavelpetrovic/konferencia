<?php
require __DIR__.'/includes/bootstrap.php';
$cid=(int)($_GET['class']??$_POST['class']??0);
$cl=$cid?class_by_id($cid):null;
if($cid&&!$cl){http_response_code(404);exit('Trieda neexistuje.');}
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();
 $action=(string)($_POST['action']??'');
 try{
  if($action==='logout'){
   unset($_SESSION['student_id']);session_regenerate_id(true);flash('Odhlásenie úspešné.');go('/?class='.$cid);
  }
  if(!$cl)throw new RuntimeException('Najprv vyber triedu.');
  if($action==='request'){
   $id=(int)($_POST['student_id']??0);
   $s=q('SELECT * FROM students WHERE id=? AND class_id=?',[$id,$cid])->fetch();
   if(!$s)throw new RuntimeException('Neplatný študent.');
   // Per-student cooldown. Lock prevents simultaneous code requests.
   db()->beginTransaction();
   $locked=q('SELECT code_requested_at FROM students WHERE id=? FOR UPDATE',[$id])->fetch();
   if($locked['code_requested_at'] && strtotime($locked['code_requested_at'])>time()-120){
    db()->rollBack();throw new RuntimeException('Nový kód možno vyžiadať najskôr o dve minúty.');
   }
   // Deterministic secret-derived code: resending does not invalidate an older email.
   $code=student_code($s);
   q('UPDATE students SET code_requested_at=NOW() WHERE id=?',[$id]);
   db()->commit();
   try{send_code($s['email'],$s['name'],$code);}
   catch(Throwable $e){error_log('Conference email failed: '.$e->getMessage());throw new RuntimeException('E-mail sa nepodarilo odoslať. Skús to neskôr.');}
   flash('Prístupový kód bol odoslaný na školský e-mail.');
  }elseif($action==='login'){
   $id=(int)($_POST['student_id']??0);
   $s=q('SELECT * FROM students WHERE id=? AND class_id=?',[$id,$cid])->fetch();
   $attempts=(int)($_SESSION['login_attempts']??0);
   $last=(int)($_SESSION['login_attempt_time']??0);
   if($last<time()-900)$attempts=0;
   if($attempts>=10)throw new RuntimeException('Priveľa pokusov. Skús to o 15 minút.');
   $_SESSION['login_attempts']=$attempts+1;$_SESSION['login_attempt_time']=time();
   if(!$s || !hash_equals(student_code($s),strtoupper(trim((string)($_POST['code']??'')))))
    throw new RuntimeException('Nesprávne meno alebo prístupový kód.');
   session_regenerate_id(true);$_SESSION['login_attempts']=0;$_SESSION['student_id']=(int)$s['id'];flash('Prihlásenie úspešné.');
  }elseif(in_array($action,['topic','slot'],true)){
   $me=student();
   if(!$me || (int)$me['class_id']!==$cid)throw new RuntimeException('Najprv sa prihlás.');
   db()->beginTransaction();
   // Lock student's row so simultaneous updates cannot overwrite each other.
   q('SELECT id FROM students WHERE id=? FOR UPDATE',[$me['id']]);
   q('INSERT IGNORE INTO selections(student_id,class_id) VALUES(?,?)',[$me['id'],$cid]);
   if($action==='topic'){
    $topic=(int)($_POST['topic_id']??0);
    if($topic && !q('SELECT id FROM topics WHERE id=? AND class_id=?',[$topic,$cid])->fetch())throw new RuntimeException('Neplatná téma.');
    q('UPDATE selections SET topic_id=? WHERE student_id=?',[$topic?:null,$me['id']]);
    flash('Výber témy bol uložený.');
   }else{
    $choice=(string)($_POST['slot_choice']??'');
    if(!preg_match('/^(0:0|[1-9][0-9]*:[1-8])$/D',$choice))throw new RuntimeException('Neplatný termín.');
    [$lesson,$slot]=array_map('intval',explode(':',$choice));
    if(($lesson===0)!==($slot===0) || $slot<0 || $slot>8)throw new RuntimeException('Neplatný termín.');
    if($lesson && !q('SELECT id FROM lessons WHERE id=? AND class_id=? AND starts_at>NOW()',[$lesson,$cid])->fetch())
     throw new RuntimeException('Tento termín nie je dostupný.');
    q('UPDATE selections SET lesson_id=?,slot=? WHERE student_id=?',[$lesson?:null,$slot?:null,$me['id']]);
    flash('Termín bol uložený.');
   }
   db()->commit();
  }else throw new RuntimeException('Neznáma operácia.');
 }catch(PDOException $e){
  if(db()->inTransaction())db()->rollBack();
  if($e->getCode()==='23000')flash('Táto téma alebo termín už boli obsadené. Vyber inú možnosť.');
  else{error_log('Conference DB error: '.$e->getMessage());flash('Databázová chyba.');}
 }catch(Throwable $e){
  if(db()->inTransaction())db()->rollBack();flash($e->getMessage());
 }
 go('/?class='.$cid);
}
$me=student();
if($cl && $me && (int)$me['class_id']!==$cid)$me=null;
$students=$cl?q('SELECT id,name FROM students WHERE class_id=? ORDER BY name',[$cid])->fetchAll():[];
$topics=$cl?q('SELECT t.*,s.student_id FROM topics t LEFT JOIN selections s ON s.topic_id=t.id WHERE t.class_id=? ORDER BY t.number',[$cid])->fetchAll():[];
$lessons=$cl?q('SELECT * FROM lessons WHERE class_id=? ORDER BY starts_at,id',[$cid])->fetchAll():[];
$assignments=$cl?q('SELECT s.student_id,s.topic_id,s.lesson_id,s.slot,st.name AS student_name,t.title AS topic_title FROM selections s JOIN students st ON st.id=s.student_id LEFT JOIN topics t ON t.id=s.topic_id WHERE s.class_id=?',[$cid])->fetchAll():[];
$mine=null;$takenTopics=[];$takenSlots=[];$bySlot=[];
foreach($assignments as $a){
 if($me && (int)$a['student_id']===(int)$me['id'])$mine=$a;
 if($a['topic_id'])$takenTopics[(int)$a['topic_id']]=(int)$a['student_id'];
 if($a['lesson_id']&&$a['slot']){$key=$a['lesson_id'].':'.$a['slot'];$takenSlots[$key]=(int)$a['student_id'];$bySlot[$key]=$a;}
}
function time_label(string $start,int $slot): string {
 $t=new DateTimeImmutable($start);return $t->modify('+'.(($slot-1)*5).' minutes')->format('H:i');
}
?>
<!doctype html><html lang="sk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Minikonferencia z fyziky</title><link rel="stylesheet" href="/assets/style.css"></head><body>
<header><div class="inner"><h1>Minikonferencia z fyziky</h1><div>Výber tém a prezentačných termínov</div></div></header>
<main>
<?php if(isset($_SESSION['flash'])): ?><p class="flash"><?=h($_SESSION['flash'])?></p><?php unset($_SESSION['flash']);endif; ?>
<?php if(!$cl): ?>
<h2>Vyber si svoju triedu</h2><div class="classgrid"><?php foreach(classes() as $c): ?><a class="classbutton" href="/?class=<?=(int)$c['id']?>"><?=h($c['name'])?></a><?php endforeach;?></div>
<?php else: ?>
<div class="topline"><h2><?=h($cl['name'])?></h2><a href="/">← Všetky triedy</a></div>
<?php if($me): ?>
<section class="panel"><div class="topline"><div>Prihlásený/á: <strong><?=h($me['name'])?></strong></div><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="class" value="<?=$cid?>"><button class="secondary" name="action" value="logout">Odhlásiť sa</button></form></div>
<div class="columns">
<form method="post"><h3>Moja téma</h3><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="class" value="<?=$cid?>">
<label for="topic">Vyber alebo zmeň tému</label><select name="topic_id" id="topic"><option value="0">— Bez témy —</option>
<?php foreach($topics as $t):$taken=isset($takenTopics[$t['id']])&&$takenTopics[$t['id']]!==(int)$me['id']; ?>
<option value="<?=$t['id']?>" <?=$taken?'disabled':''?> <?=($mine['topic_id']??null)==$t['id']?'selected':''?>><?=h($t['number'].'. '.$t['title'].($taken?' (obsadené)':''))?></option>
<?php endforeach;?></select><button name="action" value="topic">Uložiť tému</button></form>
<form method="post"><h3>Môj termín</h3><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="class" value="<?=$cid?>">
<label for="slot">Vyber alebo zmeň termín</label><select name="slot_choice" id="slot"><option value="0:0">— Bez termínu —</option>
<?php foreach($lessons as $l):if(strtotime($l['starts_at'])<=time())continue;for($n=1;$n<=8;$n++):$key=$l['id'].':'.$n;$taken=isset($takenSlots[$key])&&$takenSlots[$key]!==(int)$me['id']; ?>
<option value="<?=$key?>" <?=$taken?'disabled':''?> <?=($mine['lesson_id']??null)==$l['id']&&($mine['slot']??null)==$n?'selected':''?>><?=h(date('d.m.Y',strtotime($l['starts_at'])).' '.time_label($l['starts_at'],$n).($taken?' (obsadené)':''))?></option>
<?php endfor;endforeach;?></select>
<input type="hidden" name="lesson_id" value="<?=h($mine['lesson_id']??0)?>"><input type="hidden" name="slot" value="<?=h($mine['slot']??0)?>"><button name="action" value="slot">Uložiť termín</button></form>
</div></section>
<?php else: ?>
<div class="columns"><section class="panel"><h3>Poslať nový prístupový kód</h3><p class="muted small">Kód príde na školský e-mail. Pri opakovanom vyžiadaní zostáva rovnaký.</p><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="class" value="<?=$cid?>"><label for="request_student">Meno</label><select id="request_student" name="student_id" required><option value="">Vyber svoje meno</option><?php foreach($students as $s):?><option value="<?=$s['id']?>"><?=h($s['name'])?></option><?php endforeach;?></select><button name="action" value="request">Poslať kód e-mailom</button></form></section>
<section class="panel"><h3>Prihlásenie a zmena výberu</h3><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="class" value="<?=$cid?>"><label for="login_student">Meno</label><select id="login_student" name="student_id" required><option value="">Vyber svoje meno</option><?php foreach($students as $s):?><option value="<?=$s['id']?>"><?=h($s['name'])?></option><?php endforeach;?></select><label for="code">Prístupový kód z e-mailu</label><input id="code" name="code" autocomplete="off" required><button name="action" value="login">Prihlásiť sa</button></form></section></div>
<?php endif;?>
<section class="panel"><h2>Harmonogram prezentácií</h2>
<?php if(!$lessons):?><p class="muted">Termíny zatiaľ neboli vypísané.</p><?php endif;?>
<?php foreach($lessons as $l):?><div class="lesson"><h3><?=h(date('d.m.Y, H:i',strtotime($l['starts_at'])))?> <?=h($l['note'])?></h3><table><thead><tr><th>Čas</th><th>Študent</th><th>Téma</th></tr></thead><tbody>
<?php for($n=1;$n<=8;$n++):$a=$bySlot[$l['id'].':'.$n]??null;?><tr class="<?=$a?'taken':'free'?>"><td><?=h(time_label($l['starts_at'],$n))?></td><td><?=h($a['student_name']??'— voľné —')?></td><td><?=h($a['topic_title']??'')?></td></tr><?php endfor;?>
</tbody></table></div><?php endforeach;?></section>
<section class="panel"><h2>Neobsadené témy</h2><?php $available=array_filter($topics,fn($t)=>!isset($takenTopics[$t['id']]));if(!$available):?><p class="muted">Žiadne voľné témy.</p><?php else:?><ol class="topiclist"><?php foreach($available as $t):?><li value="<?=$t['number']?>"><?=h($t['title'])?></li><?php endforeach;?></ol><?php endif;?></section>
<?php endif;?></main></body></html>
