<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════════════╗
 * ║   MagicFit - Dashboard Scénarios Chatbot v1.0                               ║
 * ║                                                                              ║
 * ║   Interface admin pour gérer les intentions et réponses du chatbot          ║
 * ║   Les modifications sont appliquées IMMÉDIATEMENT (stockées en BDD)         ║
 * ╚══════════════════════════════════════════════════════════════════════════════╝
 */

if (!defined('ABSPATH')) exit;

// ============================================
// CRÉATION DES TABLES
// ============================================

function mf_scenarios_create_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    
    // Table des intentions
    $table_intentions = $wpdb->prefix . 'mf_intentions';
    $sql1 = "CREATE TABLE IF NOT EXISTS $table_intentions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(50) NOT NULL UNIQUE,
        nom VARCHAR(100) NOT NULL,
        emoji VARCHAR(10) DEFAULT '💬',
        needs_club TINYINT(1) DEFAULT 0,
        response_sans_club TEXT,
        response_avec_club TEXT,
        boutons TEXT,
        notes TEXT,
        priority INT DEFAULT 10,
        is_active TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) $charset;";
    
    // Table des mots-clés
    $table_keywords = $wpdb->prefix . 'mf_keywords';
    $sql2 = "CREATE TABLE IF NOT EXISTS $table_keywords (
        id INT AUTO_INCREMENT PRIMARY KEY,
        intention_code VARCHAR(50) NOT NULL,
        keyword VARCHAR(100) NOT NULL,
        variantes TEXT,
        priority INT DEFAULT 10,
        is_active TINYINT(1) DEFAULT 1,
        UNIQUE KEY unique_keyword (keyword)
    ) $charset;";
    
    // Table des corrections forcées (déjà existante, on la garde)
    $table_force = $wpdb->prefix . 'mf_force_responses';
    $sql3 = "CREATE TABLE IF NOT EXISTS $table_force (
        id INT AUTO_INCREMENT PRIMARY KEY,
        keyword VARCHAR(100) NOT NULL UNIQUE,
        intention VARCHAR(50) NOT NULL,
        response TEXT NOT NULL,
        needs_club TINYINT(1) DEFAULT 0,
        priority INT DEFAULT 10,
        is_active TINYINT(1) DEFAULT 1
    ) $charset;";
    
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql1);
    dbDelta($sql2);
    dbDelta($sql3);
    
    // Insérer les données par défaut
    mf_scenarios_insert_defaults();
}

function mf_scenarios_insert_defaults() {
    global $wpdb;
    $table = $wpdb->prefix . 'mf_intentions';
    
    // Vérifier si déjà rempli
    $count = $wpdb->get_var("SELECT COUNT(*) FROM $table");
    if ($count > 0) return;
    
    $intentions = array(
        array('SALUTATION', 'Salutation', '👋', 0, 
              "Salut ! 👋 Comment je peux t'aider ?\n\nJe peux te renseigner sur :\n• 📅 Planning des cours\n• 💰 Tarifs et abonnements\n• 🎯 Séance d'essai gratuite\n• 📍 Trouver un club\n\nDis-moi ton **code postal** pour commencer !",
              '', '', 'Menu principal', 100),
        array('AIDE', 'Aide', '💪', 0,
              "Je suis là pour t'aider ! 💪\n\nTu peux me demander :\n• Les **tarifs** de ton club\n• Les **horaires** d'ouverture\n• Le **planning** des cours\n• Comment **réserver** une séance d'essai\n• Comment **résilier** ou **suspendre**\n\n📍 Donne-moi ton **code postal** pour des infos personnalisées !",
              '', '', 'Menu aide', 90),
        array('TARIFS', 'Tarifs', '💰', 1,
              "💰 Pour les tarifs, dis-moi ton **code postal** !",
              "💰 **Tarifs {club}**\n\nDécouvre nos formules sans engagement !",
              'Voir les tarifs', '', 80),
        array('HORAIRES', 'Horaires', '🕐', 1,
              "🕐 Pour les horaires, dis-moi ton **code postal** !",
              "🕐 **Horaires {club}**\n\n{horaires_semaine}",
              '', 'Affiche planning semaine', 80),
        array('PLANNING', 'Planning', '📅', 1,
              "📅 Pour le planning, dis-moi ton **code postal** !",
              "📅 **Planning {club}**\n\nConsulte le planning complet des cours :",
              'Voir le planning', '', 80),
        array('SEANCE_ESSAI', 'Séance d\'essai', '🎯', 1,
              "🎯 Pour réserver une séance d'essai, dis-moi ton **code postal** !",
              "🎯 **Séance d'essai gratuite à {club}** !\n\nViens découvrir notre club sans engagement.",
              'Réserver ma séance', 'Gratuit', 85),
        array('INSCRIPTION', 'Inscription', '📝', 1,
              "📝 Pour t'inscrire, dis-moi ton **code postal** !",
              "📝 **Inscription {club}**\n\nRejoins-nous ! Abonnement sans engagement.",
              'M\'inscrire', '', 80),
        array('ACTIVITES', 'Activités / Musculation', '💪', 1,
              "💪 Tu veux des infos sur les équipements ?\n\n📍 Dis-moi ton **code postal** !",
              "💪 **Espace musculation {club}**\n\nNotre espace musculation comprend :\n• Machines guidées\n• Poids libres et haltères\n• Espace squat et deadlift\n• Zone fonctionnelle",
              'Réserver une séance', '', 80),
        array('COURS_COLLECTIFS', 'Cours collectifs', '🏋️', 1,
              "🏋️ On propose +50 cours collectifs !\n\n📍 Dis-moi ton **code postal** pour voir le planning !",
              "🏋️ **Cours collectifs {club}**\n\nPlus de 50 cours par semaine inclus dans ton abonnement !\n\n• 💃 Zumba, Step, Dance\n• 🧘 Yoga, Pilates, Stretching\n• 🔥 HIIT, Cross Training\n• 🚴 Biking, RPM\n• 🏋️ BodyPump, Renfo",
              'Voir le planning', '', 80),
        array('CONTACT', 'Contact', '📍', 1,
              "📞 Pour contacter un club, dis-moi ton **code postal** !",
              "📍 **{club}**\n\n**Adresse** : {adresse}\n**Téléphone** : {telephone}\n**Email** : {email}\n\nQue veux-tu savoir ? 💪",
              '', '', 70),
        array('RESILIATION', 'Résiliation', '📋', 1,
              "📋 Pour résilier, dis-moi ton **code postal** ou le nom de ton club !",
              "📋 **Résiliation {club}**\n\n**C'est simple :**\n• Connecte-toi à ton espace membre\n• Va dans \"Abonnement\" puis \"Résilier\"\n• Préavis de 30 jours\n• Zéro frais de résiliation !",
              'Espace membre|https://member.magicfit.fr/,Contacter le club|{contact_url}', 'Préavis 30 jours', 90),
        array('SUSPENSION', 'Suspension', '⏸️', 1,
              "⏸️ Pour suspendre ton abonnement, dis-moi ton **code postal** !",
              "⏸️ **Suspension {club}**\n\nTu peux mettre ton abonnement en pause (1 à 3 mois selon ta formule).\n\n**Comment faire :**\n• Via ton espace membre\n• Ou contacte l'accueil du club",
              'Espace membre|https://member.magicfit.fr/,Contacter le club|{contact_url}', '1-3 mois selon formule', 90),
        array('RETRACTATION', 'Rétractation', '📋', 0,
              "📋 **Droit de rétractation**\n\nTu as 14 jours après ton inscription en ligne pour te rétracter.\n\n• Sans frais\n• Sans justification\n• Remboursement sous 14 jours",
              '', 'Formulaire de rétractation|https://www.magicfit.fr/retractation-dabonnement/', '14 jours', 80),
        array('PARRAINAGE', 'Parrainage', '🎁', 1,
              "🎁 Pour le parrainage, dis-moi ton **code postal** !",
              "🎁 **Parrainage {club}**\n\nParraine un ami et profitez tous les deux d'avantages !\n\nRenseigne-toi à l'accueil du club.",
              '', '', 70),
        array('PAIEMENT', 'Paiement', '💳', 1,
              "💳 Pour les questions de paiement, dis-moi ton **code postal** !",
              "💳 **Paiement {club}**\n\nPour toute question sur ton paiement, contacte le club :",
              'Espace membre|https://member.magicfit.fr/,Contacter le club|{contact_url}', '', 80),
        array('FRANCHISE', 'Franchise', '🏢', 0,
              "🏢 **Devenir franchisé MagicFit**\n\nTu veux ouvrir ta propre salle de sport ?\n\nDécouvre le concept MagicFit et rejoins notre réseau !",
              '', 'En savoir plus|https://www.magicfit.fr/franchise/', '', 60),
        array('RECRUTEMENT', 'Recrutement', '💼', 0,
              "💼 **Rejoins l'équipe MagicFit !**\n\nOn recrute des passionnés de fitness !",
              '', 'Postuler|https://www.magicfit.fr/nous-contacter__trashed/contact-recrutement/', '', 60),
        array('LOCALISATION', 'Localisation', '📍', 1,
              "📍 Dis-moi ton **code postal** pour trouver ton club !",
              "📍 **{club}**\n\n**Adresse** : {adresse}\n**Téléphone** : {telephone}\n\nQue veux-tu savoir ? (tarifs, horaires, planning...) 💪",
              '', '', 70),
        array('GENERAL', 'Général', '💬', 0,
              "Je peux t'aider avec :\n\n• 📅 Planning des cours\n• 💰 Tarifs et abonnements\n• 🎯 Séance d'essai gratuite\n• 📍 Trouver un club\n\n📍 Dis-moi ton **code postal** pour des infos personnalisées !",
              '', '', 'Réponse par défaut', 10),
    );
    
    foreach ($intentions as $i) {
        $wpdb->insert($table, array(
            'code' => $i[0],
            'nom' => $i[1],
            'emoji' => $i[2],
            'needs_club' => $i[3],
            'response_sans_club' => $i[4],
            'response_avec_club' => $i[5],
            'boutons' => $i[6],
            'notes' => $i[7],
            'priority' => $i[8],
            'is_active' => 1
        ));
    }
    
    // Mots-clés
    $table_kw = $wpdb->prefix . 'mf_keywords';
    $keywords = array(
        array('SALUTATION', 'bonjour', 'bnjr, bjr'),
        array('SALUTATION', 'salut', 'slt, slut'),
        array('SALUTATION', 'hello', 'helo'),
        array('SALUTATION', 'coucou', 'cc, cou'),
        array('SALUTATION', 'hey', 'hé'),
        array('SALUTATION', 'bonsoir', 'bsr'),
        array('AIDE', 'aide', 'aidez-moi, aidez moi'),
        array('AIDE', 'help', 'halp'),
        array('TARIFS', 'tarifs', 'tarif, tarrif, tarrifs'),
        array('TARIFS', 'prix', 'pris'),
        array('TARIFS', 'abonnement', 'abonement, abonnment'),
        array('TARIFS', 'combien', 'cb'),
        array('HORAIRES', 'horaires', 'horaire, horraires'),
        array('HORAIRES', 'heures', 'heure'),
        array('HORAIRES', 'ouverture', 'ouvert'),
        array('PLANNING', 'planning', 'planing'),
        array('SEANCE_ESSAI', 'essai', 'essais, esssai'),
        array('SEANCE_ESSAI', 'tester', 'test'),
        array('INSCRIPTION', 'inscription', 'inscriptoin'),
        array('INSCRIPTION', 'inscrire', 'm\'inscrire, s\'inscrire'),
        array('ACTIVITES', 'musculation', 'musculatoin, muscul'),
        array('ACTIVITES', 'muscu', 'musku'),
        array('ACTIVITES', 'équipements', 'equipements, equipement'),
        array('COURS_COLLECTIFS', 'cours', 'cour'),
        array('COURS_COLLECTIFS', 'cours collectifs', 'cours colectifs, cours collectif'),
        array('COURS_COLLECTIFS', 'yoga', 'ioga'),
        array('COURS_COLLECTIFS', 'pilates', 'pilate'),
        array('COURS_COLLECTIFS', 'zumba', 'zomba'),
        array('COURS_COLLECTIFS', 'rpm', ''),
        array('COURS_COLLECTIFS', 'biking', 'byking'),
        array('CONTACT', 'contact', 'contacter'),
        array('CONTACT', 'telephone', 'tel, téléphone'),
        array('CONTACT', 'adresse', 'adress'),
        array('RESILIATION', 'résiliation', 'resiliation, resiliatoin, resilation, resilition, résilier'),
        array('RESILIATION', 'résilier', 'resilier, resiler, resiliatio, resillier, resilié'),
        array('SUSPENSION', 'suspension', 'suspention'),
        array('SUSPENSION', 'suspendre', 'suspendr'),
        array('RETRACTATION', 'rétractation', 'retractation'),
        array('PARRAINAGE', 'parrainage', 'parrainer'),
        array('PARRAINAGE', 'parrain', 'parin'),
        array('PAIEMENT', 'paiement', 'paiemant'),
        array('PAIEMENT', 'facture', 'factur'),
        array('PAIEMENT', 'prélèvement', 'prelevement'),
        array('FRANCHISE', 'franchise', 'franchisé'),
        array('RECRUTEMENT', 'emploi', 'emplois'),
        array('RECRUTEMENT', 'job', 'jobs'),
        array('RECRUTEMENT', 'recrutement', 'recrute'),
    );
    
    foreach ($keywords as $kw) {
        $wpdb->insert($table_kw, array(
            'intention_code' => $kw[0],
            'keyword' => $kw[1],
            'variantes' => $kw[2],
            'is_active' => 1
        ));
    }
}

