<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// CRUD for poststeder(postnr, poststed)

// Angre siste sletting (enkelt nivå)
if (isset($_GET['undo']) && !empty($_SESSION['undo_poststed'])) {
    $snap = $_SESSION['undo_poststed'];
    $row = $snap['poststed'] ?? null;
    $msg = '';
    if (is_array($row) && isset($row['postnr'])) {
        $stmt = $conn->prepare('INSERT INTO poststeder (postnr, poststed) VALUES (?, ?)');
        $pn = (int)$row['postnr'];
        $ps = (string)($row['poststed'] ?? '');
        try {
            $stmt->execute([$pn, $ps]);
            $msg = 'Angre utført: gjenopprettet poststed ' . $pn . '.';
            unset($_SESSION['undo_poststed']);
        } catch (Throwable $e) {
            $msg = 'Angre mislyktes: ' . $e->getMessage();
        }
    } else {
        $msg = 'Ingenting å angre.';
        unset($_SESSION['undo_poststed']);
    }
    header('Location: poststeder.php?msg=' . urlencode($msg));
    exit;
}

// Slett
if (isset($_GET['delete'])) {
    $postnr = (int)$_GET['delete'];
    if ($postnr > 0) {
        // snapshot for undo
        $snap = null;
        $s = $conn->prepare('SELECT postnr, poststed FROM poststeder WHERE postnr = ?');
        $s->execute([$postnr]);
        $snap = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        $ok = true; $err = '';
        try { 
            $stmt = $conn->prepare('DELETE FROM poststeder WHERE postnr = ?');
            $stmt->execute([$postnr]);
        } catch (PDOException $e) { $ok = false; $err = $e->getMessage(); }
        if ($ok) {
            if ($snap) { $_SESSION['undo_poststed'] = ['poststed' => $snap, 'when' => time()]; }
            header('Location: poststeder.php?act=deleted&msg=' . urlencode('Slettet poststed ' . $postnr . '. Du kan angre nedenfor.'));
            exit;
        } else {
            $m = 'Kan ikke slette poststed ' . $postnr . '. DB sa: ' . $err;
            header('Location: poststeder.php?msg=' . urlencode($m));
            exit;
        }
    }
    header('Location: poststeder.php');
    exit;
}

// Legg til / oppdater
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['mode'] ?? 'add';
    $postnr = (int)($_POST['postnr'] ?? 0);
    $poststed = trim((string)($_POST['poststed'] ?? ''));

    if ($mode === 'edit') {
        $orig = (int)($_POST['orig_postnr'] ?? 0);
        $stmt = $conn->prepare('UPDATE poststeder SET postnr = ?, poststed = ? WHERE postnr = ?');
        $stmt->execute([$postnr, $poststed, $orig]);
    } else {
        $stmt = $conn->prepare('INSERT INTO poststeder (postnr, poststed) VALUES (?, ?)');
        $stmt->execute([$postnr, $poststed]);
    }
    header('Location: poststeder.php?act=edited');
    exit;
}

// Forhåndsutfylling for redigering
$editPostnr = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editRow = null;
if ($editPostnr > 0) {
    $stmt = $conn->prepare('SELECT postnr, poststed FROM poststeder WHERE postnr = ?');
    $stmt->execute([$editPostnr]);
    $editRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Last alle
$rows = [];
$stmtAll = $conn->query('SELECT postnr, poststed FROM poststeder ORDER BY postnr');
$rows = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="no">
<head>
    <meta charset="UTF-8">
    <title>Poststeder</title>
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
    <h2>Poststeder</h2>

    <?php $act = isset($_GET['act']) ? (string)$_GET['act'] : ''; ?>
    <?php if (!empty($_GET['msg']) || ($act === 'deleted' || $act === 'edited')): ?>
        <div style="margin:10px 0; padding:10px; background:#553; border:1px solid #aa7; border-radius:6px;">
            <?php if (!empty($_GET['msg'])): ?>
                <?= h((string)$_GET['msg']) ?>
            <?php endif; ?>
            <?php if ($act === 'deleted' && !empty($_SESSION['undo_poststed'])): ?>
                <div style="margin-top:6px;">
                    <a href="poststeder.php?undo=1" style="color:#ffae00;" onclick="return confirm('Angre siste sletting?');">Angre siste sletting</a>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="form">
        <?php $isEdit = $editRow !== null; ?>
        <h3><?= $isEdit ? 'Rediger poststed' : 'Legg til nytt poststed' ?></h3>
        <form method="post">
            <input type="hidden" name="mode" value="<?= $isEdit ? 'edit' : 'add' ?>">
            <?php if ($isEdit): ?>
                <input type="hidden" name="orig_postnr" value="<?= h((string)$editRow['postnr']) ?>">
            <?php endif; ?>
            <label>Postnr:</label>
            <input type="number" name="postnr" value="<?= h($isEdit ? (string)$editRow['postnr'] : '') ?>" required>
            <label>Poststed:</label>
            <input type="text" name="poststed" value="<?= h($isEdit ? $editRow['poststed'] : '') ?>" required>
            <button type="submit">Lagre</button>
            <?php if ($isEdit): ?><a href="poststeder.php" style="margin-left:10px;">Avbryt</a><?php endif; ?>
        </form>
    </div>

    <h3>Alle poststeder</h3>
    <table>
        <tr>
            <th>Postnr</th>
            <th>Poststed</th>
            <th>Handlinger</th>
        </tr>
        <?php if (!$rows): ?>
            <tr><td colspan="3">Ingen poststeder ennå.</td></tr>
        <?php else: foreach ($rows as $r): ?>
            <tr>
                <td><?= h((string)$r['postnr']) ?></td>
                <td><?= h($r['poststed']) ?></td>
                <td>
                    <a href="poststeder.php?edit=<?= h((string)$r['postnr']) ?>">Rediger</a> |
                    <a href="poststeder.php?delete=<?= h((string)$r['postnr']) ?>" onclick="return confirm('Slette poststed <?= h((string)$r['postnr']) ?>?');">Slett</a>
                </td>
            </tr>
        <?php endforeach; endif; ?>
    </table>
</div>

</body>
</html>
<?php $conn = null; ?>
