<?php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    // Potresti mostrare un errore JSON o reindirizzare
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['status' => 'error', 'message' => 'Accesso negato.']);
    exit;
}
// --- Funzioni Helper ---

// Funzione escape HTML standard
if (!function_exists('escape')) {
    function escape($string) {
        // Usa double_encode = false per evitare doppi escaping
        return htmlspecialchars($string ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
    }
}

/**
 * Converte semplici marcatori Markdown (grassetto, corsivo) in HTML.
 * Applica htmlspecialchars PRIMA per sicurezza, poi converte i marcatori.
 * Converte anche gli a capo in <br>.
 *
 * @param string|null $markdownText Il testo potenzialmente contenente Markdown.
 * @return string Il testo convertito in HTML (con formattazione base).
 */
function simpleMarkdownToHtml($markdownText) {
    $text = $markdownText ?? '';
    $escapedText = escape($text); // Sicurezza prima di tutto

    // Converti grassetto: **testo** -> <strong>testo</strong>
    $escapedText = preg_replace_callback(
        '/\*\*(?=\S)(.+?)(?<=\S)\*\*/',
        function ($matches) { return '<strong>' . $matches[1] . '</strong>'; },
        $escapedText
    );

    // Converti corsivo: *testo* -> <em>testo</em> (dopo il grassetto)
    $escapedText = preg_replace_callback(
        '/\*(?=\S)(.+?)(?<=\S)\*/',
        function ($matches) {
            // Semplice controllo per evitare * dentro <strong>*testo*</strong>
             if (strpos($matches[1], '**') === false) {
                 return '<em>' . $matches[1] . '</em>';
             }
             return '*' . $matches[1] . '*'; // Lascia invariato
        },
        $escapedText
    );

    $htmlWithBreaks = nl2br($escapedText); // Aggiungi <br> per a capo
    return $htmlWithBreaks;
}

/**
 * Rimuove i marcatori Markdown semplici da una stringa (per TXT/CSV).
 * Specificamente: **bold**, *italic*
 *
 * @param string|null $markdownText Il testo potenzialmente contenente Markdown.
 * @return string Il testo senza i marcatori specificati.
 */
function stripSimpleMarkdown($markdownText) {
    $text = $markdownText ?? '';

    // Rimuovi ** per grassetto, lasciando il contenuto interno
    $text = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/', '$1', $text);

    // Rimuovi * per corsivo, lasciando il contenuto interno (dopo il grassetto)
     $text = preg_replace('/\* (?=\S)(.+?)(?<=\S)\*/', '$1', $text); // Correggi regex per *

    // Aggiungi qui altre regole di rimozione se necessario (es. #, -, *, >)
    // $text = preg_replace('/^#+\s*/m', '', $text); // Rimuove # titolo
    // $text = preg_replace('/^[\-\*]\s+/m', '', $text); // Rimuove bullet lista
    // $text = preg_replace('/^>\s*/m', '', $text); // Rimuove > citazione

    return $text;
}

// Funzione per la connessione al DB
function getDbConnection() {
    $dbPath = __DIR__ . '/db.db';
    try {
        if (!file_exists($dbPath)) throw new Exception("DB non trovato: " . $dbPath);
        if (!is_readable($dbPath)) throw new Exception("DB non leggibile: " . $dbPath);
        $db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
        $db->exec('PRAGMA journal_mode = WAL;');
        return $db;
    } catch (Exception $e) {
        error_log("Errore connessione DB export.php: " . $e->getMessage());
        return null;
    }
}

// --- Logica Principale ---
ob_start(); // Inizia output buffering

// 1. Determina formato
$format = strtolower($_GET['format'] ?? '');
$allowedFormats = ['txt', 'csv', 'html', 'md'];
$error_message = null;

if (!in_array($format, $allowedFormats)) {
    $error_message = "Formato di esportazione non valido o non specificato.";
    $format = 'error';
}

// 2. Connetti DB
$db = null;
if ($format !== 'error') {
    $db = getDbConnection();
    if (!$db) {
        $error_message = "Errore interno: Impossibile connettersi al database.";
        $format = 'error';
    }
}

// 3. Recupera Messaggi
$messages = [];
if ($format !== 'error' && $db) {
    try {
        $tableCheck = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='conversazioni'");
        if (!$tableCheck) throw new Exception("Tabella 'conversazioni' non trovata.");
        $result = $db->query("SELECT id, ip, timestamp, sender, message FROM conversazioni ORDER BY id DESC");
        if ($result) {
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) { $messages[] = $row; }
        } else { throw new Exception("Errore esecuzione query: " . $db->lastErrorMsg()); }
    } catch (Exception $e) {
        error_log("Errore recupero messaggi export.php: " . $e->getMessage());
        $error_message = "Errore interno: Impossibile recuperare i messaggi.";
        $format = 'error';
    }
    if ($db) $db->close(); // Chiudi connessione
}

