# AI Chatbot with Admin Panel for Any Website

A self-hosted AI chatbot for any website. Includes a full admin panel to manage appearance, messages, behavior, and AI settings. Lightweight and easy to install — runs on PHP with SQLite.

## Features

### Chatbot Widget
*   **AI Integration:** Connects to [OpenRouter.ai](https://openrouter.ai/) to leverage various AI models (including free ones).
*   **Contextual Conversations:** Uses a system prompt (built from your Knowledge Base and Bot Rules) and conversation history for more relevant AI responses.
*   **Session-Based History:** Remembers the current conversation within a user's session.
*   **Persistent Storage (Optional):** Save all conversations to the SQLite database.
*   **Customizable Appearance:**
    *   Chat title and welcome message.
    *   Display your logo in the chat header.
    *   Custom colors for various chat elements (configurable via admin panel).
*   **User & Bot Avatars:** Customizable icons for user and bot.
*   **Notification Sound:** Plays a sound when the chat opens (can be customized and muted).
*   **Markdown Support:** Bot messages are rendered from Markdown to HTML (supports bold, italics, lists, links, code blocks).
*   **Embeddable:** Easily embeddable into any HTML page using a single JavaScript file (`embed.js`).

### Admin Panel
*   **Secure Access:** Login system with hashed password storage. First-time setup for admin user.
*   **Dashboard Tabs:**
    *   **Settings:**
        *   Interface Language: Select admin panel language (supports English, Italian, Spanish, French, German, Portuguese via gettext).
        *   Chatbot Basics: Configure chat title, welcome message.
        *   API Configuration: Enter OpenRouter.ai API Key and select the desired AI model (fetches available models from OpenRouter).
        *   Knowledge Base: Define the information the chatbot should know (textarea with character/token counter).
        *   Bot Rules: Set behavioral guidelines for the chatbot (textarea with character/token counter).
    *   **Messages:**
        *   Toggle conversation saving to the database.
        *   View saved conversations (includes Conversation ID, User IP, Timestamp, Sender, and Message content).
        *   Delete all saved messages.
        *   Export messages in TXT, CSV, HTML, or Markdown (MD) formats.
    *   **Style:**
        *   Customize colors for header, chat area background, user/bot message bubbles (background and text), and send button (background and text).
        *   Live preview of style changes.
    *   **Images:**
        *   Upload custom images (PNG, GIF, JPG - max 2MB) for:
            *   Chat open icon
            *   Chat closed icon
            *   Bot avatar
            *   User avatar
            *   Company/Site Logo
        *   Toggle visibility of the logo in the chat header.
    *   **Sounds:**
        *   Upload a custom notification sound (MP3 - max 2MB).
        *   Enable/disable the notification sound.
    *   **Guide:**
        *   Instructions on how to embed the chatbot.
        *   Basic explanation of how the chatbot works.
        *   Suggested templates for Knowledge Base and Bot Rules.
        *   Common troubleshooting tips.
    *   **License:**
        *   Displays the software license information.

### General
*   **Database:** Uses SQLite 3 for all data storage (settings, styles, messages, admin users).
*   **Self-Hosted:** Full control over your data and chatbot instance.
*   **Internationalization (i18n):**
    *   The admin panel and login page are translatable using `gettext`.
    *   Language preference is stored in the database.
    *   Requires `.po` and `.mo` files in the `/languages` directory.
*   **Security:**
    *   Admin authentication with password hashing (PASSWORD_DEFAULT).
    *   Output escaping (`htmlspecialchars`) to prevent XSS.
    *   MIME type and file size validation for uploads.
    *   DOMPurify for sanitizing Markdown-rendered HTML from bot messages.

## Technology Stack

*   **Backend:** PHP (7.x or higher recommended)
*   **Database:** SQLite 3
*   **Frontend:** HTML, CSS, Vanilla JavaScript
*   **AI Provider:** [OpenRouter.ai](https://openrouter.ai/)
*   **External JS Libraries (CDN):**
    *   [Marked.js](https://marked.js.org/) (for Markdown parsing)
    *   [DOMPurify](https://github.com/cure53/DOMPurify) (for HTML sanitization)

## Prerequisites

*   A web server with PHP support (e.g., Apache, Nginx).
*   PHP version 7.0 or newer.
*   PHP extensions:
    *   `sqlite3`
    *   `session`
    *   `json`
    *   `fileinfo`
    *   `mbstring`
    *   `gettext` (for internationalization)
*   Write permissions for the web server on the chatbot's root directory (for `db.db` creation) and the `images/`, `sounds/` subdirectories.

## Installation & Setup

1.  **Download/Clone:**
    *   Download the latest release or clone the repository:
        ```bash
        git clone https://github.com/NewCodeByte/chatbot-ai-free-models/
        ```
    *   Place the `chatbot` folder into your web server's document root (e.g., `/var/www/html/chatbot` or `htdocs/chatbot`).

2.  **Permissions:**
    *   Ensure your web server has write permissions for the main chatbot directory (to create/write to `db.db`).
    *   Ensure your web server has write permissions for the `images/` and `sounds/` subdirectories if you plan to upload custom assets.
        ```bash
        # Example (adjust user/group as needed, e.g., www-data)
        # sudo chown -R www-data:www-data /path/to/your/chatbot
        sudo chmod -R 755 /path/to/your/chatbot
        sudo chmod 664 /path/to/your/chatbot/db.db  # If it exists, or parent dir writeable
        sudo chmod 775 /path/to/your/chatbot/images
        sudo chmod 775 /path/to/your/chatbot/sounds
        ```
    *   The `db.db` file will be created automatically when you first access `login.php` or `admin.php`.

3.  **Admin Setup:**
    *   Open your web browser and navigate to `http://your-domain.com/path-to-chatbot/login.php`.
    *   If no admin user exists, you will be prompted to create one. Follow the on-screen instructions.
    *   Once created, log in with your new admin credentials.

4.  **Configure Settings:**
    *   In the admin panel, navigate to the "Settings" tab.
    *   Enter your **OpenRouter.ai API Key**. You can get one from [OpenRouter.ai](https://openrouter.ai/).
    *   Select an AI model (free models are available).
    *   Fill in the "Knowledge Base" and "Bot Rules" to customize your chatbot's behavior and information source.
    *   Configure other settings as needed (chat title, welcome message, etc.).

5.  **Internationalization (Optional):**
    *   The application uses `gettext` for translations.
    *   Translation files (`.po`, `.mo`) should be placed in the `/languages/{locale_code}/LC_MESSAGES/` directory. For example, for US English: `/languages/en_US.UTF-8/LC_MESSAGES/chatbot.mo`.
    *   The text domain is `chatbot`.
    *   You can use a tool like Poedit to create/edit `.po` files and compile them to `.mo` files.
    *   Supported languages by default (can be extended): English, Italian, Spanish, French, German, Portuguese.

## Embedding the Chatbot

To add the chatbot to any page on your website:

1.  Include the following JavaScript snippet in your HTML, preferably before the closing `</body>` tag:

    ```html
    <script src="/path-to-chatbot/embed.js" defer></script>
    ```

2.  **Important:** Replace `/path-to-chatbot/` with the actual URL path where you installed the chatbot files on your server.
    *   For example, if the chatbot is in `https://example.com/my-chatbot/`, the script src would be `/my-chatbot/embed.js`.
    *   If it's in the root, it might be `/embed.js`.

3.  The `embed.js` script will automatically create the chat button and the iframe-based chat window on the page. It uses the settings and styles configured in the admin panel.

## Configuration

Most configuration is done through the **Admin Panel**. Key settings include:

*   **API Key & Model:** Essential for the AI to function.
*   **Knowledge Base:** The core information your bot will use.
*   **Bot Rules:** How your bot should behave and respond.
*   **Styling:** Customize the look and feel.
*   **Language:** Set the admin panel and default chatbot language.

## License

This software is provided under a custom license:

*   **Free to Use:** You can use, copy, modify, merge, publish, and distribute the software for any purpose, including commercial.
*   **Attribution Required:** A visible link to [https://newcodebyte.altervista.org](https://newcodebyte.altervista.org) must be maintained in the footer of the chatbot interface at all times.
*   **Link Removal (Optional):** The footer link can be removed only after a one-time donation of at least $10 USD via [Buy Me A Coffee](https://buymeacoffee.com/codebytewp). Unauthorized removal is a license violation.
*   **No Warranty:** The software is provided "as is" without any warranty. The author is not liable for any claims or damages.
*   This license notice must be included in all copies or substantial portions of the Software.

Please refer to the `LICENSE.txt` file (if included in the repository) or the "License" tab in the admin panel for the full terms.

## Author

Developed by **NewCodeByte**.

*   Website: [https://newcodebyte.altervista.org](https://newcodebyte.altervista.org)
*   Contact: [software_on_demand@yahoo.it](mailto:software_on_demand@yahoo.it)

## Contributing

Contributions are welcome! If you'd like to contribute, please:

1.  Fork the repository.
2.  Create a new branch for your feature or bug fix.
3.  Make your changes.
4.  Test thoroughly.
5.  Submit a pull request with a clear description of your changes.

## Demo e Screenshot

Here are some main screenshots of the chatbot and the admin panel:

![Widget Integrato](Screenshots/Screenshot%201.png)
![Login Admin](Screenshots/Screenshot%202.png)
![Dashboard Admin](Screenshots/Screenshot%203.png)
![Impostazioni Generali](Screenshots/Screenshot%204.png)
![Gestione Messaggi](Screenshots/Screenshot%205.png)
![Stile Personalizzabile](Screenshots/Screenshot%206.png)
![Gestione Immagini](Screenshots/Screenshot%207.png)
![Suoni Personalizzati](Screenshots/Screenshot%208.png)
![Guida Integrata](Screenshots/Screenshot%209.png)