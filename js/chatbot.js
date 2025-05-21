// Variabili globali per tracciare il caricamento degli script esterni
let markedLoaded = false;
let dompurifyLoaded = false;
let scriptsInitialized = false; // Flag per eseguire initializeScripts solo una volta
let currentConversationId = null; // Variabile globale per l'ID conversazione della sessione JS

// Funzione per caricare script esterni (Marked e DOMPurify)
function initializeExternalScripts() {
    if (scriptsInitialized) return;
    scriptsInitialized = true;
    console.log('Chatbot: Inizializzazione script esterni...');

    // Carica Marked.js
    const markedScript = document.createElement('script');
    markedScript.src = 'https://cdn.jsdelivr.net/npm/marked/marked.min.js'; // Usa CDN o percorso locale
    markedScript.onload = () => { markedLoaded = true; console.log('Chatbot: Marked.js caricato.'); };
    markedScript.onerror = () => console.error('Chatbot: Errore caricamento Marked.js');
    document.head.appendChild(markedScript);

    // Carica DOMPurify.js
    const dompurifyScript = document.createElement('script');
    dompurifyScript.src = 'https://cdnjs.cloudflare.com/ajax/libs/dompurify/3.0.6/purify.min.js'; // Usa CDN o percorso locale
    dompurifyScript.onload = () => { dompurifyLoaded = true; console.log('Chatbot: DOMPurify.js caricato.'); };
    dompurifyScript.onerror = () => console.error('Chatbot: Errore caricamento DOMPurify.js');
    document.head.appendChild(dompurifyScript);
}

// Chiama l'inizializzazione degli script esterni subito
initializeExternalScripts();

