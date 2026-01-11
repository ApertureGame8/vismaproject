<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// Enkel CRUD for eksamen (fagkode, enr, dato, karakter)

// Angre siste sletting (enkelt nivå)
if (isset($_GET['undo']) && !empty($_SESSION['undo_exam'])) {
    $snap = $_SESSION['undo_exam'];
    $x = $snap['exam'] ?? null;
    $msg = '';
    if (is_array($x) && isset($x['fagkode'], $x['dato'])) {
        $stmt = $conn->prepare('INSERT INTO eksamen (fagkode, enr, dato, karakter) VALUES (?,?,?,?)');
        $fk = (string)$x['fagkode'];
        $enr = isset($x['enr']) ? (int)$x['enr'] : null; // skal være heltall (int) eller null
        $dato = (string)$x['dato'];
        $kar = (string)($x['karakter'] ?? '');
        try {
            $enrBind = $enr ?? 0; // nybegynner‑forenkling
            $stmt->execute([$fk, $enrBind, $dato, $kar]);
            $msg = 'Angre utført: gjenopprettet eksamensoppføring.';
            unset($_SESSION['undo_exam']);
        } catch (Throwable $e) {
            $msg = 'Angre mislyktes: ' . $e->getMessage();
        }
    } else {
        $msg = 'Ingenting å angre.';
        unset($_SESSION['undo_exam']);
    }
    header('Location: eksamen.php?msg=' . urlencode($msg));
    exit;
}

// Slett bruker sammensatt nøkkel
if (isset($_GET['del_fagkode'], $_GET['del_enr'], $_GET['del_dato'])) {
    $fk = trim((string)$_GET['del_fagkode']);
    $enr = (int)$_GET['del_enr'];
    $dato = trim((string)$_GET['del_dato']);
    if ($fk !== '' && $enr > 0 && $dato !== '') {
        // Ta øyeblikksbilde for angre
        $snap = null;
        $s = $conn->prepare('SELECT fagkode, enr, dato, karakter FROM eksamen WHERE fagkode = ? AND enr = ? AND dato = ?');
        $s->execute([$fk, $enr, $dato]);
        $snap = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        $ok = true; $err = '';
        if ($stmt = $conn->prepare('DELETE FROM eksamen WHERE fagkode = ? AND enr = ? AND dato = ?')) {
            try { $stmt->execute([$fk, $enr, $dato]); } catch (PDOException $e) { $ok = false; $err = $e->getMessage(); }
        }
        if ($ok) {
            if ($snap) { $_SESSION['undo_exam'] = ['exam' => $snap, 'when' => time()]; }
            header('Location: eksamen.php?act=deleted&msg=' . urlencode('Slettet eksamensoppføring. Du kan angre nedenfor.'));
            exit;
        } else {
            header('Location: eksamen.php?msg=' . urlencode('Kunne ikke slette eksamensoppføring: ' . $err));
            exit;
        }
    }
    header('Location: eksamen.php');
    exit;
}

// Legg til / oppdater
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['mode'] ?? 'add';
    $fagkode = trim((string)($_POST['fagkode'] ?? ''));
    $enr = (int)($_POST['enr'] ?? 0);
    $dato = trim((string)($_POST['dato'] ?? ''));
    $karakter = trim((string)($_POST['karakter'] ?? ''));

    if ($mode === 'edit') {
        $orig_fk = trim((string)($_POST['orig_fagkode'] ?? ''));
        $orig_enr = (int)($_POST['orig_enr'] ?? 0);
        $orig_dato = trim((string)($_POST['orig_dato'] ?? ''));
        $stmt = $conn->prepare('UPDATE eksamen SET fagkode = ?, enr = ?, dato = ?, karakter = ? WHERE fagkode = ? AND enr = ? AND dato = ?');
        $stmt->execute([$fagkode, $enr, $dato, $karakter, $orig_fk, $orig_enr, $orig_dato]);
    } else {
        $stmt = $conn->prepare('INSERT INTO eksamen (fagkode, enr, dato, karakter) VALUES (?,?,?,?)');
        $stmt->execute([$fagkode, $enr, $dato, $karakter]);
    }
    header('Location: eksamen.php?act=edited');
    exit;
}

