<?php

declare(strict_types=1);

// Legt einen Admin-Account an oder setzt sein Passwort neu (kein Web-Registrierungsformular
// aus Sicherheitsgruenden - nur wer Shell-Zugriff auf den Server hat, darf Admins anlegen).
//
// Aufruf: php api/bin/create_admin.php admin@example.com 'SuperSicheresPasswort123'

require __DIR__ . '/../src/Database.php';

if ($argc !== 3) {
    fwrite(STDERR, "Aufruf: php api/bin/create_admin.php <email> <passwort>\n");
    exit(1);
}

[$script, $email, $password] = $argv;
$email = trim($email);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Ungueltige E-Mail-Adresse.\n");
    exit(1);
}
if (strlen($password) < 8) {
    fwrite(STDERR, "Passwort muss mindestens 8 Zeichen haben.\n");
    exit(1);
}

$pdo = Database::connection();
$hash = password_hash($password, PASSWORD_DEFAULT);

$existing = $pdo->prepare('SELECT id FROM admins WHERE email = ?');
$existing->execute([$email]);

if ($existing->fetch()) {
    $pdo->prepare('UPDATE admins SET password_hash = ? WHERE email = ?')->execute([$hash, $email]);
    echo "Passwort fuer bestehenden Admin '{$email}' aktualisiert.\n";
} else {
    $pdo->prepare('INSERT INTO admins (email, password_hash) VALUES (?, ?)')->execute([$email, $hash]);
    echo "Admin '{$email}' angelegt.\n";
}
