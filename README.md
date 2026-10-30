# 🦜 Sekou — Assistant Vocal & Multimodal IA

Sekou est un assistant conversationnel moderne, réactif et multimodal propulsé par l'API **Google Gemini (Gemini 3.8 Flash)**, avec un backend en **PHP 8.2+ / PostgreSQL** et une interface **Vanilla JavaScript / CSS** moderne.


##  Fonctionnalités principales

- **Intelligence Artificielle de pointe** : Intégration de `gemini-3.8-flash` (avec secours automatique sur `gemini-3.1-flash-lite`), optimisé pour des réponses riches, structurées et complètes.
- **📎 Multimodal & Analyse de documents** : Bouton d'ajout de fichiers (`+`) permettant de joindre et analyser des images (`PNG`, `JPG`), des documents `PDF`, ou des fichiers texte (`TXT`, `MD`, `CSV`, `JSON`).
- **🎙️ Voix & Mode Mains Libres** :
  - Reconnaissance vocale (*Speech-to-Text*) via Web Speech API.
  - Synthèse vocale fluide (*Text-to-Speech*) des réponses de Sekou avec possibilité de l'activer/désactiver.
  - Mode mains libres continu.
- **💬 Gestion avancée des discussions** :
  - Panneau latéral rétractable (sur ordinateur et mobile).
  - Menu contextuel (`...`) pour chaque discussion : **Partager**, **Renommer**, **Épingler**, **Verrouiller**, **Archiver** et **Supprimer**.
- **🔐 Authentification sécurisée** :
  - Inscription et vérification d'adresse par code à 6 chiffres.
  - Réinitialisation de mot de passe sécurisée.
  - Protection contre les attaques par force brute (*Rate Limiting* en base de données).
  - Hachage de mot de passe moderne (`bcrypt`) et cookies de session protégés (`HttpOnly`, `SameSite`).
- **🗄️ Base de données PostgreSQL** : Schéma optimisé avec horodatages `TIMESTAMPTZ` et intégrité référentielle en cascade.

---

## 📁 Structure du projet

```text
AI_project/
├── Backend/
│   ├── auth.php            # API d'authentification (login, register, verify, reset, logout)
│   ├── bootstrap.php       # Initialisation CORS, session, connexion PDO PostgreSQL
│   ├── chat.php            # Relais API Gemini multimodal (texte + fichiers)
│   ├── config.php          # Configuration applicative (charge les variables .env)
│   ├── conversations.php   # Gestion des discussions (liste, renommage, suppression)
│   ├── history.php         # Historique des messages d'une discussion
│   ├── mailer.php          # Envoi des codes email (mode 'log' ou API 'brevo')
│   └── schema.sql          # Schéma de base de données PostgreSQL
├── Frontend/
│   ├── index.html          # Interface principale du chat
│   ├── login.html          # Page d'authentification et de vérification
│   ├── app.js              # Logique client du chat, reconnaissance vocale et uploads
│   ├── auth.js             # Logique d'authentification côté client
│   ├── style.css           # Thème principal (Dark / Light mode)
│   ├── auth.css            # Styles de la page de connexion
│   ├── logo.svg            # Logo Sekou complet
│   └── logo-mark.svg       # Icône Sekou
├── scripts/
│   ├── claim_conversations.php     # Rapatriement de conversations vers un compte
│   └── migrate_mysql_to_pgsql.php  # Script de migration depuis MySQL vers PostgreSQL
├── .env.example            # Gabarit de configuration des variables d'environnement
├── .gitignore              # Fichiers exclus de Git
└── README.md
```

---

## Installation & Démarrage

### 1. Prérequis
- **PHP 8.2+** avec extensions `pdo_pgsql`, `curl`, `mbstring`, `openssl`
- **PostgreSQL 14+**
- Une clé d'API **Google AI Studio** ([aistudio.google.com](https://aistudio.google.com/app/apikey))

### 2. Cloner le projet
```bash
git clone https://github.com/ahmad-mbengue/sekou-assistant.git
cd sekou-assistant
```

### 3. Configurer l'environnement
Copiez le fichier `.env.example` en `.env` :
```bash
cp .env.example .env
```
Éditez le fichier `.env` avec vos identifiants :
```dotenv
GEMINI_API_KEY=votre_cle_gemini_ici
GEMINI_MODEL=gemini-3.8-flash
GEMINI_FALLBACK_MODEL=gemini-3.1-flash-lite

ALLOWED_ORIGIN=http://localhost:5500

# Base de données PostgreSQL
DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=assistant_ia
DB_USER=postgres
DB_PASS=votre_mot_de_passe_ici
```

### 4. Initialiser la base de données
Créez la base `assistant_ia` sur votre serveur PostgreSQL, puis exécutez le script SQL :
```bash
psql -U postgres -d assistant_ia -f Backend/schema.sql
```

### 5. Lancer l'application
- **Backend (PHP)** :
```bash
php -S localhost:8000 -t Backend
```
- **Frontend** :
Ouvrez le dossier `Frontend` avec un serveur local (ex. l'extension **Live Server** de VS Code sur le port `5500`, ou `npx serve Frontend -p 5500`).

---

## 🔒 Sécurité
- Les clés API et les mots de passe ne sont **jamais** versionnés sur Git grâce au fichier `.env` protégé.
- Toutes les requêtes SQL utilisent des requêtes préparées PDO contre les injections SQL.
- Les messages et pièces jointes sont assainis et protégés contre les failles XSS.

---

## Auteur
Développé avec passion par **[Ahmad At-Tijani MBENGUE](https://github.com/ahmad-mbengue)**.
Dédié aux étudiants et à la communauté de l'**ESP**.
