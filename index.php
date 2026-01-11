<?php
declare(strict_types=1);
require __DIR__ . '/db.php';

// Veldig enkel forside som lenker til hver seksjon
// Holder løsningen nybegynnervennlig
?>
<!DOCTYPE html>
<html lang="no">
<head>
    <meta charset="UTF-8">
    <title>Mini‑Visma – Meny</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { overflow: auto; padding: 20px; }
        .menu { max-width: 760px; margin: 20px auto; background: rgba(0,0,0,0.5); padding: 20px; border-radius: 8px; }
        .menu h1 { margin-bottom: 10px; }
        .menu ul { list-style: none; margin-top: 10px; }
        .menu li { margin: 10px 0; }
        .menu a { color: #ffae00; text-decoration: none; font-weight: 600; }
        .menu a:hover { text-decoration: underline; }
        .small { font-size: 0.9em; color: #ddd; }
    </style>
    </head>
<body>

<div class="menu">
    <h1>Mini‑Visma</h1>
    <p class="small">
        <?php $pServer = env_or_default('DB_HOST','localhost'); $pPort = (int)env_or_default('DB_PORT',3306); $pDb = env_or_default('DB_NAME','mini_visma'); ?>
        Koblet til: <?= h($pServer) . ':' . h((string)$pPort) ?> / <?= h($pDb) ?>
    </p>

    <p>Velg hva du vil jobbe med:</p>
    <ul>
        <li><a href="elever.php">Elever (elev) – vis/legg til/rediger/slett</a></li>
        <li><a href="fag.php">Fag (fag) – vis/legg til/rediger/slett</a></li>
        <li><a href="poststeder.php">Poststeder – vis/legg til/rediger/slett</a></li>
        <li><a href="eksamen.php">Eksamen (eksamen) – vis/legg til/rediger/slett</a></li>
        <li><a href="resultater.php">Oversikt – elever og eksamensresultater</a></li>
    </ul>

</div>

</body>
</html>
<?php $conn = null; ?>