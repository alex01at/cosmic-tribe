-- Durchsuchbares Ereignis-Log fürs Admin-Panel: Spieler-Aktionen (Registrierung,
-- Allianz-Ereignisse, Angriffe, Kolonisierung) und technische Fehler (uncaught Exceptions),
-- damit der Betreiber Spielgeschehen/Probleme nachvollziehen kann, ohne SSH-Zugriff auf
-- Server-Logs zu brauchen. Bewusst nur ein Freitext-Feld statt vieler Spalten - die Suche läuft
-- über LIKE, keine strukturierten Abfragen nötig.
CREATE TABLE event_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    universe_id INT UNSIGNED NULL,
    category ENUM('register', 'alliance', 'combat', 'colonize', 'error') NOT NULL,
    message VARCHAR(500) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_event_log_created ON event_log (created_at);
CREATE INDEX idx_event_log_category ON event_log (category);
