// Trennung von Backend-URL und Frontend-Code (siehe Konzept-Vorgabe) - Browser und
// Android-WebView (Capacitor) brauchen unterschiedliche API_BASE_URL-Werte, ohne den Rest des
// Codes anzufassen. Für die lokale Entwicklung zeigt das auf den PHP-Dev-Server (anderer Port als
// www, damit CORS tatsächlich greift statt durch Same-Origin maskiert zu werden).
window.STARFORGE_CONFIG = {
    API_BASE_URL: 'http://127.0.0.1:8099/api',
};
