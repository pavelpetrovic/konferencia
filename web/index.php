<?php
require __DIR__ . '/includes/bootstrap.php';

$classId = (int) ($_GET['class'] ?? $_POST['class'] ?? 0);
$selectedClass = $classId ? class_by_id($classId) : null;

if ($classId && !$selectedClass) {
    http_response_code(404);
    exit('Trieda neexistuje.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'logout') {
            unset($_SESSION['student_id']);
            session_regenerate_id(true);
            flash('Odhlásenie úspešné.');
            go('/?class=' . $classId);
        }

        if (!$selectedClass) {
            throw new RuntimeException('Najprv vyber triedu.');
        }

        if ($action === 'request') {
            $studentId = (int) ($_POST['student_id'] ?? 0);
            $requestedStudent = q(
                'SELECT * FROM students WHERE id = ? AND class_id = ?',
                [$studentId, $classId]
            )->fetch();

            if (!$requestedStudent) {
                throw new RuntimeException('Neplatný študent.');
            }

            // Serialize requests for the same student and enforce a cooldown.
            db()->beginTransaction();

            $locked = q(
                'SELECT code_requested_at FROM students WHERE id = ? FOR UPDATE',
                [$studentId]
            )->fetch();

            if ($locked['code_requested_at']
                && strtotime($locked['code_requested_at']) > time() - 120) {
                db()->rollBack();
                throw new RuntimeException('Nový kód možno vyžiadať najskôr o dve minúty.');
            }

            $code = student_code($requestedStudent);

            q(
                'UPDATE students SET code_requested_at = NOW() WHERE id = ?',
                [$studentId]
            );
            db()->commit();

            try {
                send_code($requestedStudent['email'], $requestedStudent['name'], $code);
            } catch (Throwable $error) {
                error_log('Conference email failed: ' . $error->getMessage());
                throw new RuntimeException('E-mail sa nepodarilo odoslať. Skús to neskôr.');
            }

            flash('Prístupový kód bol odoslaný na školský e-mail.');
        } elseif ($action === 'login') {
            $studentId = (int) ($_POST['student_id'] ?? 0);
            $requestedStudent = q(
                'SELECT * FROM students WHERE id = ? AND class_id = ?',
                [$studentId, $classId]
            )->fetch();

            $attempts = (int) ($_SESSION['login_attempts'] ?? 0);
            $lastAttempt = (int) ($_SESSION['login_attempt_time'] ?? 0);

            if ($lastAttempt < time() - 900) {
                $attempts = 0;
            }

            if ($attempts >= 10) {
                throw new RuntimeException('Priveľa pokusov. Skús to o 15 minút.');
            }

            $_SESSION['login_attempts'] = $attempts + 1;
            $_SESSION['login_attempt_time'] = time();

            $enteredCode = strtoupper(trim((string) ($_POST['code'] ?? '')));

            if (!$requestedStudent || !hash_equals(student_code($requestedStudent), $enteredCode)) {
                throw new RuntimeException('Nesprávne meno alebo prístupový kód.');
            }

            session_regenerate_id(true);
            $_SESSION['login_attempts'] = 0;
            $_SESSION['student_id'] = (int) $requestedStudent['id'];

            flash('Prihlásenie úspešné.');
        } elseif ($action === 'selection') {
            $me = student();

            if (!$me || (int) $me['class_id'] !== $classId) {
                throw new RuntimeException('Najprv sa prihlás.');
            }

            // Topic and slot are submitted together by one form.
            $topicId = (int) ($_POST['topic_id'] ?? 0);
            $slotChoice = (string) ($_POST['slot_choice'] ?? '');

            if ($topicId && !q(
                'SELECT id FROM topics WHERE id = ? AND class_id = ?',
                [$topicId, $classId]
            )->fetch()) {
                throw new RuntimeException('Neplatná téma.');
            }

            if (!preg_match('/^(0:0|[1-9][0-9]*:[1-8])$/D', $slotChoice)) {
                throw new RuntimeException('Neplatný termín.');
            }

            [$lessonId, $slotNumber] = array_map('intval', explode(':', $slotChoice));

            if ($lessonId && !q(
                'SELECT id FROM lessons WHERE id = ? AND class_id = ? AND starts_at > NOW()',
                [$lessonId, $classId]
            )->fetch()) {
                throw new RuntimeException('Tento termín nie je dostupný.');
            }

            db()->beginTransaction();

            // Lock this student so simultaneous changes cannot overwrite each other.
            q('SELECT id FROM students WHERE id = ? FOR UPDATE', [$me['id']]);

            q(
                'INSERT IGNORE INTO selections (student_id, class_id) VALUES (?, ?)',
                [$me['id'], $classId]
            );

            // Save both selections atomically. Database uniqueness constraints
            // prevent another student from claiming the same topic or slot.
            q(
                'UPDATE selections
                 SET topic_id = ?, lesson_id = ?, slot = ?
                 WHERE student_id = ?',
                [$topicId ?: null, $lessonId ?: null, $slotNumber ?: null, $me['id']]
            );

            db()->commit();
            flash('Výber bol uložený.');
        } else {
            throw new RuntimeException('Neznáma operácia.');
        }
    } catch (PDOException $error) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }

        if ($error->getCode() === '23000') {
            flash('Táto téma alebo termín už boli obsadené. Vyber inú možnosť.');
        } else {
            error_log('Conference DB error: ' . $error->getMessage());
            flash('Databázová chyba.');
        }
    } catch (Throwable $error) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }

        flash($error->getMessage());
    }

    go('/?class=' . $classId);
}

