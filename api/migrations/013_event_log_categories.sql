-- Zwei neue Log-Kategorien: 'chat' (Allianz-Chat-Nachrichten, bisher gar nicht geloggt) und
-- 'moderation' (vom Wortfilter blockierte Versuche).
ALTER TABLE event_log MODIFY COLUMN category ENUM('register', 'alliance', 'combat', 'colonize', 'error', 'chat', 'moderation') NOT NULL;
