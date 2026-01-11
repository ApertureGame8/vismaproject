<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
// Enkel sesjon for angre‑funksjon
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// Svært enkel CRUD for tabellen: elev(enr, fornavn, etternavn, adresse, postnr)

// Liten hjelper som justerer fremmednøkkel slik at sletting av elev bare «kobler fra» eksamener (uten egen admin‑side)
function auto_detach_fk(PDO $conn): array {
    $steps = [
        'ALTER TABLE eksamen MODIFY COLUMN enr INT NULL',
        'ALTER TABLE eksamen DROP FOREIGN KEY fk_eksamen_elev',
        'ALTER TABLE eksamen ADD CONSTRAINT fk_eksamen_elev FOREIGN KEY (enr) REFERENCES elev(enr) ON DELETE SET NULL ON UPDATE CASCADE',
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
    $allOk = true;
    foreach ($applied as $a) { if (!$a[1]) { $allOk = false; break; } }
    return ['ok' => $allOk, 'steps' => $applied];
}

// Angre siste sletting (enkelt nivå)
if (isset($_GET['undo']) && !empty($_SESSION['undo_student'])) {
    $ud = $_SESSION['undo_student'];
    $stu = $ud['student'] ?? null;
    $exams = $ud['exams'] ?? [];
    $msg = '';
    if (is_array($stu) && isset($stu['enr'])) {
        $conn->beginTransaction();
        try {
            // Gjenopprett elev
            $stmt = $conn->prepare('INSERT INTO elev (enr, fornavn, etternavn, adresse, postnr) VALUES (?,?,?,?,?)');
            $enr = (int)$stu['enr'];
            $fornavn = (string)($stu['fornavn'] ?? '');
            $etternavn = (string)($stu['etternavn'] ?? '');
            $adresse = (string)($stu['adresse'] ?? '');
            $postnr = $stu['postnr'] !== null ? (int)$stu['postnr'] : null;
            // Hvis NULL, bind 0; skjema kan hende ikke tillater NULL i postnr – enkel nybegynner‑tilnærming
            $pn = $postnr ?? 0;
            $stmt->execute([$enr, $fornavn, $etternavn, $adresse, $pn]);
            // Koble til igjen eksamener som ble frakoblet (best effort)
            if ($exams) {
                $u = $conn->prepare('UPDATE eksamen SET enr = ? WHERE enr IS NULL AND fagkode = ? AND dato = ? AND karakter = ?');
                foreach ($exams as $x) {
                    $fk = (string)($x['fagkode'] ?? '');
                    $dt = (string)($x['dato'] ?? '');
                    $kar = (string)($x['karakter'] ?? '');
                    if ($fk === '' || $dt === '') continue;
                    $enr = (int)$stu['enr'];
                    $u->execute([$enr, $fk, $dt, $kar]);
                }
            }
            $conn->commit();
            $msg = 'Angre utført: gjenopprettet elev med ID ' . (int)$stu['enr'] . '  ';
            unset($_SESSION['undo_student']);
        } catch (Throwable $e) {
            if ($conn->inTransaction()) { $conn->rollBack(); }
            $msg = 'Angre mislyktes: ' . $e->getMessage();
        }
    } else {
        $msg = 'Ingenting å angre.';
        unset($_SESSION['undo_student']);
    }
    header('Location: elever.php?msg=' . urlencode($msg));
    exit;
}

// Slett (med valgfri cascade=1 for å også slette tilhørende eksamensrader først)
if (isset($_GET['delete'])) {
    $enr = (int)$_GET['delete'];
    $cascade = isset($_GET['cascade']) && $_GET['cascade'] === '1';
    if ($enr > 0) {
        try {
            // Valgfri enkel transaksjon for å holde ting konsistente
            $conn->beginTransaction();
            // Ta vare på data for Angre før sletting
            $studentRow = null;
            $s = $conn->prepare('SELECT enr, fornavn, etternavn, adresse, postnr FROM elev WHERE enr = ?');
            $s->execute([$enr]);
            $studentRow = $s->fetch(PDO::FETCH_ASSOC) ?: null;
            $examRows = [];
            $r = $conn->prepare('SELECT fagkode, dato, karakter FROM eksamen WHERE enr = ? ORDER BY dato DESC');
            $r->execute([$enr]);
            $examRows = $r->fetchAll(PDO::FETCH_ASSOC);

            if ($cascade) {
                $stmt1 = $conn->prepare('DELETE FROM eksamen WHERE enr = ?');
                $stmt1->execute([$enr]);
            }

            $ok = true;
            $err = '';
            $stmt = $conn->prepare('DELETE FROM elev WHERE enr = ?');
            try {
                $stmt->execute([$enr]);
            } catch (PDOException $e) {
                $ok = false;
                $err = $e->getMessage();
            }

            if (!$ok && stripos($err, 'foreign key') !== false) {
                // Forsøk å auto‑justere FK slik at sletting frakobler eksamener i stedet for å feile
                $conn->rollBack();
                $fix = auto_detach_fk($conn);
                // Prøv sletting på nytt i en ny transaksjon
                $conn->beginTransaction();
                $ok = true; $err = '';
                $stmt = $conn->prepare('DELETE FROM elev WHERE enr = ?');
                try { $stmt->execute([$enr]); } catch (PDOException $e) { $ok = false; $err = $e->getMessage(); }
                if ($ok) {
                    // Lagre angre‑øyeblikksbilde og commit
                    if ($studentRow) {
                        $_SESSION['undo_student'] = [
                            'student' => $studentRow,
                            'exams' => $examRows,
                            'when' => time(),
                        ];
                    }
                    $conn->commit();
                    $note = $fix['ok'] ? ' Utførte automatisk databaseendring (kobler fra eksamener ved sletting).' : '';
                    header('Location: elever.php?act=deleted&msg=' . urlencode('Slettet elev ' . $enr . '.' . $note . ' Du kan angre nedenfor.'));
                    exit;
                } else {
                    $conn->rollBack();
                    $m = 'Kan ikke slette elev ' . $enr . '. Har sannsynligvis eksamensrader. '
                       . 'Du kan velge "Slett med eksamener" for å fjerne eksamener først. DB sa: ' . $err;
                    header('Location: elever.php?msg=' . urlencode($m) . '&offer=' . $enr);
                    exit;
                }
            }

            if ($ok) {
                // Lagre angre‑øyeblikksbilde og commit
                if ($studentRow) {
                    $_SESSION['undo_student'] = [
                        'student' => $studentRow,
                        'exams' => $examRows,
                        'when' => time(),
                    ];
                }
                $conn->commit();
                header('Location: elever.php?act=deleted&msg=' . urlencode('Slettet elev ' . $enr . '. Du kan angre nedenfor.'));
                exit;
            } else {
                if ($conn->inTransaction()) { $conn->rollBack(); }
                $m = 'Kan ikke slette elev ' . $enr . '. Har sannsynligvis eksamensrader. '
                   . 'Du kan velge "Slett med eksamener" for å fjerne eksamener først. DB sa: ' . $err;
                header('Location: elever.php?msg=' . urlencode($m) . '&offer=' . $enr);
                exit;
            }
        } catch (Throwable $t) {
            // Reservehåndtering ved uventet feil
            if ($conn->inTransaction()) { $conn->rollBack(); }
            $m = 'Sletting feilet for elev ' . $enr . ': ' . $t->getMessage();
            header('Location: elever.php?msg=' . urlencode($m) . '&offer=' . $enr);
            exit;
        }
    }
    header('Location: elever.php?act=edited');
    exit;
}

// Legg til eller oppdater
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['mode'] ?? 'add';
    $enr = (int)($_POST['enr'] ?? 0);
    $fornavn = trim((string)($_POST['fornavn'] ?? ''));
    $etternavn = trim((string)($_POST['etternavn'] ?? ''));
    $adresse = trim((string)($_POST['adresse'] ?? ''));
    $postnr = (int)($_POST['postnr'] ?? 0);

    if ($mode === 'edit') {
        $orig_enr = (int)($_POST['orig_enr'] ?? 0);
        $stmt = $conn->prepare('UPDATE elev SET enr = ?, fornavn = ?, etternavn = ?, adresse = ?, postnr = ? WHERE enr = ?');
        $stmt->execute([$enr, $fornavn, $etternavn, $adresse, $postnr, $orig_enr]);
    } else {
        $stmt = $conn->prepare('INSERT INTO elev (enr, fornavn, etternavn, adresse, postnr) VALUES (?,?,?,?,?)');
        $stmt->execute([$enr, $fornavn, $etternavn, $adresse, $postnr]);
    }
    header('Location: elever.php');
    exit;
}