$me = student();

if ($selectedClass && $me && (int) $me['class_id'] !== $classId) {
    $me = null;
}

$students = $selectedClass
    ? q('SELECT id, name FROM students WHERE class_id = ? ORDER BY name', [$classId])->fetchAll()
    : [];

$topics = $selectedClass
    ? q(
        'SELECT t.*, s.student_id
         FROM topics t
         LEFT JOIN selections s ON s.topic_id = t.id
         WHERE t.class_id = ?
         ORDER BY t.number',
        [$classId]
    )->fetchAll()
    : [];

$lessons = $selectedClass
    ? q('SELECT * FROM lessons WHERE class_id = ? ORDER BY starts_at, id', [$classId])->fetchAll()
    : [];

$assignments = $selectedClass
    ? q(
        'SELECT s.student_id, s.topic_id, s.lesson_id, s.slot,
                st.name AS student_name, t.title AS topic_title
         FROM selections s
         JOIN students st ON st.id = s.student_id
         LEFT JOIN topics t ON t.id = s.topic_id
         WHERE s.class_id = ?',
        [$classId]
    )->fetchAll()
    : [];

$mine = null;
$takenTopics = [];
$takenSlots = [];
$bySlot = [];

foreach ($assignments as $assignment) {
    if ($me && (int) $assignment['student_id'] === (int) $me['id']) {
        $mine = $assignment;
    }

    if ($assignment['topic_id']) {
        $takenTopics[(int) $assignment['topic_id']] = (int) $assignment['student_id'];
    }

    if ($assignment['lesson_id'] && $assignment['slot']) {
        $key = $assignment['lesson_id'] . ':' . $assignment['slot'];
        $takenSlots[$key] = (int) $assignment['student_id'];
        $bySlot[$key] = $assignment;
    }
}

