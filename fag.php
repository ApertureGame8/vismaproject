<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// CRUD for fag(fagkode, fagnavn)

// Liten hjelper: forsøk å endre FK på eksamen→fag slik at slett av fag kobler fra eksamensrader (ON DELETE SET NULL)
function auto_detach_fk_subject(PDO $conn): array {
    $steps = [
        // Noen MySQL‑oppsett krever å droppe FK før endring av kolonnen
        'ALTER TABLE eksamen DROP FOREIGN KEY fk_eksamen_fag',
        // Gjør fagkode tillat med NULL (bredde 50 for enkelhets skyld)
        "ALTER TABLE eksamen MODIFY COLUMN fagkode VARCHAR(50) NULL",
        // Legg til FK som setter NULL ved sletting av fag
        'ALTER TABLE eksamen ADD CONSTRAINT fk_eksamen_fag FOREIGN KEY (fagkode) REFERENCES fag(fagkode) ON DELETE SET NULL ON UPDATE CASCADE',
    ];
    $applied = [];
    foreach ($steps as $sql) {
        try {
            $conn->exec($sql);
            $applied[] = [$sql, true, 'OK'];
        } catch (Throwable $e) {
            $applied[] = [$sql, false, $e->getMessage()];
        }
    }
    $allOk = true; foreach ($applied as $a) { if (!$a[1]) { $allOk = false; break; } }
    return ['ok' => $allOk, 'steps' => $applied];
}

// Angre siste sletting (enkelt nivå)
if (isset($_GET['undo']) && !empty($_SESSION['undo_subject'])) {
    $snap = $_SESSION['undo_subject'];
    $row = $snap['subject'] ?? null;
    $msg = '';
    if (is_array($row) && isset($row['fagkode'])) {
        $stmt = $conn->prepare('INSERT INTO fag (fagkode, fagnavn) VALUES (?, ?)');
        $fk = (string)$row['fagkode'];
        $navn = (string)($row['fagnavn'] ?? '');
        try {
            $stmt->execute([$fk, $navn]);
            $msg = 'Angre utført: gjenopprettet faget ' . $fk . '.';
            unset($_SESSION['undo_subject']);
        } catch (Throwable $e) {
            $msg = 'Angre mislyktes: ' . $e->getMessage();
        }
    } else {
        $msg = 'Ingenting å angre.';
        unset($_SESSION['undo_subject']);
    }
    header('Location: fag.php?msg=' . urlencode($msg));
    exit;
}

// Slett
if (isset($_GET['delete'])) {
    $fagkode = trim((string)$_GET['delete']);
    if ($fagkode !== '') {
        // øyeblikksbilde før sletting for angre
        $snap = null;
        $s = $conn->prepare('SELECT fagkode, fagnavn FROM fag WHERE fagkode = ?');
        $s->execute([$fagkode]);
        $snap = $s->fetch(PDO::FETCH_ASSOC) ?: null;

        try {
            $conn->beginTransaction();
            $ok = true; $err = '';
            $stmt = $conn->prepare('DELETE FROM fag WHERE fagkode = ?');
            try { $stmt->execute([$fagkode]); } catch (PDOException $e) { $ok = false; $err = $e->getMessage(); }

            if (!$ok && stripos($err, 'foreign key') !== false) {
                // Forsøk automatisk FK‑justering: koble fra eksamener ved sletting
                $conn->rollBack();
                $fix = auto_detach_fk_subject($conn);
                $conn->beginTransaction();
                $ok = true; $err = '';
                $stmt = $conn->prepare('DELETE FROM fag WHERE fagkode = ?');
                try { $stmt->execute([$fagkode]); } catch (PDOException $e) { $ok = false; $err = $e->getMessage(); }
                if ($ok) {
                    if ($snap) { $_SESSION['undo_subject'] = ['subject' => $snap, 'when' => time()]; }
                    $conn->commit();
                    $note = $fix['ok'] ? ' Utførte automatisk databaseendring (kobler fra eksamener ved sletting av fag).' : '';
                    header('Location: fag.php?act=deleted&msg=' . urlencode('Slettet fag ' . $fagkode . '.' . $note . ' Du kan angre nedenfor.'));
                    exit;
                } else {
                    $conn->rollBack();
                    $m = 'Kan ikke slette faget ' . $fagkode . '. Det finnes sannsynligvis eksamensrader som peker på dette faget. DB sa: ' . $err;
                    header('Location: fag.php?msg=' . urlencode($m));
                    exit;
                }
            }

            if ($ok) {
                if ($snap) { $_SESSION['undo_subject'] = ['subject' => $snap, 'when' => time()]; }
                $conn->commit();
                header('Location: fag.php?act=deleted&msg=' . urlencode('Slettet fag ' . $fagkode . '. Du kan angre nedenfor.'));
                exit;
            } else {
                if ($conn->inTransaction()) { $conn->rollBack(); }
                $m = 'Kan ikke slette faget ' . $fagkode . '. Det finnes sannsynligvis eksamensrader som peker på dette faget. DB sa: ' . $err;
                header('Location: fag.php?msg=' . urlencode($m));
                exit;
            }
        } catch (Throwable $t) {
            if ($conn->inTransaction()) { $conn->rollBack(); }
            $m = 'Sletting feilet for fag ' . $fagkode . ': ' . $t->getMessage();
            header('Location: fag.php?msg=' . urlencode($m));
            exit;
        }
    }
    header('Location: fag.php?act=edited');
    exit;
}

