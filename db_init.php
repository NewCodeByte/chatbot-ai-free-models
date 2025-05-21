<?php // db_init.php

/**
 * Inizializza la connessione al database SQLite, crea il file e/o le tabelle
 * necessarie se non esistono, e popola le impostazioni/stili di default
 * la prima volta.
 *
 * @return SQLite3|false Ritorna l'oggetto connessione SQLite3 in caso di successo,
 *                       o false in caso di errore fatale nell'apertura/inizializzazione.
 */
function initDatabase() {
    // Definisce il percorso del file database relativo a questo script
    $dbPath = __DIR__ . '/db.db';
    $db = null; // Inizializza la variabile DB

    try {
        // --- Controlli Permessi ---
        $dbDir = dirname($dbPath);
        if (!is_dir($dbDir)) {
             // Prova a creare la directory se non esiste (improbabile in questo setup, ma per completezza)
             if (!mkdir($dbDir, 0755, true)) {
                 throw new Exception("La directory del database '{$dbDir}' non esiste e non può essere creata.");
             }
        }
        if (!is_writable($dbDir)) {
            throw new Exception("La directory del database '{$dbDir}' non è scrivibile dal server web.");
        }
        if (file_exists($dbPath) && !is_writable($dbPath)) {
            // Se il file esiste ma non è scrivibile, logga un avviso.
            // Potrebbe essere un problema se è richiesta una scrittura (es. setup admin).
            error_log("Attenzione InitDB: Il file del database '{$dbPath}' esiste ma non è scrivibile.");
            // Nota: L'apertura con SQLITE3_OPEN_READWRITE fallirà comunque se il file non è scrivibile.
        }

        // --- Connessione/Creazione Database ---
        // SQLITE3_OPEN_CREATE crea il file .db se non esiste.
        $db = new SQLite3($dbPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        if (!$db) {
            // L'oggetto SQLite3 non è stato creato
            throw new Exception("Impossibile creare o aprire l'oggetto database SQLite3 per: " . $dbPath);
        }

        // Abilita WAL mode per migliori performance concorrenti (lettura/scrittura)
        $db->exec('PRAGMA journal_mode = WAL;');

        // --- Creazione Struttura Tabelle (IF NOT EXISTS) ---

        // Tabella Conversazioni
        $db->exec("CREATE TABLE IF NOT EXISTS conversazioni (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            conversation_id TEXT NOT NULL,
            ip TEXT NOT NULL,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            sender TEXT NOT NULL CHECK(sender IN ('User', 'Bot')),
            message TEXT NOT NULL
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_conversation_id ON conversazioni (conversation_id)");

        // Tabella Settings
        $db->exec("CREATE TABLE IF NOT EXISTS settings (
            name TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )");

        // Tabella Styles
        $db->exec("CREATE TABLE IF NOT EXISTS styles (
            name TEXT PRIMARY KEY,
            value TEXT NOT NULL,
            default_value TEXT NOT NULL
        )");

        // Tabella Admins
        $db->exec("CREATE TABLE IF NOT EXISTS admins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // --- Inserimento Valori di Default (solo se le righe non esistono già) ---

        // Inizia una transazione per efficienza e atomicità
        if (!$db->exec('BEGIN IMMEDIATE TRANSACTION')) {
             // Se non possiamo iniziare una transazione (es. DB read-only), logga e procedi senza transazione
             error_log("InitDB: Impossibile iniziare transazione. Procedo senza.");
             // Non lanciare eccezione qui, prova comunque gli insert individuali.
        }
        $transaction_active = $db->querySingle("PRAGMA query_only;") == 0; // Verifica se siamo in read-write mode
        $success = true; // Flag per commit/rollback


        try {
            // 1. Default Settings
            $defaultSettings = [
                'language'         => 'en_US', // Default italiano
                'chat_title'       => '',
                'welcome_message'  => '',
                'knowledge_base'   => '',
                'bot_rules'        => '',
                'api_key'          => '',
                'api_model'        => 'google/gemma-3-27b-it:free',
                'save_messages'    => '0',
                'show_logo'        => '1',
                'mute_sound'       => '0'
            ];
            $stmtSettings = $db->prepare("INSERT OR IGNORE INTO settings (name, value) VALUES (:name, :value)");
            if (!$stmtSettings) {
                // Errore grave nella preparazione dello statement
                throw new Exception("Errore preparazione statement INSERT IGNORE settings: " . $db->lastErrorMsg());
            }
            foreach($defaultSettings as $name => $value) {
                $stmtSettings->bindValue(':name', $name, SQLITE3_TEXT);
                $stmtSettings->bindValue(':value', $value, SQLITE3_TEXT);
                if (!$stmtSettings->execute()) {
                     error_log("Errore esecuzione INSERT IGNORE setting '{$name}': " . $db->lastErrorMsg());
                     // Non è fatale per IGNORE, ma loggare è utile.
                }
                $stmtSettings->reset();
            }
            $stmtSettings->close();

            // 2. Default Styles
            $defaultStyles = [
                'header_bg_color'         => '#038AF9',
                'header_text_color'       => '#FFFFFF',
                'chat_bg_color'           => '#FFFFFF',
                'user_msg_bg_color'       => '#ADD8E6',
                'user_msg_text_color'     => '#000000',
                'bot_msg_bg_color'        => '#FFC0CB',
                'bot_msg_text_color'      => '#000000',
                'send_button_bg_color'    => '#FF0000',
                'send_button_text_color'  => '#FFFFFF'
            ];
            $stmtStyles = $db->prepare("INSERT OR IGNORE INTO styles (name, value, default_value) VALUES (:name, :value, :default_value)");
             if (!$stmtStyles) {
                 // Errore grave nella preparazione dello statement
                 throw new Exception("Errore preparazione statement INSERT IGNORE styles: " . $db->lastErrorMsg());
             }
            foreach ($defaultStyles as $name => $value) {
                $stmtStyles->bindValue(':name', $name, SQLITE3_TEXT);
                $stmtStyles->bindValue(':value', $value, SQLITE3_TEXT);
                $stmtStyles->bindValue(':default_value', $value, SQLITE3_TEXT);
                 if (!$stmtStyles->execute()) {
                     error_log("Errore esecuzione INSERT IGNORE style '{$name}': " . $db->lastErrorMsg());
                 }
                $stmtStyles->reset();
            }
            $stmtStyles->close();

        } catch (Exception $e) {
            error_log("Eccezione durante inserimento default in db_init: " . $e->getMessage());
            $success = false; // Errore durante l'esecuzione degli insert
        }

        // Finalizza la transazione se era attiva
        if ($transaction_active) {
            if ($success) {
                if (!$db->exec('COMMIT')) {
                     error_log("InitDB: Fallimento COMMIT transazione default.");
                     // Non necessariamente fatale, ma preoccupante
                }
            } else {
                if (!$db->exec('ROLLBACK')) {
                     error_log("InitDB: Fallimento ROLLBACK transazione default.");
                }
                // Se siamo arrivati qui con $success = false, significa che c'è stato un errore
                // nella preparazione o un'eccezione durante l'esecuzione degli INSERT.
                // Rilanciamo un'eccezione generica per segnalare il fallimento dell'inizializzazione dei default.
                throw new Exception("Fallimento inserimento valori di default nel database durante l'inizializzazione.");
            }
        } elseif (!$success) {
             // Se la transazione non era attiva ma c'è stato un errore (es. prepare fallito)
             throw new Exception("Fallimento inserimento valori di default nel database durante l'inizializzazione (senza transazione).");
        }

        // Se tutto è andato bene (creazione tabelle e tentativo insert default)
        return $db;

    } catch (Exception $e) {
        // Gestisce errori da connessione, permessi, creazione tabelle o fallimento inserimento default
        error_log("Errore FATALE in initDatabase(): " . $e->getMessage());
        // Tenta di chiudere la connessione se è stata aperta parzialmente
        if (isset($db) && $db instanceof SQLite3) {
            @$db->close(); // Usa @ per sopprimere eventuali errori sulla chiusura di una connessione problematica
        }
        return false; // Segnala l'errore al chiamante
    }
}

?>