function time_label(string $start, int $slot): string
{
    $time = new DateTimeImmutable($start);
    $minutes = ($slot - 1) * 5;

    return $time->modify('+' . $minutes . ' minutes')->format('H:i');
}
?>
<!doctype html><html lang="sk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Minikonferencia z fyziky</title><link rel="stylesheet" href="/assets/style.css"></head><body>
<header><div class="inner"><h1>Minikonferencia z fyziky</h1><div>Výber tém a prezentačných termínov</div></div></header>
<main>
<?php if(isset($_SESSION['flash'])): ?><p class="flash"><?=h($_SESSION['flash'])?></p><?php unset($_SESSION['flash']);endif; ?>
<?php if(!$selectedClass): ?>
<h2>Vyber si svoju triedu</h2><div class="classgrid"><?php foreach(classes() as $c): ?><a class="classbutton" href="/?class=<?=(int)$c['id']?>"><?=h($c['name'])?></a><?php endforeach;?></div>
<?php else: ?>
<div class="topline"><h2><?=h($selectedClass['name'])?></h2><a href="/">← Všetky triedy</a></div>
<?php if($me): ?>
<section class="panel"><div class="topline"><div>Prihlásený/á: <strong><?=h($me['name'])?></strong></div><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="class" value="<?=$classId?>"><button class="secondary" name="action" value="logout">Odhlásiť sa</button></form></div>
<form method="post">
  <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
  <input type="hidden" name="class" value="<?= $classId ?>">

  <div class="columns">
    <div>
      <h3>Moja téma</h3>
      <label for="topic">Vyber alebo zmeň tému</label>
      <select name="topic_id" id="topic">
        <option value="0">— Bez témy —</option>
        <?php foreach ($topics as $t):
          $taken = isset($takenTopics[$t['id']]) && $takenTopics[$t['id']] !== (int) $me['id'];
        ?>
          <option value="<?= $t['id'] ?>" <?= $taken ? 'disabled' : '' ?> <?= ($mine['topic_id'] ?? null) == $t['id'] ? 'selected' : '' ?>>
            <?= h($t['number'] . '. ' . $t['title'] . ($taken ? ' (obsadené)' : '')) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <h3>Môj termín</h3>
      <label for="slot">Vyber alebo zmeň termín</label>
      <select name="slot_choice" id="slot">
        <option value="0:0">— Bez termínu —</option>
        <?php foreach ($lessons as $l):
          if (strtotime($l['starts_at']) <= time()) {
            continue;
          }

          for ($n = 1; $n <= 8; $n++):
            $key = $l['id'] . ':' . $n;
            $taken = isset($takenSlots[$key]) && $takenSlots[$key] !== (int) $me['id'];
        ?>
            <option value="<?= $key ?>" <?= $taken ? 'disabled' : '' ?> <?= ($mine['lesson_id'] ?? null) == $l['id'] && ($mine['slot'] ?? null) == $n ? 'selected' : '' ?>>
              <?= h(date('d.m.Y', strtotime($l['starts_at'])) . ' ' . time_label($l['starts_at'], $n) . ($taken ? ' (obsadené)' : '')) ?>
            </option>
          <?php endfor; ?>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <button name="action" value="selection">Uložiť výber</button>
</form>
</section>
<?php else: ?>
<div class="columns"><section class="panel"><h3>Poslať nový prístupový kód</h3><p class="muted small">Kód príde na školský e-mail. Pri opakovanom vyžiadaní zostáva rovnaký.</p><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="class" value="<?=$classId?>"><label for="request_student">Meno</label><select id="request_student" name="student_id" required><option value="">Vyber svoje meno</option><?php foreach($students as $s):?><option value="<?=$s['id']?>"><?=h($s['name'])?></option><?php endforeach;?></select><button name="action" value="request">Poslať kód e-mailom</button></form></section>
<section class="panel"><h3>Prihlásenie a zmena výberu</h3><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="class" value="<?=$classId?>"><label for="login_student">Meno</label><select id="login_student" name="student_id" required><option value="">Vyber svoje meno</option><?php foreach($students as $s):?><option value="<?=$s['id']?>"><?=h($s['name'])?></option><?php endforeach;?></select><label for="code">Prístupový kód z e-mailu</label><input id="code" name="code" autocomplete="off" required><button name="action" value="login">Prihlásiť sa</button></form></section></div>
<?php endif;?>
<section class="panel"><h2>Harmonogram prezentácií</h2>
<?php if(!$lessons):?><p class="muted">Termíny zatiaľ neboli vypísané.</p><?php endif;?>
<?php foreach($lessons as $l):?><div class="lesson"><h3><?=h(date('d.m.Y, H:i',strtotime($l['starts_at'])))?> <?=h($l['note'])?></h3><table><thead><tr><th>Čas</th><th>Študent</th><th>Téma</th></tr></thead><tbody>
<?php for($n=1;$n<=8;$n++):$a=$bySlot[$l['id'].':'.$n]??null;?><tr class="<?=$a?'taken':'free'?>"><td><?=h(time_label($l['starts_at'],$n))?></td><td><?=h($a['student_name']??'— voľné —')?></td><td><?=h($a['topic_title']??'')?></td></tr><?php endfor;?>
</tbody></table></div><?php endforeach;?></section>
<section class="panel"><h2>Neobsadené témy</h2><?php $available=array_filter($topics,fn($t)=>!isset($takenTopics[$t['id']]));if(!$available):?><p class="muted">Žiadne voľné témy.</p><?php else:?><ol class="topiclist"><?php foreach($available as $t):?><li value="<?=$t['number']?>"><?=h($t['title'])?></li><?php endforeach;?></ol><?php endif;?></section>
<?php endif;?></main></body></html>