// Legg til / oppdater
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['mode'] ?? 'add';
    $fagkode = trim((string)($_POST['fagkode'] ?? ''));
    $fagnavn = trim((string)($_POST['fagnavn'] ?? ''));

    if ($mode === 'edit') {
        $orig = trim((string)($_POST['orig_fagkode'] ?? ''));
        $stmt = $conn->prepare('UPDATE fag SET fagkode = ?, fagnavn = ? WHERE fagkode = ?');
        $stmt->execute([$fagkode, $fagnavn, $orig]);
    } else {
        $stmt = $conn->prepare('INSERT INTO fag (fagkode, fagnavn) VALUES (?, ?)');
        $stmt->execute([$fagkode, $fagnavn]);
    }
    header('Location: fag.php');
    exit;
}

// Forhåndsutfylling for redigering
$editFagkode = isset($_GET['edit']) ? trim((string)$_GET['edit']) : '';
$editRow = null;
if ($editFagkode !== '') {
    $stmt = $conn->prepare('SELECT fagkode, fagnavn FROM fag WHERE fagkode = ?');
    $stmt->execute([$editFagkode]);
    $editRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Last alle fag
$rows = [];
$stmtAll = $conn->query('SELECT fagkode, fagnavn FROM fag ORDER BY fagkode');
$rows = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="no">
<head>
    <meta charset="UTF-8">
    <title>Fag</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { overflow:auto; padding: 20px; }
        table { border-collapse: collapse; background: rgba(0,0,0,0.4); }
        th, td { border: 1px solid #666; padding: 6px 8px; }
        a { color: #ffae00; }
        .box { max-width: 900px; margin: 0 auto; }
        .form { margin: 15px 0; padding: 10px; background: rgba(0,0,0,0.5); border-radius: 6px; }
        label { display:inline-block; min-width: 100px; }
        input { padding: 6px; margin: 4px 6px 4px 0; }
    </style>
    </head>
<body>

<div class="box">
    <p><a href="index.php">← Til menyen</a></p>
    <h2>Fag</h2>

    <?php $act = isset($_GET['act']) ? (string)$_GET['act'] : ''; ?>
    <?php if (!empty($_GET['msg']) || ($act === 'deleted' || $act === 'edited')): ?>
        <div style="margin:10px 0; padding:10px; background:#553; border:1px solid #aa7; border-radius:6px;">
            <?php if (!empty($_GET['msg'])): ?>
                <?= h((string)$_GET['msg']) ?>
            <?php endif; ?>
            <?php if ($act === 'deleted' && !empty($_SESSION['undo_subject'])): ?>
                <div style="margin-top:6px;">
                    <a href="fag.php?undo=1" style="color:#ffae00;" onclick="return confirm('Angre siste sletting?');">Angre siste sletting</a>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="form">
        <?php $isEdit = $editRow !== null; ?>
        <h3><?= $isEdit ? 'Rediger fag' : 'Legg til nytt fag' ?></h3>
        <form method="post">
            <input type="hidden" name="mode" value="<?= $isEdit ? 'edit' : 'add' ?>">
            <?php if ($isEdit): ?>
                <input type="hidden" name="orig_fagkode" value="<?= h($editRow['fagkode']) ?>">
            <?php endif; ?>
            <label>Fagkode:</label>
            <input type="text" name="fagkode" value="<?= h($isEdit ? $editRow['fagkode'] : '') ?>" required>
            <label>Fagnavn:</label>
            <input type="text" name="fagnavn" value="<?= h($isEdit ? $editRow['fagnavn'] : '') ?>" required>
            <button type="submit">Lagre</button>
            <?php if ($isEdit): ?><a href="fag.php" style="margin-left:10px;">Avbryt</a><?php endif; ?>
        </form>
    </div>

    <h3>Alle fag</h3>
    <table>
        <tr>
            <th>Fagkode</th>
            <th>Fagnavn</th>
            <th>Handlinger</th>
        </tr>
        <?php if (!$rows): ?>
            <tr><td colspan="3">Ingen fag ennå.</td></tr>
        <?php else: foreach ($rows as $r): ?>
            <tr>
                <td><?= h($r['fagkode']) ?></td>
                <td><?= h($r['fagnavn']) ?></td>
                <td>
                    <a href="fag.php?edit=<?= h(urlencode($r['fagkode'])) ?>">Rediger</a> |
                    <a href="fag.php?delete=<?= h(urlencode($r['fagkode'])) ?>" onclick="return confirm('Slette faget <?= h($r['fagkode']) ?>?');">Slett</a>
                </td>
            </tr>
        <?php endforeach; endif; ?>
    </table>
</div>

</body>
</html>
<?php $conn = null; ?>
