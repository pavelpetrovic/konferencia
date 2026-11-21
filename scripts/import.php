<?php
// Usage: php scripts/import.php students|topics CLASS file.csv
if(PHP_SAPI!=='cli')exit('CLI only');
if($argc!==4||!in_array($argv[1],['students','topics'],true)){fwrite(STDERR,"Usage: php scripts/import.php students|topics CLASS file.csv\n");exit(1);}
require dirname(__DIR__).'/web/includes/bootstrap.php';
[$script,$kind,$className,$filename]=$argv;
$cl=q('SELECT id FROM classes WHERE name=?',[$className])->fetch();
if(!$cl){fwrite(STDERR,"Unknown class; create it in the classes table first.\n");exit(1);}
$fh=fopen($filename,'rb');if(!$fh)throw new RuntimeException('Cannot open CSV');
$first=fgets($fh);rewind($fh);$delimiter=substr_count($first,';')>substr_count($first,',')?';':',';
$header=fgetcsv($fh,0,$delimiter,'"','');
if(!$header)throw new RuntimeException('Empty CSV');
$header=array_map(fn($v)=>strtolower(trim(preg_replace('/^\xEF\xBB\xBF/','',$v))),$header);
$required=$kind==='students'?['name','email']:['number','title'];
foreach($required as $key)if(!in_array($key,$header,true))throw new RuntimeException('Missing CSV column: '.$key);
$rows=[];$line=1;
while(($values=fgetcsv($fh,0,$delimiter,'"',''))!==false){
 $line++;if(count($values)===1&&trim($values[0])==='')continue;
 if(count($values)!==count($header))throw new RuntimeException("Column count error on line $line");
 $r=array_combine($header,array_map('trim',$values));
 foreach($required as $key)if($r[$key]==='')throw new RuntimeException("Empty $key on line $line");
 if($kind==='students'&&!filter_var($r['email'],FILTER_VALIDATE_EMAIL))throw new RuntimeException("Bad email on line $line");
 if($kind==='topics'&&(!ctype_digit($r['number'])||(int)$r['number']<1))throw new RuntimeException("Bad topic number on line $line");
 $rows[]=$r;
}
fclose($fh);
db()->beginTransaction();
try{
 foreach($rows as $r){
  if($kind==='students'){
   // Existing students keep their IDs, codes and selections.
   q('INSERT INTO students(class_id,name,email) VALUES(?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name)',
   [$cl['id'],$r['name'],$r['email']]);
  }else{
   // Topic number identifies a topic. Existing assignments follow its ID.
   q('INSERT INTO topics(class_id,number,title) VALUES(?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title)',
   [$cl['id'],(int)$r['number'],$r['title']]);
  }
 }
 db()->commit();
}catch(Throwable $e){db()->rollBack();throw $e;}
echo "Imported ".count($rows)." $kind rows for $className. Existing rows were preserved.\n";