// --- ESECUZIONE PRINCIPALE all'interno dell'iframe ---
document.addEventListener('DOMContentLoaded', () => {
    console.log('Chatbot: DOM Content Loaded.');

    // --- Selettori Elementi DOM ---
    const sendButton = document.getElementById('send-button');
    const userInput = document.getElementById('user-input');
    const chatMessages = document.getElementById('chat-messages');
    const closeButton = document.getElementById('close-button');

    // --- Variabili di stato e configurazione ---
    // Assicurati che questi percorsi siano corretti rispetto alla root del sito
    const notificationSoundPath = 'sounds/notification.mp3'; // Es: '/chatbot-x/sounds/notification.mp3'
    const imagesBasePath = 'images/';                   // Es: '/chatbot-x/images/'
    const phpApiEndpoint = 'chatbot.php';               // Endpoint relativo (o assoluto es. '/chatbot-x/chatbot.php')

    const settings = window.chatSettings || {};
    const welcomeMessage = settings.welcomeMessage || 'Ciao! Come posso aiutarti?';
    let welcomeMessageShown = false;

    // Verifica elementi essenziali
    if (!sendButton || !userInput || !chatMessages || !closeButton) {
        console.error('Chatbot: Elementi DOM essenziali (send-button, user-input, chat-messages, close-button) non trovati!');
        if(chatMessages) chatMessages.innerHTML = '<p style="color:red; padding:10px;">Errore caricamento interfaccia chat.</p>';
        return; // Interrompi
    }

    // --- FUNZIONE PER GESTIRE L'ID CONVERSAZIONE ---
    /**
     * Ottiene l'ID della conversazione corrente da sessionStorage o ne genera uno nuovo.
     * @returns {string} L'ID della conversazione.
     */
    function getConversationId() {
        // Se abbiamo già un ID in memoria per questa sessione JS, usa quello
        if (currentConversationId) {
            return currentConversationId;
        }
        let storedId = null;
        try {
            // Prova a leggere da sessionStorage
            storedId = sessionStorage.getItem('chatbotConversationId');
        } catch (e) {
            console.warn('Chatbot: sessionStorage non accessibile o disabilitato.', e);
        }

        if (!storedId) {
            // Genera un nuovo ID se non trovato o se sessionStorage non è accessibile
            storedId = 'chat_' + Date.now() + Math.random().toString(36).substring(2, 15);
            console.log('Chatbot: Nuovo ID conversazione generato:', storedId);
            try {
                // Prova a salvare il nuovo ID in sessionStorage
                sessionStorage.setItem('chatbotConversationId', storedId);
            } catch (e) {
                // Fallback se non si può scrivere (l'ID sarà valido solo per la prossima richiesta)
                console.warn('Chatbot: Impossibile salvare ID in sessionStorage. L\'ID sarà temporaneo.', e);
            }
        } else {
             console.log('Chatbot: ID conversazione recuperato da sessionStorage:', storedId);
        }
        // Memorizza l'ID nella variabile globale JS per accessi futuri più rapidi
        currentConversationId = storedId;
        return currentConversationId;
    }


    // --- Funzione Add Message ---
    /**
     * Aggiunge un messaggio (utente o bot) all'area chat.
     * @param {'user' | 'bot'} sender - Chi ha inviato il messaggio.
     * @param {string} message - Il contenuto del messaggio.
     */
    function addMessage(sender, message) {
        if (!chatMessages) return; // Controllo extra

        const messageElement = document.createElement('div');
        messageElement.classList.add('message', sender);

        const avatarElement = document.createElement('img');
        avatarElement.classList.add('avatar');
        let avatarSrc = sender === 'bot' ? `${imagesBasePath}icon-bot.png` : `${imagesBasePath}icon-user.png`;
        // Aggiungi cache buster solo all'URL base, non a un data URL
        if (!avatarSrc.startsWith('data:')) {
             avatarSrc += '?t=' + Date.now();
        }
        avatarElement.src = avatarSrc;
        avatarElement.alt = sender === 'bot' ? 'Bot' : 'Utente';
        avatarElement.onerror = () => { avatarElement.style.display = 'none'; console.warn(`Chatbot: Impossibile caricare avatar: ${avatarSrc}`); };

        const contentElement = document.createElement('div');
        contentElement.classList.add('content');

        // Sanifica e processa Markdown SOLO se le librerie sono caricate E il mittente è il BOT
        if (sender === 'bot' && markedLoaded && dompurifyLoaded && typeof marked !== 'undefined' && typeof DOMPurify !== 'undefined') {
            try {
                const sanitizedHtml = DOMPurify.sanitize(message); // Sanifica prima
                contentElement.innerHTML = marked.parse(sanitizedHtml); // Poi parsa Markdown
            } catch (error) {
                console.error('Chatbot: Errore processing Markdown/Sanitize:', error);
                contentElement.textContent = message; // Fallback
            }
        } else if (sender === 'bot') {
            // Se librerie non pronte, mostra testo semplice (ma logga warning)
            console.warn("Chatbot: Librerie Marked/DOMPurify non pronte per messaggio bot. Mostro testo semplice.");
            contentElement.textContent = message;
        }
         else {
            // Messaggio utente: mostra sempre testo semplice
            contentElement.textContent = message;
        }

        messageElement.appendChild(avatarElement);
        messageElement.appendChild(contentElement);
        chatMessages.appendChild(messageElement);

        // Scrolla alla fine
        setTimeout(() => { if(chatMessages) chatMessages.scrollTop = chatMessages.scrollHeight; }, 50); // Leggero ritardo
    }

    // --- Funzione Send Message (AGGIORNATA con ID Conversazione) ---
    /**
     * Invia il messaggio dell'utente al backend (includendo l'ID conversazione)
     * e visualizza la risposta del bot.
     */
    const sendMessage = async () => {
        const message = userInput.value.trim();
        if (!message) return;

        addMessage('user', message);
        const currentUserMessage = message;
        userInput.value = '';
        userInput.focus();

        // Indicatore di caricamento
        const loadingDiv = document.createElement('div');
        loadingDiv.className = 'message bot loading';
        loadingDiv.innerHTML = `<img src="${imagesBasePath}loading.gif" alt="loading..." class="loading-gif" style="width: 40px; height: 20px;">`;
        if (chatMessages) {
            chatMessages.appendChild(loadingDiv);
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }

        // *** OTTIENI L'ID DELLA CONVERSAZIONE CORRENTE ***
        const conversationId = getConversationId();

        try {
            const response = await fetch(phpApiEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json' // Indica che accettiamo JSON
                },
                // *** INVIA L'ID CONVERSAZIONE NEL BODY ***
                body: JSON.stringify({
                    message: currentUserMessage,
                    conversation_id: conversationId
                 }),
            });

            // Rimuovi indicatore di caricamento
            loadingDiv.remove();

            if (!response.ok) {
                let errorMsg = `Errore HTTP ${response.status}`;
                try {
                    const errorData = await response.json();
                    errorMsg = errorData.message || errorMsg; // Usa messaggio server se disponibile
                } catch (e) { errorMsg += `: ${response.statusText}`; } // Altrimenti usa status text
                throw new Error(errorMsg);
            }

            const data = await response.json();

            if (data && data.status === 'success' && typeof data.message !== 'undefined') {
                addMessage('bot', data.message);
            } else {
                const errMsg = data.message || 'Formato risposta non valido o errore sconosciuto.';
                addMessage('bot', `Errore: ${errMsg}`);
                console.error("Chatbot: Risposta API non valida o errore logico:", data);
            }
        } catch (error) {
            console.error("Chatbot: Errore durante invio/ricezione messaggio:", error);
            if (loadingDiv.parentNode === chatMessages) { // Rimuovi loading se ancora presente
                loadingDiv.remove();
            }
            addMessage('bot', `Errore di connessione: ${error.message}. Riprova.`);
        }
    };

    // --- Event Listener ---
    if (sendButton) sendButton.addEventListener('click', sendMessage);
    if (userInput) {
        userInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                sendMessage();
            }
        });
    }
    if (closeButton) {
        closeButton.addEventListener('click', () => {
            console.log("Chatbot: Close button clicked. Sending message to parent.");
            // Invia messaggio al parent per chiudere l'iframe (come prima)
            if (window.parent) {
                 window.parent.postMessage('chatbot-close-request', '*'); // Usa origine specifica in produzione
            }
        });
    }

    // --- Inizializzazione Chat ---
    // Mostra messaggio di benvenuto
    if (!welcomeMessageShown && welcomeMessage) {
        setTimeout(() => {
           if(chatMessages && !welcomeMessageShown) { // Ricontrolla flag in caso di ritardi
               addMessage('bot', welcomeMessage);
               welcomeMessageShown = true;
           }
        }, 150); // Leggermente ritardato
    }

    // Tenta di riprodurre suono all'apertura (SOLO SE NON È MUTO)
    // *** INIZIO MODIFICA BLOCCO SUONO ***
    const shouldPlaySound = window.chatSettings?.muteSound === '0'; // Leggi l'impostazione ('0' significa NON muto)

    if (shouldPlaySound) {
        console.log('Chatbot: Riproduzione suono di notifica abilitata.');
        try {
            const sound = new Audio(notificationSoundPath);
            sound.play().catch(error => {
                if (error.name === 'NotAllowedError') {
                    console.info("Chatbot: Autoplay audio bloccato dal browser (richiede interazione).");
                } else {
                    console.warn(`Chatbot: Errore riproduzione suono (${notificationSoundPath}):`, error);
                }
            });
        } catch (e) {
            console.error("Chatbot: Errore creazione/play suono:", e);
        }
    } else {
        console.log('Chatbot: Riproduzione suono di notifica disabilitata (mute).');
    }
    // *** FINE MODIFICA BLOCCO SUONO ***


    console.log('Chatbot: Script inizializzato.');

}); // Fine DOMContentLoaded