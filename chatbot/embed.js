
(function() {
    // --- Funzione per ottenere il percorso base dello script embed.js ---
    function getScriptBasePath() {
        const scripts = document.getElementsByTagName('script');
        for (let i = 0; i < scripts.length; i++) {
            // Cerca lo script che ha "embed.js" nel suo src
            // Questo è un po' euristico; se hai più file chiamati embed.js
            // potresti aver bisogno di un ID o un data-attribute sullo script tag.
            if (scripts[i].src && scripts[i].src.includes('/embed.js')) {
                const scriptSrc = scripts[i].src;
                // Rimuove "embed.js" dall'URL per ottenere il percorso della cartella
                // e poi rimuove eventuali parametri query (come ?t=...)
                return scriptSrc.substring(0, scriptSrc.lastIndexOf('/') + 1).split('?')[0];
            }
        }
        // Fallback se non trovato (improbabile se lo script è in esecuzione)
        // Potrebbe essere necessario un approccio più robusto se questo fallisce.
        console.warn('Embed.js: Impossibile determinare il percorso base dello script. Uso un percorso relativo vuoto.');
        return ''; // O un percorso di default se necessario
    }

    const basePath = getScriptBasePath(); // Es: "/my-chatbot-folder/" o "/some/path/chatbot/"

    // --- Configurazione con percorsi dinamici ---
    const chatbotPageUrl = basePath + 'chatbot.php';         // URL chatbot.php
    const buttonIconClosed = basePath + 'images/icon-closed.gif'; // URL icona chiusa
    const buttonIconOpen = basePath + 'images/icon-open.png';     // URL icona aperta

    // Log per debug (puoi rimuoverlo in produzione)
    console.log('Chatbot Embed Base Path:', basePath);
    console.log('Chatbot Page URL:', chatbotPageUrl);
    console.log('Icon Closed URL:', buttonIconClosed);
    console.log('Icon Open URL:', buttonIconOpen);

    const widgetWidth = '400px'; // Larghezza iframe
    const widgetHeight = '600px'; // Altezza iframe
    const widgetBottom = '90px';  // Distanza dal basso quando aperto
    const widgetRight = '20px';   // Distanza da destra
    const buttonBottom = '20px';  // Distanza pulsante dal basso
    const buttonRight = '20px';   // Distanza pulsante da destra
    const buttonSize = '70px';    // Dimensione pulsante

    // --- Stili CSS da iniettare (rimangono invariati) ---
    const styles = `
        #chatbot-embed-button {
            position: fixed;
            bottom: ${buttonBottom};
            right: ${buttonRight};
            width: ${buttonSize};
            height: ${buttonSize};
            background-color: transparent;
            border: none;
            border-radius: 50%;
            padding: 0;
            cursor: pointer;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            z-index: 2147483646 !important;
            overflow: hidden;
            transition: transform 0.2s ease-in-out;
        }
        #chatbot-embed-button:hover {
            transform: scale(1.1);
        }
        #chatbot-embed-button img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
            clip-path: circle(45% at center);
        }
        #chatbot-embed-iframe-container {
            position: fixed;
            right: ${widgetRight};
            bottom: -${parseInt(widgetHeight) + 50}px;
            width: ${widgetWidth};
            height: ${widgetHeight};
            max-width: 95vw;
            max-height: 85vh;
            border: none;
            border-radius: 16px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            overflow: hidden;
            z-index: 2147483645 !important;
            transition: bottom 0.4s ease-out;
            background-color: #transparent;
        }
        #chatbot-embed-iframe-container.open {
            bottom: ${widgetBottom};
        }
        #chatbot-embed-iframe {
            width: 100%;
            height: 100%;
            border: none;
            display: block;
        }
        @media (max-width: 480px) {
            #chatbot-embed-iframe-container {
                width: calc(100% - 20px);
                height: calc(100% - 80px);
                right: 10px;
                bottom: -110vh;
            }
             #chatbot-embed-iframe-container.open {
                bottom: 70px;
             }
            #chatbot-embed-button {
                bottom: 10px;
                right: 10px;
                width: 60px;
                height: 60px;
            }
            #chatbot-embed-button img {
                clip-path: circle(45% at center);
            }
        }
    `;

    // --- Inietta CSS nella head ---
    const styleSheet = document.createElement("style");
    styleSheet.type = "text/css";
    styleSheet.innerText = styles;
    document.head.appendChild(styleSheet);

    // --- Crea elementi HTML ---
    const button = document.createElement('button');
    button.id = 'chatbot-embed-button';
    const buttonImg = document.createElement('img');
    buttonImg.src = buttonIconClosed; // Ora usa il percorso dinamico
    buttonImg.alt = 'Apri chat';
    button.appendChild(buttonImg);

    const iframeContainer = document.createElement('div');
    iframeContainer.id = 'chatbot-embed-iframe-container';

    const iframe = document.createElement('iframe');
    iframe.id = 'chatbot-embed-iframe';
    iframe.title = 'Chatbot';
    iframe.setAttribute('allow', 'autoplay');

    iframeContainer.appendChild(iframe);

    document.body.appendChild(button);
    document.body.appendChild(iframeContainer);

    // --- Logica Event Listener ---
    let isChatOpen = false;

    function closeChat() {
        if (!isChatOpen) return;
        isChatOpen = false;
        iframeContainer.classList.remove('open');
        buttonImg.src = buttonIconClosed; // Usa il percorso dinamico
        buttonImg.alt = 'Apri chat';
        button.setAttribute('aria-label', 'Apri chat');
        console.log("Embed: Chat chiusa.");
    }

    button.addEventListener('click', () => {
        if (isChatOpen) {
            closeChat();
        } else {
            isChatOpen = true;
            iframeContainer.classList.add('open');
            buttonImg.src = buttonIconOpen; // Usa il percorso dinamico
            buttonImg.alt = 'Chiudi chat';
            button.setAttribute('aria-label', 'Chiudi chat');
            iframe.src = chatbotPageUrl + '?t=' + Date.now(); // Usa il percorso dinamico
            console.log(`Embed: Chat aperta e iframe ricaricato (${iframe.src})`);
        }
    });

    // Listener per i messaggi dall'iframe (ricorda di aggiungere il controllo dell'origine in produzione)
    const expectedOrigin = new URL(chatbotPageUrl, window.location.origin).origin;

    window.addEventListener('message', (event) => {
        if (event.origin !== expectedOrigin) {
            // Disabilita temporaneamente questo warning se stai testando da file:/// localmente
            // o se l'origine attesa non è corretta durante lo sviluppo
            // console.warn('Embed: Messaggio ricevuto da origine non attendibile:', event.origin, 'Attesa:', expectedOrigin);
            // return; // Scommenta in produzione
        }

        if (event.data === 'chatbot-close-request') {
            console.log("Embed: Ricevuto 'chatbot-close-request' dall'iframe.");
            closeChat();
        }
    });

    console.log('Chatbot embed script caricato e listener attivi.');

})();