// 4. Controlla messaggi vuoti
if ($format !== 'error' && empty($messages)) {
    $error_message = "Nessun messaggio trovato da esportare.";
    $format = 'error';
}

// 5. Prepara Nome File e Content Type
if ($format === 'error') {
    $filename = "export_error.txt";
    $contentType = 'text/plain; charset=utf-8';
} else {
    $filename = "export_messaggi_" . date('Ymd_His') . "." . $format;
    switch ($format) {
        case 'csv': $contentType = 'text/csv; charset=utf-8'; break;
        case 'html': $contentType = 'text/html; charset=utf-8'; break;
        case 'md': $contentType = 'text/markdown; charset=utf-8'; break;
        case 'txt': default: $contentType = 'text/plain; charset=utf-8'; break;
    }
}

// === FINE OUTPUT BUFFERING E INVIO HEADERS ===
$stray_output = ob_get_clean(); // Pulisce buffer e ottiene output accidentale
if (!empty($stray_output) && $format !== 'error') {
     error_log("Output indesiderato rilevato prima degli header in export.php: " . $stray_output);
     $error_message = "Errore interno server durante la generazione dell'export (output before headers).";
     $format = 'error';
     $filename = "export_error.txt";
     $contentType = 'text/plain; charset=utf-8';
}

// 6. INVIA GLI HEADER HTTP
if (ini_get('zlib.output_compression')) ini_set('zlib.output_compression', 'Off'); // Disabilita compressione Gzip
header('Content-Type: ' . $contentType);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');
if ($format === 'csv') header('Content-Transfer-Encoding: binary');

