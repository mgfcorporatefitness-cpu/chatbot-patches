# MagicFit Chatbot Patches

🔧 Dépôt de patches automatiques pour le plugin MagicFit Corporate Fitness Chatbot.

## 🚀 Comment ça marche

1. **Auto-Repair** vérifie ce dépôt automatiquement
2. Si une nouvelle version est disponible, il télécharge les fichiers
3. Les patches sont appliqués instantanément
4. Zéro intervention manuelle !

## 📁 Structure

```
├── version.json          # Version actuelle et liste des fichiers
├── includes/
│   ├── mf-chat-handler.php
│   ├── mf-scenarios-dashboard.php
│   └── admin-auto-repair.php
└── README.md
```

## 📋 version.json

```json
{
    "version": "10.5.0",
    "files": [
        {"path": "includes/mf-chat-handler.php", "description": "..."},
        ...
    ]
}
```

## 🔄 Pour publier une mise à jour

1. Modifiez les fichiers PHP
2. Incrémentez la version dans `version.json`
3. Commit & Push sur la branche `main`
4. Auto-Repair détecte et applique automatiquement !

## ⚙️ Configuration Auto-Repair

Dans `admin-auto-repair.php` :
```php
define('MF_GITHUB_REPO', 'MagicFit/chatbot-patches');
define('MF_GITHUB_BRANCH', 'main');
```

---

**MagicFit** - Plugin chatbot pour salles de fitness
