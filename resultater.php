<?php
declare(strict_types=1);
require __DIR__ . '/db.php';

// Oversiktsside over elever og deres eksamensresultater

// Hent radene ved å slå sammen (LEFT JOIN) elev, fag og eksamen for visning
$rows = [];
$sql = 'SELECT e.enr, e.fornavn, e.etternavn, f.fagkode, f.fagnavn, x.dato, x.karakter
        FROM eksamen x
        LEFT JOIN elev e ON e.enr = x.enr
        LEFT JOIN fag f ON f.fagkode = x.fagkode
        ORDER BY e.enr, x.dato DESC, f.fagkode';
$stmt = $conn->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="no">
<head>
    <meta charset="UTF-8">
    <title>Oversikt: Resultater</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { overflow:auto; padding: 20px; }
        table { border-collapse: collapse; background: rgba(0,0,0,0.4); }
        th, td { border: 1px solid #666; padding: 6px 8px; }
        .box { max-width: 1100px; margin: 0 auto; }
    </style>
    </head>
<body>

<div class="box">
    <p><a href="index.php">← Til menyen</a></p>
    <h2>Elever og eksamensresultater</h2>

    <table>
        <tr>
            <th>ID</th>
            <th>Student</th>
            <th>Fagkode</th>
            <th>Fagnavn</th>
            <th>Dato</th>
            <th>Karakter</th>
        </tr>
        <?php if (!$rows): ?>
            <tr><td colspan="6">Ingen resultater funnet.</td></tr>
        <?php else: foreach ($rows as $r): ?>
            <tr>
                <td><?= h((string)$r['enr']) ?></td>
                <td>
                    <?php if ($r['enr'] !== null): ?>
                        <?= h(trim(($r['fornavn'] ?? '') . ' ' . ($r['etternavn'] ?? ''))) ?>
                    <?php else: ?>
                        (ingen elev)
                    <?php endif; ?>
                </td>
                <td><?= h($r['fagkode']) ?></td>
                <td><?= h($r['fagnavn']) ?></td>
                <td><?= h(substr($r['dato'], 0, 10)) ?></td>
                <td><?= h($r['karakter']) ?></td>
            </tr>
        <?php endforeach; endif; ?>
    </table>
</div>

</body>
</html>
<?php $conn = null; ?>