// 7. Genera e Stampa l'Output o il Messaggio di Errore
if ($format === 'error') {
    echo "ERRORE ESPORTAZIONE:\n\n";
    echo $error_message ?? "Errore sconosciuto durante l'esportazione.";
} else {
    // Genera output solo se non ci sono stati errori
    switch ($format) {
        // --- FORMATO TXT ---
        case 'txt':
            $output = "Esportazione Messaggi Chat - " . date('Y-m-d H:i:s') . "\n";
            $output .= "========================================\n\n";
            foreach ($messages as $msg) {
                // *** Pulisci Markdown dal messaggio ***
                $plainMessage = stripSimpleMarkdown($msg['message'] ?? '');

                $output .= "----------------------------------------\n";
                $output .= "ID        : " . ($msg['id'] ?? 'N/A') . "\n";
                $output .= "Timestamp : " . ($msg['timestamp'] ?? 'N/A') . "\n";
                $output .= "Sender    : " . ($msg['sender'] ?? 'N/A') . "\n";
                $output .= "IP Address: " . ($msg['ip'] ?? 'N/A') . "\n";
                $output .= "Message   :\n" . trim($plainMessage) . "\n\n"; // Usa messaggio pulito
            }
            $output .= "----------------------------------------\n";
            echo $output;
            break;

        // --- FORMATO CSV ---
        case 'csv':
            $outputHandle = fopen('php://output', 'w');
            if (!$outputHandle) exit; // Esce silenziosamente se non può aprire lo stream
            // Intestazione CSV
            fputcsv($outputHandle, ['ID', 'Timestamp', 'Sender', 'IP', 'Message']);
            // Righe dati
            foreach ($messages as $msg) {
                // *** Pulisci Markdown dal messaggio ***
                 $plainMessage = stripSimpleMarkdown($msg['message'] ?? '');

                fputcsv($outputHandle, [
                    $msg['id'] ?? '',
                    $msg['timestamp'] ?? '',
                    $msg['sender'] ?? '',
                    $msg['ip'] ?? '',
                    $plainMessage // Usa messaggio pulito
                ]);
            }
            fclose($outputHandle);
            // Niente echo qui, fputcsv scrive direttamente
            break;

        // --- FORMATO HTML ---
        case 'html':
            $output = "<!DOCTYPE html>\n<html lang=\"it\">\n<head>\n";
            $output .= "<meta charset=\"UTF-8\">\n<title>Esportazione Messaggi Chat</title>\n";
            $output .= "<style>body { font-family: sans-serif; font-size: 14px; line-height: 1.4; } table { border-collapse: collapse; width: 100%; margin-top: 15px; } th, td { border: 1px solid #ddd; padding: 8px; text-align: left; vertical-align: top; } th { background-color: #f2f2f2; font-weight: bold; } tr:nth-child(even) { background-color: #f9f9f9; } .message-cell { word-wrap: break-word; } strong { font-weight: bold; } em { font-style: italic; } .message-cell p, .message-cell ul, .message-cell ol { margin-top: 0; margin-bottom: 0.5em; } </style>\n</head>\n<body>\n"; // Aggiunto stile p,ul,ol e strong,em
            $output .= "<h1>Esportazione Messaggi Chat (" . date('d/m/Y H:i:s') . ")</h1>\n";
            $output .= "<table>\n<thead><tr><th>ID</th><th>Timestamp</th><th>Mittente</th><th>IP</th><th>Messaggio</th></tr></thead>\n<tbody>\n";
            foreach ($messages as $msg) {
                $messageContent = $msg['message'] ?? '';
                // --- Logica di formattazione ---
                if (($msg['sender'] ?? '') === 'Bot') {
                    // Renderizza Markdown semplice del Bot in HTML (usando la funzione personalizzata)
                    $formattedMessage = simpleMarkdownToHtml($messageContent);
                } else {
                    // Messaggio Utente: solo escape HTML e converti a capo in <br>
                    $formattedMessage = nl2br(escape($messageContent));
                }
                // --- Fine logica ---
                $output .= "<tr>\n";
                $output .= "<td>" . escape($msg['id'] ?? '') . "</td>\n";
                $output .= "<td>" . escape($msg['timestamp'] ?? '') . "</td>\n";
                $output .= "<td>" . escape($msg['sender'] ?? '') . "</td>\n";
                $output .= "<td>" . escape($msg['ip'] ?? '') . "</td>\n";
                $output .= "<td class=\"message-cell\">" . $formattedMessage . "</td>\n"; // Inserisci risultato
                $output .= "</tr>\n";
            }
            $output .= "</tbody>\n</table>\n</body>\n</html>";
            echo $output;
            break;

        // --- FORMATO MARKDOWN (MD) ---
        case 'md':
            // Usiamo text/plain per maggiore compatibilità, ma il contenuto è MD
            $contentType = 'text/plain; charset=utf-8';
            $output = "# Esportazione Messaggi Chat (" . date('d/m/Y H:i:s') . ")\n\n";

            foreach ($messages as $msg) {
                $output .= "## Messaggio ID: " . escape($msg['id'] ?? 'N/A') . "\n\n"; // Titolo per ogni messaggio
                $output .= "- **Timestamp:** " . escape($msg['timestamp'] ?? 'N/A') . "\n";
                $output .= "- **Sender:** " . escape($msg['sender'] ?? 'N/A') . "\n";
                $output .= "- **IP Address:** " . escape($msg['ip'] ?? 'N/A') . "\n\n";
                $output .= "**Messaggio:**\n\n";

                // Stampa il contenuto del messaggio direttamente.
                // NON rimuovere gli a capo né i marcatori Markdown.
                // Usa trim per rimuovere spazi vuoti iniziali/finali dal messaggio.
                $output .= trim($msg['message'] ?? '') . "\n\n";

                $output .= "---\n\n"; // Separatore orizzontale tra i messaggi
            }
            echo $output;
            break;
    }
}

// 8. Termina esplicitamente lo script
exit;

?>