// Forhåndsutfylling av redigeringsskjema
$editEnr = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editRow = null;
if ($editEnr > 0) {
    $stmt = $conn->prepare('SELECT enr, fornavn, etternavn, adresse, postnr FROM elev WHERE enr = ?');
    $stmt->execute([$editEnr]);
    $editRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Last poststeder til nedtrekksmeny
$poststeder = [];
foreach ($conn->query('SELECT postnr, poststed FROM poststeder ORDER BY postnr') as $r) {
    $poststeder[] = $r;
}

// Last gjeldende elevliste
$rows = [];
$sql = 'SELECT e.enr, e.fornavn, e.etternavn, e.adresse, e.postnr, p.poststed FROM elev e LEFT JOIN poststeder p ON e.postnr = p.postnr ORDER BY e.enr';
$stmtList = $conn->query($sql);
$rows = $stmtList->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="no">
<head>
    <meta charset="UTF-8">
    <title>Elever</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { overflow:auto; padding: 20px; }
        table { border-collapse: collapse; background: rgba(0,0,0,0.4); }
        th, td { border: 1px solid #666; padding: 6px 8px; }
        a { color: #ffae00; }
        .box { max-width: 1000px; margin: 0 auto; }
        .form { margin: 15px 0; padding: 10px; background: rgba(0,0,0,0.5); border-radius: 6px; }
        label { display:inline-block; min-width: 100px; }
        input, select { padding: 6px; margin: 4px 6px 4px 0; }
    </style>
    </head>
<body>

<div class="box">
    <p><a href="index.php">← Til menyen</a></p>
    <h2>Elever (elev)</h2>
    <?php $act = isset($_GET['act']) ? (string)$_GET['act'] : ''; ?>
    <?php if (!empty($_GET['msg']) || ($act === 'deleted' && !empty($_SESSION['undo_student']))): ?>
        <div style="margin:10px 0; padding:10px; background:#553; border:1px solid #aa7; border-radius:6px;">
            <?php if (!empty($_GET['msg'])): ?>
                <?= h((string)$_GET['msg']) ?>
            <?php endif; ?>
            <?php if (!empty($_GET['offer'])): ?>
                <?php $oe = (int)$_GET['offer']; ?>
                <div style="margin-top:6px;">
                    <a href="elever.php?delete=<?= h((string)$oe) ?>&cascade=1" style="color:#ffae00;"
                       onclick="return confirm('Slette elev <?= h((string)$oe) ?> OG alle tilhørende eksamener?');">
                        Slett med eksamener
                    </a>
                </div>
            <?php endif; ?>
            <?php if ($act === 'deleted' && !empty($_SESSION['undo_student'])): ?>
                <div style="margin-top:6px;">
                    <a href="elever.php?undo=1" style="color:#ffae00;" onclick="return confirm('Angre siste sletting?');">Angre siste sletting</a>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="form">
        <?php $isEdit = $editRow !== null; ?>
        <h3><?= $isEdit ? 'Rediger elev' : 'Legg til ny elev' ?></h3>
        <form method="post">
            <input type="hidden" name="mode" value="<?= $isEdit ? 'edit' : 'add' ?>">
            <?php if ($isEdit): ?>
                <input type="hidden" name="orig_enr" value="<?= h((string)$editRow['enr']) ?>">
            <?php endif; ?>
            <label>ID:</label>
            <input type="number" name="enr" value="<?= h($isEdit ? (string)$editRow['enr'] : '') ?>" required>
            <label>Fornavn:</label>
            <input type="text" name="fornavn" value="<?= h($isEdit ? $editRow['fornavn'] : '') ?>" required>
            <label>Etternavn:</label>
            <input type="text" name="etternavn" value="<?= h($isEdit ? $editRow['etternavn'] : '') ?>" required>
            <label>Adresse:</label>
            <input type="text" name="adresse" value="<?= h($isEdit ? ($editRow['adresse'] ?? '') : '') ?>">
            <label>Postnr:</label>
            <select name="postnr">
                <option value="">--</option>
                <?php foreach ($poststeder as $p): ?>
                    <?php $sel = ($isEdit && (int)$editRow['postnr'] === (int)$p['postnr']) ? 'selected' : ''; ?>
                    <option value="<?= h((string)$p['postnr']) ?>" <?= $sel ?>><?= h((string)$p['postnr']) ?> - <?= h($p['poststed']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Lagre</button>
            <?php if ($isEdit): ?><a href="elever.php" style="margin-left:10px;">Avbryt</a><?php endif; ?>
        </form>
    </div>

    <h3>Alle elever</h3>
    <table>
        <tr>
            <th>ID</th>
            <th>Fornavn</th>
            <th>Etternavn</th>
            <th>Adresse</th>
            <th>Postnr</th>
            <th>Poststed</th>
            <th>Handlinger</th>
        </tr>
        <?php if (!$rows): ?>
            <tr><td colspan="7">Ingen elever ennå.</td></tr>
        <?php else: foreach ($rows as $r): ?>
            <tr>
                <td><?= h((string)$r['enr']) ?></td>
                <td><?= h($r['fornavn']) ?></td>
                <td><?= h($r['etternavn']) ?></td>
                <td><?= h($r['adresse'] ?? '') ?></td>
                <td><?= h((string)($r['postnr'] ?? '')) ?></td>
                <td><?= h($r['poststed'] ?? '') ?></td>
                <td>
                    <a href="elever.php?edit=<?= h((string)$r['enr']) ?>">Rediger</a> |
                    <a href="elever.php?delete=<?= h((string)$r['enr']) ?>" onclick="return confirm('Slette elev <?= h((string)$r['enr']) ?>? Hvis eleven har eksamener kan du velge \"Slett med eksamener\" på neste side.');">Slett</a>
                </td>
            </tr>
        <?php endforeach; endif; ?>
    </table>
</div>

</body>
</html>
<?php $conn = null; ?>
