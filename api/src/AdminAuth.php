<?php

declare(strict_types=1);

/**
 * Komplett getrennt von Auth.php (Spieler-Login per device_id) - ein Betreiber-Account ist kein
 * Spielkonto. E-Mail+Passwort statt Geraete-ID, eigene Token-Tabelle (admin_tokens), damit ein
 * gestohlener Spieler-Token niemals Admin-Routen erreichen kann und umgekehrt.
 */
final class AdminAuth
{
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function login(PDO $pdo, string $email, string $password): array
    {
        $stmt = $pdo->prepare('SELECT * FROM admins WHERE email = ?');
        $stmt->execute([trim($email)]);
        $admin = $stmt->fetch();

        // Dieselbe Fehlermeldung bei unbekannter E-Mail UND falschem Passwort - sonst liesse sich
        // per Fehlermeldung ausprobieren, welche E-Mails als Admin registriert sind.
        if ($admin === false || !password_verify($password, $admin['password_hash'])) {
            Response::error('E-Mail oder Passwort falsch', 401);
        }

        $token = self::generateToken();
        $pdo->prepare('INSERT INTO admin_tokens (admin_id, token_hash) VALUES (?, ?)')
            ->execute([$admin['id'], self::hashToken($token)]);

        return ['token' => $token, 'email' => $admin['email']];
    }

    public static function requireAdmin(PDO $pdo): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            Response::error('Unauthorized', 401);
        }

        $tokenHash = self::hashToken($matches[1]);
        $stmt = $pdo->prepare(
            'SELECT a.* FROM admins a
             JOIN admin_tokens t ON t.admin_id = a.id
             WHERE t.token_hash = ?'
        );
        $stmt->execute([$tokenHash]);
        $admin = $stmt->fetch();

        if ($admin === false) {
            Response::error('Unauthorized', 401);
        }

        $pdo->prepare('UPDATE admin_tokens SET last_used_at = UTC_TIMESTAMP() WHERE token_hash = ?')
            ->execute([$tokenHash]);

        return $admin;
    }

    public static function logout(PDO $pdo): void
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            $pdo->prepare('DELETE FROM admin_tokens WHERE token_hash = ?')->execute([self::hashToken($matches[1])]);
        }
    }
}