// Créer les tables à l'init
add_action('admin_init', 'mf_scenarios_create_tables');

// ============================================
// MENU ADMIN
// ============================================

add_action('admin_menu', 'mf_scenarios_admin_menu', 50);

function mf_scenarios_admin_menu() {
    add_submenu_page(
        'magicfit',
        'Scénarios Chatbot',
        '🎯 Scénarios',
        'manage_options',
        'mf-scenarios',
        'mf_scenarios_admin_page'
    );
}

// ============================================
// PAGE ADMIN PRINCIPALE
// ============================================

function mf_scenarios_admin_page() {
    global $wpdb;
    
    $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'intentions';
    
    // Traitement des actions
    if (isset($_POST['mf_action']) && wp_verify_nonce($_POST['mf_nonce'], 'mf_scenarios_nonce')) {
        mf_scenarios_handle_action($_POST);
    }
    
    ?>
    <div class="wrap">
        <h1>🎯 Scénarios Chatbot MagicFit</h1>
        
        <?php if (isset($_GET['updated']) && $_GET['updated'] == '1'): ?>
            <div class="notice notice-success is-dismissible"><p>✅ Intention mise à jour avec succès !</p></div>
        <?php endif; ?>
        
        <!-- Onglets -->
        <nav class="nav-tab-wrapper">
            <a href="?page=mf-scenarios&tab=intentions" class="nav-tab <?php echo $tab === 'intentions' ? 'nav-tab-active' : ''; ?>">📋 Intentions</a>
            <a href="?page=mf-scenarios&tab=keywords" class="nav-tab <?php echo $tab === 'keywords' ? 'nav-tab-active' : ''; ?>">🔤 Mots-clés</a>
            <a href="?page=mf-scenarios&tab=corrections" class="nav-tab <?php echo $tab === 'corrections' ? 'nav-tab-active' : ''; ?>">🔧 Corrections</a>
            <a href="?page=mf-scenarios&tab=test" class="nav-tab <?php echo $tab === 'test' ? 'nav-tab-active' : ''; ?>">🧪 Tester</a>
        </nav>
        
        <div style="margin-top: 20px;">
        <?php
        switch ($tab) {
            case 'intentions':
                mf_scenarios_tab_intentions();
                break;
            case 'keywords':
                mf_scenarios_tab_keywords();
                break;
            case 'corrections':
                mf_scenarios_tab_corrections();
                break;
            case 'test':
                mf_scenarios_tab_test();
                break;
        }
        ?>
        </div>
    </div>
    
    <style>
        .mf-card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .mf-card h3 { margin-top: 0; }
        .mf-badge { display: inline-block; padding: 3px 8px; border-radius: 4px; font-size: 12px; }
        .mf-badge-green { background: #d4edda; color: #155724; }
        .mf-badge-red { background: #f8d7da; color: #721c24; }
        .mf-badge-blue { background: #cce5ff; color: #004085; }
        .mf-textarea { width: 100%; min-height: 100px; }
        .mf-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; }
        .mf-intention-card { border-left: 4px solid #4472C4; }
        .mf-intention-card.needs-club { border-left-color: #28a745; }
        .mf-emoji { font-size: 24px; margin-right: 10px; }
    </style>
    <?php
}

// ============================================
// ONGLET INTENTIONS
// ============================================

function mf_scenarios_tab_intentions() {
    global $wpdb;
    $table = $wpdb->prefix . 'mf_intentions';
    
    // Ajout d'une nouvelle intention
    if (isset($_GET['action']) && $_GET['action'] === 'new') {
        mf_scenarios_new_intention_form();
        return;
    }
    
    // Édition d'une intention
    if (isset($_GET['edit'])) {
        $id = intval($_GET['edit']);
        $intention = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
        if ($intention) {
            mf_scenarios_edit_intention_form($intention);
            return;
        }
    }
    
    // Liste des intentions
    $intentions = $wpdb->get_results("SELECT * FROM $table ORDER BY priority DESC, nom ASC");
    
    ?>
    <div class="mf-card">
        <h3>📋 Gestion des Intentions</h3>
        <p>Modifiez les réponses du chatbot pour chaque type de demande.</p>
        <p>
            <a href="?page=mf-scenarios&tab=intentions&action=new" class="button button-primary button-large">➕ Nouvelle intention</a>
        </p>
    </div>
    
    <div class="mf-grid">
    <?php foreach ($intentions as $i): ?>
        <div class="mf-card mf-intention-card <?php echo $i->needs_club ? 'needs-club' : ''; ?>">
            <h4>
                <span class="mf-emoji"><?php echo esc_html($i->emoji); ?></span>
                <?php echo esc_html($i->nom); ?>
                <code style="font-size: 11px; color: #666;"><?php echo esc_html($i->code); ?></code>
            </h4>
            
            <p>
                <?php if ($i->needs_club): ?>
                    <span class="mf-badge mf-badge-green">✅ Nécessite club</span>
                <?php else: ?>
                    <span class="mf-badge mf-badge-blue">🌐 Global</span>
                <?php endif; ?>
                
                <?php if ($i->is_active): ?>
                    <span class="mf-badge mf-badge-green">Actif</span>
                <?php else: ?>
                    <span class="mf-badge mf-badge-red">Inactif</span>
                <?php endif; ?>
            </p>
            
            <p><strong>Réponse sans club :</strong></p>
            <p style="font-size: 12px; background: #f5f5f5; padding: 10px; border-radius: 4px; max-height: 80px; overflow: hidden;">
                <?php echo nl2br(esc_html(substr($i->response_sans_club, 0, 150))); ?>...
            </p>
            
            <p>
                <a href="?page=mf-scenarios&tab=intentions&edit=<?php echo $i->id; ?>" class="button button-primary">✏️ Modifier</a>
                
                <form method="post" style="display: inline;">
                    <?php wp_nonce_field('mf_scenarios_nonce', 'mf_nonce'); ?>
                    <input type="hidden" name="mf_action" value="toggle_intention">
                    <input type="hidden" name="id" value="<?php echo $i->id; ?>">
                    <button type="submit" class="button"><?php echo $i->is_active ? '⏸️ Désactiver' : '▶️ Activer'; ?></button>
                </form>
                
                <form method="post" style="display: inline;">
                    <?php wp_nonce_field('mf_scenarios_nonce', 'mf_nonce'); ?>
                    <input type="hidden" name="mf_action" value="delete_intention">
                    <input type="hidden" name="id" value="<?php echo $i->id; ?>">
                    <button type="submit" class="button" onclick="return confirm('Supprimer cette intention ?');">🗑️</button>
                </form>
            </p>
        </div>
    <?php endforeach; ?>
    </div>
    <?php
}

function mf_scenarios_new_intention_form() {
    ?>
    <div class="mf-card">
        <h3>➕ Nouvelle intention</h3>
        
        <form method="post">
            <?php wp_nonce_field('mf_scenarios_nonce', 'mf_nonce'); ?>
            <input type="hidden" name="mf_action" value="add_intention">
            
            <table class="form-table">
                <tr>
                    <th>Code <span style="color:red;">*</span><br><small>(ex: PISCINE, SAUNA, COACH)</small></th>
                    <td><input type="text" name="code" required placeholder="NOUVEAU_CODE" style="text-transform: uppercase;" pattern="[A-Z0-9_]+" title="Lettres majuscules, chiffres et underscores uniquement"></td>
                </tr>
                <tr>
                    <th>Nom <span style="color:red;">*</span></th>
                    <td><input type="text" name="nom" required placeholder="ex: Piscine / Aquagym" class="regular-text"></td>
                </tr>
                <tr>
                    <th>Emoji</th>
                    <td><input type="text" name="emoji" value="💬" style="width: 60px;"></td>
                </tr>
                <tr>
                    <th>Nécessite un club ?</th>
                    <td>
                        <label><input type="checkbox" name="needs_club" value="1" checked> Oui, demander le code postal d'abord</label>
                    </td>
                </tr>
                <tr>
                    <th>Réponse SANS club <span style="color:red;">*</span><br><small>(quand on ne connaît pas encore le club)</small></th>
                    <td><textarea name="response_sans_club" class="mf-textarea" rows="4" required placeholder="Ex: 🏊 Pour les infos piscine, dis-moi ton **code postal** !"></textarea></td>
                </tr>
                <tr>
                    <th>Réponse AVEC club<br><small>(Variables: {club}, {adresse}, {telephone}, {email})</small></th>
                    <td><textarea name="response_avec_club" class="mf-textarea" rows="6" placeholder="Ex: 🏊 **Espace aquatique {club}**

Notre espace aquatique comprend :
• Piscine 25m
• Jacuzzi
• Hammam"></textarea></td>
                </tr>
                <tr>
                    <th>Boutons<br><small>(Format: Texte|URL, séparés par des virgules)</small></th>
                    <td>
                        <!-- Sélecteur d'URL avec 3 menus déroulants -->
                        <div class="mf-url-builder" style="background: #f9f9f9; padding: 15px; border-radius: 8px; margin-bottom: 10px; border: 1px solid #ddd;">
                            <p style="margin: 0 0 10px 0; font-weight: bold;">➕ Ajouter un bouton rapidement :</p>
                            
                            <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;">
                                <!-- Menu 1: Catégorie -->
                                <div style="flex: 1; min-width: 150px;">
                                    <label style="display: block; font-size: 12px; color: #666; margin-bottom: 3px;">📁 Catégorie</label>
                                    <select id="mf_url_category" class="mf-url-select" style="width: 100%;">
                                        <option value="">-- Choisir --</option>
                                        <option value="reservation">📅 Réservation / Essai</option>
                                        <option value="planning">📆 Planning</option>
                                        <option value="tarifs">💰 Tarifs</option>
                                        <option value="contact">📞 Contact</option>
                                        <option value="membre">👤 Espace membre</option>
                                        <option value="paiement">💳 Paiement</option>
                                        <option value="resiliation">📋 Résiliation</option>
                                        <option value="suspension">⏸️ Suspension</option>
                                        <option value="parrainage">🎁 Parrainage</option>
                                        <option value="retractation">↩️ Rétractation</option>
                                        <option value="activites">🏃 Activités</option>
                                        <option value="franchise">🚀 Franchise</option>
                                        <option value="recettes">🥗 Recettes</option>
                                        <option value="musculation">💪 Musculation</option>
                                        <option value="calculateurs">🧮 Calculateurs</option>
                                        <option value="recrutement">👔 Recrutement</option>
                                        <option value="presse">📰 Presse</option>
                                        <option value="clubs">🏋️ Clubs</option>
                                        <option value="custom">✏️ URL personnalisée</option>
                                    </select>
                                </div>
                                
                                <!-- Menu 2: Type -->
                                <div style="flex: 1; min-width: 150px;">
                                    <label style="display: block; font-size: 12px; color: #666; margin-bottom: 3px;">📋 Type</label>
                                    <select id="mf_url_type" class="mf-url-select" style="width: 100%;" disabled>
                                        <option value="">-- Choisir catégorie --</option>
                                    </select>
                                </div>
                                
                                <!-- Menu 3: Club (si nécessaire) -->
                                <div style="flex: 1; min-width: 150px;">
                                    <label style="display: block; font-size: 12px; color: #666; margin-bottom: 3px;">🏋️ Club</label>
                                    <select id="mf_url_club" class="mf-url-select" style="width: 100%;" disabled>
                                        <option value="">-- Tous (variable) --</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div style="display: flex; gap: 10px; margin-top: 10px; flex-wrap: wrap; align-items: flex-end;">
                                <!-- Texte du bouton -->
                                <div style="flex: 2; min-width: 200px;">
                                    <label style="display: block; font-size: 12px; color: #666; margin-bottom: 3px;">🏷️ Texte du bouton</label>
                                    <input type="text" id="mf_btn_text" placeholder="Ex: 📅 Réserver ma séance" style="width: 100%;">
                                </div>
                                
                                <!-- URL générée (lecture seule) -->
                                <div style="flex: 2; min-width: 200px;">
                                    <label style="display: block; font-size: 12px; color: #666; margin-bottom: 3px;">🔗 URL générée</label>
                                    <input type="text" id="mf_url_preview" readonly style="width: 100%; background: #e9e9e9;" placeholder="L'URL apparaîtra ici">
                                </div>
                                
                                <!-- Bouton Ajouter -->
                                <div>
                                    <button type="button" id="mf_add_btn" class="button button-primary" style="height: 30px;">➕ Ajouter</button>
                                </div>
                            </div>
                            
                            <!-- URL personnalisée (cachée par défaut) -->
                            <div id="mf_custom_url_wrapper" style="display: none; margin-top: 10px;">
                                <label style="display: block; font-size: 12px; color: #666; margin-bottom: 3px;">✏️ URL personnalisée</label>
                                <input type="text" id="mf_custom_url" placeholder="https://www.magicfit.fr/..." style="width: 100%;">
                            </div>
                        </div>
                        
                        <!-- Textarea pour les boutons -->
                        <textarea name="boutons" id="mf_boutons_textarea" class="mf-textarea" rows="2" placeholder="Ex: Réserver|{booking_url},Voir le planning|{planning_url}"></textarea>
                    </td>
                </tr>
                <tr>
                    <th>Mots-clés associés<br><small>(séparés par des virgules)</small></th>
                    <td><textarea name="keywords" class="mf-textarea" rows="2" placeholder="Ex: piscine, aquagym, aquabike, natation, nager"></textarea></td>
                </tr>
                <tr>
                    <th>Notes internes</th>
                    <td><input type="text" name="notes" class="regular-text" placeholder="Notes pour vous"></td>
                </tr>
                <tr>
                    <th>Priorité</th>
                    <td><input type="number" name="priority" value="50" min="1" max="100"> <small>(plus élevé = priorité haute)</small></td>
                </tr>
            </table>
            
            <p>
                <button type="submit" class="button button-primary button-large">💾 Créer l'intention</button>
                <a href="?page=mf-scenarios&tab=intentions" class="button">Annuler</a>
            </p>
        </form>
    </div>
    
    <div class="mf-card" style="background: #f0f8ff;">
        <h4>💡 Aide</h4>
        <p><strong>Variables disponibles dans les réponses :</strong></p>
        <ul>
            <li><code>{club}</code> → Nom du club (ex: Magicfit Maisons-Laffitte)</li>
            <li><code>{adresse}</code> → Adresse complète</li>
            <li><code>{telephone}</code> → Téléphone du club</li>
            <li><code>{email}</code> → Email du club</li>
            <li><code>{contact_url}</code> → URL du formulaire de contact</li>
            <li><code>{planning_url}</code> → URL du planning</li>
            <li><code>{booking_url}</code> → URL de réservation</li>
            <li><code>{horaires_semaine}</code> → Tous les horaires de la semaine</li>
        </ul>
    </div>
    <?php
}

function mf_scenarios_edit_intention_form($intention) {
    ?>
    <div class="mf-card">
        <h3>✏️ Modifier : <?php echo esc_html($intention->emoji . ' ' . $intention->nom); ?></h3>
        
        <form method="post">
            <?php wp_nonce_field('mf_scenarios_nonce', 'mf_nonce'); ?>
            <input type="hidden" name="mf_action" value="update_intention">
            <input type="hidden" name="id" value="<?php echo $intention->id; ?>">
            
            <table class="form-table">
                <tr>
                    <th>Code (non modifiable)</th>
                    <td><code><?php echo esc_html($intention->code); ?></code></td>
                </tr>
                <tr>
                    <th>Nom</th>
                    <td><input type="text" name="nom" value="<?php echo esc_attr($intention->nom); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th>Emoji</th>
                    <td><input type="text" name="emoji" value="<?php echo esc_attr($intention->emoji); ?>" style="width: 60px;"></td>
                </tr>
                <tr>
                    <th>Nécessite un club ?</th>
                    <td>
                        <label><input type="checkbox" name="needs_club" value="1" <?php checked($intention->needs_club, 1); ?>> Oui, demander le code postal d'abord</label>
                    </td>
                </tr>
                <tr>
                    <th>Réponse SANS club<br><small>(quand on ne connaît pas encore le club)</small></th>
                    <td><textarea name="response_sans_club" class="mf-textarea" rows="6"><?php echo esc_textarea($intention->response_sans_club); ?></textarea></td>
                </tr>
                <tr>
                    <th>Réponse AVEC club<br><small>(Variables: {club}, {adresse}, {telephone}, {email}, {contact_url})</small></th>
                    <td><textarea name="response_avec_club" class="mf-textarea" rows="6"><?php echo esc_textarea($intention->response_avec_club); ?></textarea></td>
                </tr>
                <tr>
                    <th>Boutons<br><small>(Format: Texte|URL, un par ligne)</small></th>
                    <td>
                        <!-- Sélecteur d'URL avec 3 menus déroulants -->
                        <div class="mf-url-builder" style="background: #f9f9f9; padding: 15px; border-radius: 8px; margin-bottom: 10px; border: 1px solid #ddd;">
                            <p style="margin: 0 0 10px 0; font-weight: bold;">➕ Ajouter un bouton rapidement :</p>
                            
                            <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;">
                                <!-- Menu 1: Catégorie -->
                                <div style="flex: 1; min-width: 150px;">
                                    <label style="display: block; font-size: 12px; color: #666; margin-bottom: 3px;">📁 Catégorie</label>
                                    <select id="mf_url_category" class="mf-url-select" style="width: 100%;">
                                        <option value="">-- Choisir --</option>
                                        <option value="reservation">📅 Réservation / Essai</option>
                                        <option value="planning">📆 Planning</option>
                                        <option value="tarifs">💰 Tarifs</option>
                                        <option value="contact">📞 Contact</option>
                                        <option value="membre">👤 Espace membre</option>
                                        <option value="paiement">💳 Paiement</option>
                                        <option value="resiliation">📋 Résiliation</option>
                                        <option value="suspension">⏸️ Suspension</option>
                                        <option value="parrainage">🎁 Parrainage</option>
                                        <option value="retractation">↩️ Rétractation</option>
                                        <option value="activites">🏃 Activités</option>
                                        <option value="franchise">🚀 Franchise</option>
                                        <option value="recettes">🥗 Recettes</option>
                                        <option value="musculation">💪 Musculation</option>
                                        <option value="calculateurs">🧮 Calculateurs</option>
                                        <option value="recrutement">👔 Recrutement</option>
                                        <option value="presse">📰 Presse</option>
                                        <option value="clubs">🏋️ Clubs</option>
                                        <option value="custom">✏️ URL personnalisée</option>
                                    </select>
                                </div>
                                
                                <!-- Menu 2: Type -->
                                <div style="flex: 1; min-width: 150px;">
                                    <label style="display: block; font-size: 12px; color: #666; margin-bottom: 3px;">📋 Type</label>
                                    <select id="mf_url_type" class="mf-url-select" style="width: 100%;" disabled>
                                        <option value="">-- Choisir catégorie --</option>
                                    </select>
                                </div>
                                
                                <!-- Menu 3: Club (si nécessaire) -->
                                <div style="flex: 1; min-width: 150px;">
                                    <label style="display: block; font-size: 12px; color: #666; margin-bottom: 3px;">🏋️ Club</label>
                                    <select id="mf_url_club" class="mf-url-select" style="width: 100%;" disabled>
                                        <option value="">-- Tous (variable) --</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div style="display: flex; gap: 10px; margin-top: 10px; flex-wrap: wrap; align-items: flex-end;">
                                <!-- Texte du bouton -->
                                <div style="flex: 2; min-width: 200px;">
                                    <label style="display: block; font-size: 12px; color: #666; margin-bottom: 3px;">🏷️ Texte du bouton</label>
                                    <input type="text" id="mf_btn_text" placeholder="Ex: 📅 Réserver ma séance" style="width: 100%;">
                                </div>
                                
                                <!-- URL générée (lecture seule) -->
                                <div style="flex: 2; min-width: 200px;">
                                    <label style="display: block; font-size: 12px; color: #666; margin-bottom: 3px;">🔗 URL générée</label>
                                    <input type="text" id="mf_url_preview" readonly style="width: 100%; background: #e9e9e9;" placeholder="L'URL apparaîtra ici">
                                </div>
                                
                                <!-- Bouton Ajouter -->
                                <div>
                                    <button type="button" id="mf_add_btn" class="button button-primary" style="height: 30px;">➕ Ajouter</button>
                                </div>
                            </div>
                            
                            <!-- URL personnalisée (cachée par défaut) -->
                            <div id="mf_custom_url_wrapper" style="display: none; margin-top: 10px;">
                                <label style="display: block; font-size: 12px; color: #666; margin-bottom: 3px;">✏️ URL personnalisée</label>
                                <input type="text" id="mf_custom_url" placeholder="https://www.magicfit.fr/..." style="width: 100%;">
                            </div>
                        </div>
                        
                        <!-- Textarea pour les boutons -->
                        <textarea name="boutons" id="mf_boutons_textarea" class="mf-textarea" rows="4" placeholder="Les boutons apparaîtront ici..."><?php echo esc_textarea($intention->boutons); ?></textarea>
                        
                        <p style="margin-top: 5px; color: #666; font-size: 12px;">
                            💡 Format: <code>Texte du bouton|URL</code> (un par ligne) • Variables: <code>{booking_url}</code>, <code>{planning_url}</code>, <code>{contact_url}</code>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>Notes internes</th>
                    <td><input type="text" name="notes" value="<?php echo esc_attr($intention->notes); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th>Priorité</th>
                    <td><input type="number" name="priority" value="<?php echo esc_attr($intention->priority); ?>" min="1" max="100"></td>
                </tr>
            </table>
            
            <p>
                <button type="submit" class="button button-primary button-large">💾 Enregistrer</button>
                <a href="?page=mf-scenarios&tab=intentions" class="button">Annuler</a>
            </p>
        </form>
    </div>
    <?php
}

// ============================================
// ONGLET MOTS-CLÉS
// ============================================

function mf_scenarios_tab_keywords() {
    global $wpdb;
    $table = $wpdb->prefix . 'mf_keywords';
    $table_intentions = $wpdb->prefix . 'mf_intentions';
    
    $keywords = $wpdb->get_results("
        SELECT k.*, i.nom as intention_nom, i.emoji 
        FROM $table k 
        LEFT JOIN $table_intentions i ON k.intention_code = i.code 
        ORDER BY k.intention_code, k.keyword
    ");
    
    $intentions = $wpdb->get_results("SELECT code, nom FROM $table_intentions ORDER BY nom");
    
    ?>
    <div class="mf-card">
        <h3>➕ Ajouter un mot-clé</h3>
        <form method="post">
            <?php wp_nonce_field('mf_scenarios_nonce', 'mf_nonce'); ?>
            <input type="hidden" name="mf_action" value="add_keyword">
            
            <table class="form-table">
                <tr>
                    <th>Intention</th>
                    <td>
                        <select name="intention_code" required>
                            <?php foreach ($intentions as $i): ?>
                            <option value="<?php echo esc_attr($i->code); ?>"><?php echo esc_html($i->nom); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Mot-clé principal</th>
                    <td><input type="text" name="keyword" required placeholder="ex: musculation"></td>
                </tr>
                <tr>
                    <th>Variantes (facultatif)</th>
                    <td><input type="text" name="variantes" placeholder="ex: muscu, musculatoin"></td>
                </tr>
            </table>
            
            <p><button type="submit" class="button button-primary">➕ Ajouter</button></p>
        </form>
    </div>
    
    <div class="mf-card">
        <h3>🔤 Liste des mots-clés</h3>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th width="20%">Intention</th>
                    <th width="20%">Mot-clé</th>
                    <th width="35%">Variantes</th>
                    <th width="10%">Actif</th>
                    <th width="15%">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($keywords as $kw): ?>
                <tr>
                    <td><?php echo esc_html($kw->emoji . ' ' . $kw->intention_nom); ?></td>
                    <td><strong><?php echo esc_html($kw->keyword); ?></strong></td>
                    <td><small><?php echo esc_html($kw->variantes); ?></small></td>
                    <td><?php echo $kw->is_active ? '🟢' : '🔴'; ?></td>
                    <td>
                        <form method="post" style="display: inline;">
                            <?php wp_nonce_field('mf_scenarios_nonce', 'mf_nonce'); ?>
                            <input type="hidden" name="mf_action" value="delete_keyword">
                            <input type="hidden" name="id" value="<?php echo $kw->id; ?>">
                            <button type="submit" class="button button-small" onclick="return confirm('Supprimer ?');">🗑️</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// ============================================
// ONGLET CORRECTIONS
// ============================================

function mf_scenarios_tab_corrections() {
    global $wpdb;
    $table = $wpdb->prefix . 'mf_force_responses';
    
    $corrections = $wpdb->get_results("SELECT * FROM $table ORDER BY priority DESC, keyword ASC");
    
    ?>
    <div class="mf-card">
        <h3>➕ Ajouter une correction forcée</h3>
        <p>Les corrections forcées ont la priorité absolue et contournent le cache.</p>
        
        <form method="post">
            <?php wp_nonce_field('mf_scenarios_nonce', 'mf_nonce'); ?>
            <input type="hidden" name="mf_action" value="add_correction">
            
            <table class="form-table">
                <tr>
                    <th>Mot-clé exact</th>
                    <td><input type="text" name="keyword" required placeholder="ex: suspension"></td>
                </tr>
                <tr>
                    <th>Intention</th>
                    <td>
                        <select name="intention">
                            <option value="SUSPENSION">SUSPENSION</option>
                            <option value="RESILIATION">RESILIATION</option>
                            <option value="ACTIVITES">ACTIVITES</option>
                            <option value="COURS_COLLECTIFS">COURS_COLLECTIFS</option>
                            <option value="TARIFS">TARIFS</option>
                            <option value="HORAIRES">HORAIRES</option>
                            <option value="PLANNING">PLANNING</option>
                            <option value="SEANCE_ESSAI">SEANCE_ESSAI</option>
                            <option value="INSCRIPTION">INSCRIPTION</option>
                            <option value="CONTACT">CONTACT</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Réponse</th>
                    <td><textarea name="response" class="mf-textarea" required></textarea></td>
                </tr>
                <tr>
                    <th>Nécessite un club ?</th>
                    <td><label><input type="checkbox" name="needs_club" value="1" checked> Oui</label></td>
                </tr>
            </table>
            
            <p><button type="submit" class="button button-primary">➕ Ajouter</button></p>
        </form>
    </div>
    
    <div class="mf-card">
        <h3>🔧 Corrections actives</h3>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th width="15%">Mot-clé</th>
                    <th width="15%">Intention</th>
                    <th width="40%">Réponse</th>
                    <th width="10%">Club ?</th>
                    <th width="10%">Actif</th>
                    <th width="10%">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($corrections as $c): ?>
                <tr style="<?php echo $c->is_active ? '' : 'opacity: 0.5;'; ?>">
                    <td><strong><?php echo esc_html($c->keyword); ?></strong></td>
                    <td><code><?php echo esc_html($c->intention); ?></code></td>
                    <td style="font-size: 12px;"><?php echo nl2br(esc_html(substr($c->response, 0, 100))); ?>...</td>
                    <td><?php echo $c->needs_club ? '✅' : '❌'; ?></td>
                    <td><?php echo $c->is_active ? '🟢' : '🔴'; ?></td>
                    <td>
                        <form method="post" style="display: inline;">
                            <?php wp_nonce_field('mf_scenarios_nonce', 'mf_nonce'); ?>
                            <input type="hidden" name="mf_action" value="toggle_correction">
                            <input type="hidden" name="id" value="<?php echo $c->id; ?>">
                            <button type="submit" class="button button-small"><?php echo $c->is_active ? '⏸️' : '▶️'; ?></button>
                        </form>
                        <form method="post" style="display: inline;">
                            <?php wp_nonce_field('mf_scenarios_nonce', 'mf_nonce'); ?>
                            <input type="hidden" name="mf_action" value="delete_correction">
                            <input type="hidden" name="id" value="<?php echo $c->id; ?>">
                            <button type="submit" class="button button-small" onclick="return confirm('Supprimer ?');">🗑️</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// ============================================
// ONGLET TEST
// ============================================

function mf_scenarios_tab_test() {
    ?>
    <div class="mf-card">
        <h3>🧪 Tester le chatbot</h3>
        <p>Testez directement une requête pour voir comment le chatbot répondrait.</p>
        
        <form method="post">
            <?php wp_nonce_field('mf_scenarios_nonce', 'mf_nonce'); ?>
            <input type="hidden" name="mf_action" value="test_message">
            
            <p>
                <label><strong>Message :</strong></label><br>
                <input type="text" name="test_message" value="<?php echo esc_attr($_POST['test_message'] ?? ''); ?>" style="width: 400px;" placeholder="ex: musculation">
            </p>
            
            <p><button type="submit" class="button button-primary">🔍 Tester</button></p>
        </form>
        
        <?php if (isset($_POST['test_message']) && !empty($_POST['test_message'])): ?>
        <div style="margin-top: 20px; padding: 20px; background: #f0f0f0; border-radius: 8px;">
            <h4>Résultat :</h4>
            <?php
            $test_msg = sanitize_text_field($_POST['test_message']);
            if (function_exists('mf_process_message')) {
                $result = mf_process_message($test_msg, 'test_session');
                echo '<p><strong>Intention détectée :</strong> <code>' . esc_html($result['intention']) . '</code></p>';
                echo '<p><strong>Club ID :</strong> ' . ($result['club_id'] ?? 'Aucun') . '</p>';
                echo '<p><strong>Pending intention :</strong> ' . ($result['pending_intention'] ?? 'Aucune') . '</p>';
                echo '<p><strong>Réponse :</strong></p>';
                echo '<div style="background: white; padding: 15px; border-radius: 4px; border-left: 4px solid #4472C4;">';
                echo nl2br(esc_html($result['response']));
                echo '</div>';
            } else {
                echo '<p style="color: red;">⚠️ La fonction mf_process_message n\'est pas disponible.</p>';
            }
            ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

// ============================================
// TRAITEMENT DES ACTIONS
// ============================================

function mf_scenarios_handle_action($post) {
    global $wpdb;
    
    $action = $post['mf_action'] ?? '';
    
    switch ($action) {
        case 'add_intention':
            $code = strtoupper(sanitize_text_field($post['code']));
            $code = preg_replace('/[^A-Z0-9_]/', '', $code);
            
            // Vérifier si le code existe déjà
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}mf_intentions WHERE code = %s",
                $code
            ));
            
            if ($exists) {
                echo '<div class="notice notice-error"><p>❌ Ce code existe déjà !</p></div>';
                break;
            }
            
            $wpdb->insert(
                $wpdb->prefix . 'mf_intentions',
                array(
                    'code' => $code,
                    'nom' => sanitize_text_field($post['nom']),
                    'emoji' => sanitize_text_field($post['emoji'] ?: '💬'),
                    'needs_club' => isset($post['needs_club']) ? 1 : 0,
                    'response_sans_club' => wp_kses_post($post['response_sans_club']),
                    'response_avec_club' => wp_kses_post($post['response_avec_club']),
                    'boutons' => wp_kses_post($post['boutons']),
                    'notes' => sanitize_text_field($post['notes']),
                    'priority' => intval($post['priority']),
                    'is_active' => 1
                )
            );
            
            // Ajouter les mots-clés
            if (!empty($post['keywords'])) {
                $keywords = array_map('trim', explode(',', $post['keywords']));
                foreach ($keywords as $kw) {
                    if (!empty($kw)) {
                        $wpdb->insert(
                            $wpdb->prefix . 'mf_keywords',
                            array(
                                'intention_code' => $code,
                                'keyword' => sanitize_text_field($kw),
                                'variantes' => '',
                                'is_active' => 1
                            )
                        );
                    }
                }
            }
            
            echo '<div class="notice notice-success"><p>✅ Intention <strong>' . esc_html($code) . '</strong> créée avec succès !</p></div>';
            break;
            
        case 'delete_intention':
            $id = intval($post['id']);
            $intention = $wpdb->get_row($wpdb->prepare(
                "SELECT code FROM {$wpdb->prefix}mf_intentions WHERE id = %d",
                $id
            ));
            
            if ($intention) {
                // Supprimer les mots-clés associés
                $wpdb->delete($wpdb->prefix . 'mf_keywords', array('intention_code' => $intention->code));
                // Supprimer l'intention
                $wpdb->delete($wpdb->prefix . 'mf_intentions', array('id' => $id));
                echo '<div class="notice notice-success"><p>✅ Intention supprimée !</p></div>';
            }
            break;
        
        case 'update_intention':
            $result = $wpdb->update(
                $wpdb->prefix . 'mf_intentions',
                array(
                    'nom' => sanitize_text_field($post['nom']),
                    'emoji' => sanitize_text_field($post['emoji']),
                    'needs_club' => isset($post['needs_club']) ? 1 : 0,
                    'response_sans_club' => wp_kses_post($post['response_sans_club']),
                    'response_avec_club' => wp_kses_post($post['response_avec_club']),
                    'boutons' => wp_kses_post($post['boutons']),
                    'notes' => sanitize_text_field($post['notes']),
                    'priority' => intval($post['priority'])
                ),
                array('id' => intval($post['id'])),
                array('%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d'),
                array('%d')
            );
            
            if ($result === false) {
                echo '<div class="notice notice-error"><p>❌ Erreur SQL: ' . esc_html($wpdb->last_error) . '</p></div>';
            } elseif ($result === 0) {
                echo '<div class="notice notice-warning"><p>⚠️ Aucune modification détectée (ou données identiques)</p></div>';
            } else {
                // Rediriger vers le formulaire d'édition pour voir les changements
                $redirect_url = admin_url('admin.php?page=mf-scenarios&tab=intentions&edit=' . intval($post['id']) . '&updated=1');
                echo '<div class="notice notice-success"><p>✅ Intention mise à jour ! Redirection...</p></div>';
                echo '<script>window.location.href = "' . esc_url($redirect_url) . '";</script>';
            }
            break;
            
        case 'toggle_intention':
            $current = $wpdb->get_var($wpdb->prepare(
                "SELECT is_active FROM {$wpdb->prefix}mf_intentions WHERE id = %d",
                intval($post['id'])
            ));
            $wpdb->update(
                $wpdb->prefix . 'mf_intentions',
                array('is_active' => $current ? 0 : 1),
                array('id' => intval($post['id']))
            );
            echo '<div class="notice notice-success"><p>✅ Statut modifié !</p></div>';
            break;
            
        case 'add_keyword':
            $wpdb->insert(
                $wpdb->prefix . 'mf_keywords',
                array(
                    'intention_code' => sanitize_text_field($post['intention_code']),
                    'keyword' => sanitize_text_field($post['keyword']),
                    'variantes' => sanitize_text_field($post['variantes']),
                    'is_active' => 1
                )
            );
            echo '<div class="notice notice-success"><p>✅ Mot-clé ajouté !</p></div>';
            break;
            
        case 'delete_keyword':
            $wpdb->delete($wpdb->prefix . 'mf_keywords', array('id' => intval($post['id'])));
            echo '<div class="notice notice-success"><p>✅ Mot-clé supprimé !</p></div>';
            break;
            
        case 'add_correction':
            $wpdb->replace(
                $wpdb->prefix . 'mf_force_responses',
                array(
                    'keyword' => sanitize_text_field($post['keyword']),
                    'intention' => sanitize_text_field($post['intention']),
                    'response' => sanitize_textarea_field($post['response']),
                    'needs_club' => isset($post['needs_club']) ? 1 : 0,
                    'priority' => 100,
                    'is_active' => 1
                )
            );
            echo '<div class="notice notice-success"><p>✅ Correction ajoutée !</p></div>';
            break;
            
        case 'toggle_correction':
            $current = $wpdb->get_var($wpdb->prepare(
                "SELECT is_active FROM {$wpdb->prefix}mf_force_responses WHERE id = %d",
                intval($post['id'])
            ));
            $wpdb->update(
                $wpdb->prefix . 'mf_force_responses',
                array('is_active' => $current ? 0 : 1),
                array('id' => intval($post['id']))
            );
            echo '<div class="notice notice-success"><p>✅ Statut modifié !</p></div>';
            break;
            
        case 'delete_correction':
            $wpdb->delete($wpdb->prefix . 'mf_force_responses', array('id' => intval($post['id'])));
            echo '<div class="notice notice-success"><p>✅ Correction supprimée !</p></div>';
            break;
    }
}

// ============================================
// JAVASCRIPT POUR LE SÉLECTEUR D'URL
// ============================================

add_action('admin_footer', 'mf_scenarios_url_builder_js');

function mf_scenarios_url_builder_js() {
    // Seulement sur la page Scénarios
    if (!isset($_GET['page']) || $_GET['page'] !== 'mf-scenarios') return;
    
    // Récupérer les clubs pour le menu déroulant
    global $wpdb;
    $clubs = $wpdb->get_results("SELECT id, name, slug, city FROM {$wpdb->prefix}mf_clubs WHERE is_active = 1 ORDER BY name");
    ?>
    <script>
    (function() {
        // ==========================================
        // DONNÉES DES URLs
        // ==========================================
        
        const urlData = {
            // Réservation / Essai
            reservation: {
                label: '📅 Réservation / Essai',
                types: {
                    'variable': { label: '🔄 Variable (selon club)', url: '{booking_url}', text: '📅 Réserver', needsClub: false },
                    'page': { label: '📄 Page réservation', url: 'https://www.magicfit.fr/reservation-{slug}/', text: '📅 Réserver ma séance', needsClub: true },
                    'essai': { label: '🎯 Séance essai', url: 'https://www.magicfit.fr/reservation-{slug}/', text: '🎯 Essai gratuit', needsClub: true }
                }
            },
            
            // Planning
            planning: {
                label: '📆 Planning',
                types: {
                    'variable': { label: '🔄 Variable (selon club)', url: '{planning_url}', text: '📅 Voir le planning', needsClub: false },
                    'page': { label: '📄 Page planning', url: 'https://www.magicfit.fr/planning-{slug}/', text: '📅 Planning des cours', needsClub: true }
                }
            },
            
            // Tarifs
            tarifs: {
                label: '💰 Tarifs',
                types: {
                    'variable': { label: '🔄 Variable (selon club)', url: '{tarifs_url}', text: '💰 Voir les tarifs', needsClub: false },
                    'page': { label: '📄 Page tarifs', url: 'https://www.magicfit.fr/tarifs-{slug}/', text: '💰 Nos tarifs', needsClub: true }
                }
            },
            
            // Contact
            contact: {
                label: '📞 Contact',
                types: {
                    'variable': { label: '🔄 Variable (selon club)', url: '{contact_url}', text: '📞 Contacter le club', needsClub: false },
                    'page': { label: '📄 Page contact', url: 'https://www.magicfit.fr/contact-{slug}/', text: '📞 Nous contacter', needsClub: true },
                    'tel': { label: '📱 Téléphone', url: 'tel:{telephone}', text: '📞 Appeler', needsClub: false }
                }
            },
            
            // Espace membre
            membre: {
                label: '👤 Espace membre',
                types: {
                    'connexion': { label: '🔐 Connexion', url: 'https://member.magicfit.fr/', text: '👤 Espace membre', needsClub: false },
                    'inscription': { label: '📝 Inscription', url: 'https://member.magicfit.fr/register', text: '📝 Créer mon compte', needsClub: false },
                    'mdp_oublie': { label: '🔑 Mot de passe oublié', url: 'https://member.magicfit.fr/forgot-password', text: '🔑 Récupérer mot de passe', needsClub: false },
                    'mon_compte': { label: '👤 Mon compte', url: 'https://member.magicfit.fr/', text: '👤 Mon compte', needsClub: false }
                }
            },
            
            // Paiement
            paiement: {
                label: '💳 Paiement',
                types: {
                    'espace_membre': { label: '👤 Via Espace membre', url: 'https://member.magicfit.fr/', text: '💳 Gérer mon paiement', needsClub: false },
                    'contact': { label: '📞 Contacter le club', url: '{contact_url}', text: '📞 Contacter le club', needsClub: false },
                    'modifier_cb': { label: '💳 Modifier CB', url: 'https://member.magicfit.fr/', text: '💳 Modifier ma carte', needsClub: false },
                    'regulariser': { label: '💰 Régulariser impayé', url: 'https://member.magicfit.fr/', text: '💰 Régulariser', needsClub: false },
                    'factures': { label: '🧾 Mes factures', url: 'https://member.magicfit.fr/', text: '🧾 Voir mes factures', needsClub: false }
                }
            },
            
            // Résiliation
            resiliation: {
                label: '📋 Résiliation',
                types: {
                    'espace_membre': { label: '👤 Via Espace membre', url: 'https://member.magicfit.fr/', text: '📋 Gérer mon abonnement', needsClub: false },
                    'contact': { label: '📞 Contacter le club', url: '{contact_url}', text: '📞 Contacter le club', needsClub: false },
                    'formulaire': { label: '📝 Formulaire contact', url: 'https://www.magicfit.fr/contact-{slug}/', text: '📝 Demande de résiliation', needsClub: true }
                }
            },
            
            // Suspension
            suspension: {
                label: '⏸️ Suspension',
                types: {
                    'espace_membre': { label: '👤 Via Espace membre', url: 'https://member.magicfit.fr/', text: '⏸️ Suspendre mon abo', needsClub: false },
                    'contact': { label: '📞 Contacter le club', url: '{contact_url}', text: '📞 Contacter le club', needsClub: false }
                }
            },
            
            // Parrainage
            parrainage: {
                label: '🎁 Parrainage',
                types: {
                    'espace_membre': { label: '👤 Via Espace membre', url: 'https://member.magicfit.fr/', text: '🎁 Parrainer un ami', needsClub: false },
                    'info': { label: 'ℹ️ Infos parrainage', url: 'https://www.magicfit.fr/parrainage/', text: '🎁 Comment parrainer ?', needsClub: false }
                }
            },
            
            // Rétractation
            retractation: {
                label: '↩️ Rétractation',
                types: {
                    'formulaire': { label: '📝 Formulaire rétractation', url: 'https://www.magicfit.fr/retractation/', text: '↩️ Formulaire rétractation', needsClub: false },
                    'contact': { label: '📞 Contacter le club', url: '{contact_url}', text: '📞 Contacter le club', needsClub: false }
                }
            },
            
            // Activités
            activites: {
                label: '🏃 Activités',
                types: {
                    'planning': { label: '📅 Planning des cours', url: '{planning_url}', text: '📅 Voir le planning', needsClub: false },
                    'page_club': { label: '📄 Page du club', url: 'https://www.magicfit.fr/{slug}/', text: '🏋️ Découvrir le club', needsClub: true },
                    'musculation': { label: '💪 Musculation', url: 'https://www.magicfit.fr/tag/musculation/', text: '💪 Conseils muscu', needsClub: false },
                    'yoga': { label: '🧘 Yoga', url: '{planning_url}', text: '🧘 Cours de Yoga', needsClub: false },
                    'pilates': { label: '🤸 Pilates', url: '{planning_url}', text: '🤸 Cours de Pilates', needsClub: false },
                    'cycling': { label: '🚴 Cycling/Biking', url: '{planning_url}', text: '🚴 Cours de Cycling', needsClub: false },
                    'boxe': { label: '🥊 Boxe', url: '{planning_url}', text: '🥊 Cours de Boxe', needsClub: false },
                    'cross_training': { label: '🔥 Cross Training', url: '{planning_url}', text: '🔥 Cross Training', needsClub: false },
                    'aquagym': { label: '🏊 Aquagym', url: '{planning_url}', text: '🏊 Aquagym', needsClub: false },
                    'zumba': { label: '💃 Zumba', url: '{planning_url}', text: '💃 Cours de Zumba', needsClub: false }
                }
            },
            
            // Franchise
            franchise: {
                label: '🚀 Franchise',
                types: {
                    'page': { label: '📄 Page principale', url: 'https://www.magicfit.fr/franchise/', text: '🚀 Devenir franchisé', needsClub: false },
                    'simulateur': { label: '🧮 Simulateur profil', url: 'https://www.magicfit.fr/simulateur-de-profil-franchise/', text: '🧮 Tester mon profil', needsClub: false },
                    'articles': { label: '📰 Articles franchise', url: 'https://www.magicfit.fr/tag/franchise/', text: '📰 Actualités franchise', needsClub: false }
                }
            },
            
            // Recettes
            recettes: {
                label: '🥗 Recettes',
                types: {
                    'articles': { label: '📰 Articles recettes', url: 'https://www.magicfit.fr/tag/recettes-magicfit/', text: '🥗 Voir les recettes', needsClub: false }
                }
            },
            
            // Musculation
            musculation: {
                label: '💪 Musculation',
                types: {
                    'articles': { label: '📰 Articles musculation', url: 'https://www.magicfit.fr/tag/musculation/', text: '💪 Conseils muscu', needsClub: false }
                }
            },
            
            // Calculateurs
            calculateurs: {
                label: '🧮 Calculateurs',
                types: {
                    'articles': { label: '📰 Tous les calculateurs', url: 'https://www.magicfit.fr/tag/calculateurs-de-sports/', text: '🧮 Calculateurs', needsClub: false }
                }
            },
            
            // Recrutement
            recrutement: {
                label: '👔 Recrutement',
                types: {
                    'formulaire': { label: '📝 Formulaire candidature', url: 'https://www.magicfit.fr/nous-contacter__trashed/contact-recrutement/', text: '👔 Postuler', needsClub: false }
                }
            },
            
            // Presse
            presse: {
                label: '📰 Presse',
                types: {
                    'contact': { label: '📧 Contact presse', url: 'https://www.magicfit.fr/nous-contacter/contact-presse/', text: '📰 Contact presse', needsClub: false }
                }
            },
            
            // Clubs
            clubs: {
                label: '🏋️ Clubs',
                types: {
                    'liste': { label: '📋 Liste des clubs', url: 'https://www.magicfit.fr/nos-salles/', text: '🏋️ Nos clubs', needsClub: false },
                    'fiche': { label: '📄 Fiche club', url: 'https://www.magicfit.fr/{slug}/', text: '🏋️ Voir le club', needsClub: true },
                    'reservation_var': { label: '🔄 Réservation (variable)', url: '{reservation_url}', text: '🎯 Réserver ma séance', needsClub: false },
                    'inscription_var': { label: '🔄 Inscription (variable)', url: '{inscription_url}', text: '📝 M\'inscrire', needsClub: false },
                    'contact_var': { label: '🔄 Contact (variable)', url: '{contact_url}', text: '📞 Contacter le club', needsClub: false }
                }
            },
            
            // URL personnalisée
            custom: {
                label: '✏️ URL personnalisée',
                types: {
                    'custom': { label: '✏️ Saisir manuellement', url: '', text: '', needsClub: false, isCustom: true }
                }
            }
        };
        
        // Liste des clubs
        const clubs = <?php echo json_encode(array_map(function($c) { 
            return array('id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'city' => $c->city); 
        }, $clubs)); ?>;
        
        // ==========================================
        // ÉLÉMENTS DOM
        // ==========================================
        
        const categorySelect = document.getElementById('mf_url_category');
        const typeSelect = document.getElementById('mf_url_type');
        const clubSelect = document.getElementById('mf_url_club');
        const btnText = document.getElementById('mf_btn_text');
        const urlPreview = document.getElementById('mf_url_preview');
        const addBtn = document.getElementById('mf_add_btn');
        const textarea = document.getElementById('mf_boutons_textarea');
        const customUrlWrapper = document.getElementById('mf_custom_url_wrapper');
        const customUrlInput = document.getElementById('mf_custom_url');
        
        if (!categorySelect) return; // Pas sur la bonne page
        
        // ==========================================
        // ÉVÉNEMENTS
        // ==========================================
        
        // Changement de catégorie
        categorySelect.addEventListener('change', function() {
            const category = this.value;
            
            // Reset
            typeSelect.innerHTML = '<option value="">-- Choisir --</option>';
            typeSelect.disabled = !category;
            clubSelect.innerHTML = '<option value="">-- Tous (variable) --</option>';
            clubSelect.disabled = true;
            urlPreview.value = '';
            btnText.value = '';
            customUrlWrapper.style.display = 'none';
            
            if (!category || !urlData[category]) return;
            
            // Remplir les types
            const types = urlData[category].types;
            for (const key in types) {
                const opt = document.createElement('option');
                opt.value = key;
                opt.textContent = types[key].label;
                typeSelect.appendChild(opt);
            }
        });
        
        // Changement de type
        typeSelect.addEventListener('change', function() {
            const category = categorySelect.value;
            const typeKey = this.value;
            
            clubSelect.innerHTML = '<option value="">-- Tous (variable) --</option>';
            clubSelect.disabled = true;
            customUrlWrapper.style.display = 'none';
            
            if (!category || !typeKey || !urlData[category]) return;
            
            const typeData = urlData[category].types[typeKey];
            
            // URL personnalisée ?
            if (typeData.isCustom) {
                customUrlWrapper.style.display = 'block';
                urlPreview.value = '';
                btnText.value = '';
                return;
            }
            
            // Pré-remplir le texte du bouton
            btnText.value = typeData.text;
            
            // Besoin d'un club ?
            if (typeData.needsClub) {
                clubSelect.disabled = false;
                clubs.forEach(club => {
                    const opt = document.createElement('option');
                    opt.value = club.slug;
                    opt.textContent = club.name + ' (' + club.city + ')';
                    clubSelect.appendChild(opt);
                });
                urlPreview.value = typeData.url; // Afficher avec {slug}
            } else {
                urlPreview.value = typeData.url;
            }
        });
        
        // Changement de club
        clubSelect.addEventListener('change', function() {
            const category = categorySelect.value;
            const typeKey = typeSelect.value;
            const slug = this.value;
            
            if (!category || !typeKey || !urlData[category]) return;
            
            const typeData = urlData[category].types[typeKey];
            
            if (slug) {
                urlPreview.value = typeData.url.replace('{slug}', slug);
            } else {
                urlPreview.value = typeData.url;
            }
        });
        
        // URL personnalisée
        if (customUrlInput) {
            customUrlInput.addEventListener('input', function() {
                urlPreview.value = this.value;
            });
        }
        
        // Bouton Ajouter
        addBtn.addEventListener('click', function() {
            const text = btnText.value.trim();
            let url = urlPreview.value.trim();
            
            // Si URL personnalisée
            if (customUrlWrapper.style.display !== 'none' && customUrlInput) {
                url = customUrlInput.value.trim();
            }
            
            if (!text) {
                alert('⚠️ Veuillez saisir le texte du bouton');
                return;
            }
            if (!url) {
                alert('⚠️ Veuillez sélectionner ou saisir une URL');
                return;
            }
            
            // Ajouter au textarea
            const newLine = text + '|' + url;
            const current = textarea.value.trim();
            
            if (current) {
                textarea.value = current + '\n' + newLine;
            } else {
                textarea.value = newLine;
            }
            
            // Reset les champs
            categorySelect.value = '';
            typeSelect.innerHTML = '<option value="">-- Choisir catégorie --</option>';
            typeSelect.disabled = true;
            clubSelect.innerHTML = '<option value="">-- Tous (variable) --</option>';
            clubSelect.disabled = true;
            btnText.value = '';
            urlPreview.value = '';
            customUrlWrapper.style.display = 'none';
            if (customUrlInput) customUrlInput.value = '';
            
            // Feedback visuel
            textarea.style.backgroundColor = '#d4edda';
            setTimeout(() => { textarea.style.backgroundColor = ''; }, 500);
        });
        
    })();
    </script>
    
    <style>
    .mf-url-builder select,
    .mf-url-builder input[type="text"] {
        height: 32px;
        font-size: 13px;
    }
    .mf-url-builder .button {
        vertical-align: bottom;
    }
    @media (max-width: 782px) {
        .mf-url-builder > div {
            flex-direction: column;
        }
        .mf-url-builder > div > div {
            min-width: 100% !important;
        }
    }
    </style>
    <?php
}