// Forhåndsutfylling
$edit_fk = isset($_GET['edit_fk']) ? trim((string)$_GET['edit_fk']) : '';
$edit_enr = isset($_GET['edit_enr']) ? (int)$_GET['edit_enr'] : 0;
$edit_dato = isset($_GET['edit_dato']) ? trim((string)$_GET['edit_dato']) : '';
$editRow = null;
if ($edit_fk !== '' && $edit_enr > 0 && $edit_dato !== '') {
    $stmt = $conn->prepare('SELECT fagkode, enr, dato, karakter FROM eksamen WHERE fagkode = ? AND enr = ? AND dato = ?');
    $stmt->execute([$edit_fk, $edit_enr, $edit_dato]);
    $editRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Last data til nedtrekkslister
$students = [];
foreach ($conn->query('SELECT enr, fornavn, etternavn FROM elev ORDER BY enr') as $r) { $students[] = $r; }
$subjects = [];
foreach ($conn->query('SELECT fagkode, fagnavn FROM fag ORDER BY fagkode') as $r) { $subjects[] = $r; }

// Last eksamensrader med JOIN for visning
$rows = [];
$sql = 'SELECT x.fagkode, x.enr, x.dato, x.karakter, e.fornavn, e.etternavn, f.fagnavn
        FROM eksamen x 
        LEFT JOIN elev e ON e.enr = x.enr
        LEFT JOIN fag f ON f.fagkode = x.fagkode
        ORDER BY x.dato DESC, x.enr, x.fagkode';
$stmtList = $conn->query($sql);
$rows = $stmtList->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="no">
<head>
    <meta charset="UTF-8">
    <title>Eksamen</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { overflow:auto; padding: 20px; }
        table { border-collapse: collapse; background: rgba(0,0,0,0.4); }
        th, td { border: 1px solid #666; padding: 6px 8px; }
        a { color: #ffae00; }
        .box { max-width: 1100px; margin: 0 auto; }
        .form { margin: 15px 0; padding: 10px; background: rgba(0,0,0,0.5); border-radius: 6px; }
        label { display:inline-block; min-width: 110px; }
        input, select { padding: 6px; margin: 4px 6px 4px 0; }
    </style>
    </head>
<body>

<div class="box">
    <p><a href="index.php">← Til menyen</a></p>
    <h2>Eksamen</h2>

    <?php $act = isset($_GET['act']) ? (string)$_GET['act'] : ''; ?>
    <?php if (!empty($_GET['msg']) || ($act === 'deleted' || $act === 'edited')): ?>
        <div style="margin:10px 0; padding:10px; background:#553; border:1px solid #aa7; border-radius:6px;">
            <?php if (!empty($_GET['msg'])): ?>
                <?= h((string)$_GET['msg']) ?>
            <?php endif; ?>
            <?php if ($act === 'deleted' && !empty($_SESSION['undo_exam'])): ?>
                <div style="margin-top:6px;">
                    <a href="eksamen.php?undo=1" style="color:#ffae00;" onclick="return confirm('Angre siste sletting?');">Angre siste sletting</a>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="form">
        <?php $isEdit = $editRow !== null; ?>
        <h3><?= $isEdit ? 'Rediger eksamensresultat' : 'Legg til nytt eksamensresultat' ?></h3>
        <form method="post">
            <input type="hidden" name="mode" value="<?= $isEdit ? 'edit' : 'add' ?>">
            <?php if ($isEdit): ?>
                <input type="hidden" name="orig_fagkode" value="<?= h($editRow['fagkode']) ?>">
                <input type="hidden" name="orig_enr" value="<?= h((string)$editRow['enr']) ?>">
                <input type="hidden" name="orig_dato" value="<?= h($editRow['dato']) ?>">
            <?php endif; ?>
            <label>Student (ID):</label>
            <select name="enr" required>
                <option value="">--</option>
                <?php foreach ($students as $s): ?>
                    <?php $sel = ($isEdit && (int)$editRow['enr'] === (int)$s['enr']) ? 'selected' : ''; ?>
                    <option value="<?= h((string)$s['enr']) ?>" <?= $sel ?>><?= h((string)$s['enr']) ?> - <?= h($s['fornavn'] . ' ' . $s['etternavn']) ?></option>
                <?php endforeach; ?>
            </select>
            <label>Fag (fagkode):</label>
            <select name="fagkode" required>
                <option value="">--</option>
                <?php foreach ($subjects as $sub): ?>
                    <?php $sel = ($isEdit && $editRow['fagkode'] === $sub['fagkode']) ? 'selected' : ''; ?>
                    <option value="<?= h($sub['fagkode']) ?>" <?= $sel ?>><?= h($sub['fagkode']) ?> - <?= h($sub['fagnavn']) ?></option>
                <?php endforeach; ?>
            </select>
            <label>Dato:</label>
            <input type="date" name="dato" value="<?= h($isEdit ? substr($editRow['dato'], 0, 10) : '') ?>" required>
            <label>Karakter:</label>
            <input type="text" name="karakter" value="<?= h($isEdit ? $editRow['karakter'] : '') ?>" required>
            <button type="submit">Lagre</button>
            <?php if ($isEdit): ?><a href="eksamen.php" style="margin-left:10px;">Avbryt</a><?php endif; ?>
        </form>
    </div>

    <h3>Alle eksamensresultater</h3>
    <table>
        <tr>
            <th>Dato</th>
            <th>Student</th>
            <th>Fag</th>
            <th>Karakter</th>
            <th>Handlinger</th>
        </tr>
        <?php if (!$rows): ?>
            <tr><td colspan="5">Ingen eksamensresultater ennå.</td></tr>
        <?php else: foreach ($rows as $r): ?>
            <tr>
                <td><?= h(substr($r['dato'], 0, 10)) ?></td>
                <td>
                    <?php if ($r['enr'] !== null): ?>
                        <?= h((string)$r['enr']) ?> - <?= h(trim(($r['fornavn'] ?? '') . ' ' . ($r['etternavn'] ?? ''))) ?>
                    <?php else: ?>
                        (ingen elev)
                    <?php endif; ?>
                </td>
                <td><?= h($r['fagkode']) ?> - <?= h($r['fagnavn']) ?></td>
                <td><?= h($r['karakter']) ?></td>
                <td>
                    <a href="eksamen.php?edit_fk=<?= h(urlencode($r['fagkode'])) ?>&edit_enr=<?= h((string)$r['enr']) ?>&edit_dato=<?= h(urlencode(substr($r['dato'],0,10))) ?>">Rediger</a> |
                    <a href="eksamen.php?del_fagkode=<?= h(urlencode($r['fagkode'])) ?>&del_enr=<?= h((string)$r['enr']) ?>&del_dato=<?= h(urlencode(substr($r['dato'],0,10))) ?>"
                       onclick="return confirm('Slette denne eksamensoppføringen?');">Slett</a>
                </td>
            </tr>
        <?php endforeach; endif; ?>
    </table>
</div>

</body>
</html>
<?php $conn = null; ?>
