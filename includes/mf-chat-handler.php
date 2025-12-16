<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════════════╗
 * ║   MagicFit Chat Handler v10.2 - VARIABLES ÉTENDUES                          ║
 * ║                                                                              ║
 * ║   Toutes les réponses viennent de la base de données                        ║
 * ║   Modifications dans le Dashboard = Effet IMMÉDIAT                          ║
 * ║                                                                              ║
 * ║   v10.2 : Ajout {modes_paiement}, {activites}, {activites_liste}            ║
 * ╚══════════════════════════════════════════════════════════════════════════════╝
 */

if (!defined('ABSPATH')) exit;

define('MF_HANDLER_VERSION', '10.2.0');

// ============================================
// POINT D'ENTRÉE PRINCIPAL
// ============================================

function mf_process_message($message, $session_id = '', $context = array()) {
    global $wpdb;
    
    $message = trim($message);
    if (empty($message)) {
        return mf_response("👋 Comment puis-je t'aider ?", 'SALUTATION');
    }
    
    // ============================================
    // v10.3 - HOOK PRÉ-TRAITEMENT (Synonymes, Contexte)
    // ============================================
    if (has_filter('mf_pre_process_message')) {
        $enhanced = apply_filters('mf_pre_process_message', $message, $session_id, $context);
        if (is_array($enhanced)) {
            $message = $enhanced['message'] ?? $message;
            $context = $enhanced['context'] ?? $context;
        }
    }
    
    // Normaliser le message
    $msg_lower = mb_strtolower($message);
    $msg_clean = mf_normalize_text($msg_lower);
    
    // Récupérer le contexte
    $pending_intention = $context['pending_intention'] ?? null;
    $context_club_id = $context['club_id'] ?? null;
    
    // Clés pour la mémorisation (utilise options au lieu de transients pour éviter Redis)
    $club_key = 'mf_club_' . md5($session_id);
    $pending_key = 'mf_pending_' . md5($session_id);
    
    // Récupérer pending_intention depuis le serveur (BDD directe)
    if (empty($pending_intention) && !empty($session_id)) {
        $pending_intention = get_option($pending_key, null);
    }
    
    // Récupérer club_id mémorisé depuis le serveur (BDD directe, pas Redis)
    if (empty($context_club_id) && !empty($session_id)) {
        $context_club_id = get_option($club_key, null);
    }
    
    // ============================================
    // ÉTAPE 0 : DÉTECTIONS PRIORITAIRES (avant tout)
    // ============================================
    
    // CODE POSTAL - Détection prioritaire (5 chiffres)
    if (preg_match('/^\d{5}$/', $msg_clean)) {
        global $wpdb;
        
        // Chercher le club par code postal
        $club = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mf_clubs WHERE postal_code = %s AND is_active = 1 LIMIT 1",
            $msg_clean
        ));
        
        // Si pas de club exact, chercher le plus proche par rayon
        if (!$club) {
            $club = $wpdb->get_row($wpdb->prepare(
                "SELECT c.*, 
                    (SELECT data_value FROM {$wpdb->prefix}mf_club_data WHERE club_id = c.id AND data_key = 'coverage_radius') as radius
                FROM {$wpdb->prefix}mf_clubs c 
                WHERE c.is_active = 1 
                ORDER BY ABS(CAST(c.postal_code AS SIGNED) - %d) 
                LIMIT 1",
                intval($msg_clean)
            ));
        }
        
        if ($club) {
            // Sauvegarder le club_id pour la suite de la conversation (30 minutes)
            if (!empty($session_id)) {
                update_option('mf_club_' . md5($session_id), $club->id);
            }
            
            // Vérifier s'il y a une intention en attente
            $pending = null;
            if (!empty($session_id)) {
                $pending = get_option('mf_pending_' . md5($session_id), null);
                if ($pending) {
                    delete_option('mf_pending_' . md5($session_id));
                }
            }
            
            // Récupérer les URLs du club
            $inscription_url = '';
            $reservation_url = '';
            
            if (function_exists('mf_get_club_data')) {
                $inscription_url = mf_get_club_data($club->id, 'inscription_url', '');
                $reservation_url = mf_get_club_data($club->id, 'reservation_url', '');
            }
            
            // Nettoyer le nom du club (éviter "MagicFit Magicfit")
            $club_display_name = $club->name;
            if (stripos($club_display_name, 'magicfit') === 0) {
                $club_display_name = trim(substr($club_display_name, 8));
            }
            
            // Préparer les données pour les variables
            $club_data = array(
                'inscription_url' => $inscription_url,
                'reservation_url' => $reservation_url
            );
            
            // Si intention en attente, récupérer les données depuis mf_intentions
            if ($pending) {
                $intention_data = mf_get_intention_data($pending);
                
                if ($intention_data) {
                    // Utiliser la réponse AVEC club depuis les Scénarios
                    $response_text = $intention_data->response_avec_club;
                    
                    // Remplacer les variables
                    $response_text = str_replace('{club}', $club_display_name, $response_text);
                    $response_text = str_replace('{adresse}', $club->address ?? '', $response_text);
                    $response_text = str_replace('{telephone}', $club->phone ?? '', $response_text);
                    $response_text = str_replace('{email}', $club->email ?? '', $response_text);
                    $response_text = str_replace('{code_postal}', $club->postal_code ?? '', $response_text);
                    $response_text = str_replace('{ville}', $club->city ?? '', $response_text);
                    
                    // v10.2 - Modes de paiement (avec et sans underscore)
                    if (strpos($response_text, '{modes_paiement}') !== false || strpos($response_text, '{modespaiement}') !== false) {
                        $modes = mf_get_formatted_payment_methods($club->id);
                        $response_text = str_replace('{modes_paiement}', $modes, $response_text);
                        $response_text = str_replace('{modespaiement}', $modes, $response_text);
                    }
                    
                    // v10.2 - Activités
                    if (strpos($response_text, '{activites}') !== false) {
                        $activites = mf_get_formatted_activities($club->id, 'inline');
                        $response_text = str_replace('{activites}', $activites, $response_text);
                    }
                    
                    // Construire les boutons depuis les Scénarios
                    $buttons_html = '';
                    if (!empty($intention_data->boutons)) {
                        $buttons_html = mf_build_buttons_html($intention_data->boutons, $club, $club_data);
                    }
                    
                    // Si pas de boutons configurés, utiliser les boutons par défaut
                    if (empty($buttons_html)) {
                        $booking_url = !empty($reservation_url) ? $reservation_url : (!empty($inscription_url) ? $inscription_url : "https://inscription.magicfit.fr/");
                        
                        if ($pending === 'TARIFS') {
                            $tarifs_url = !empty($inscription_url) ? $inscription_url : $booking_url;
                            $buttons_html = "<div class=\"mf-booking-buttons\"><a href=\"{$tarifs_url}\" target=\"_blank\" class=\"mf-booking-btn\">💰 Voir les tarifs</a></div>";
                        } elseif ($pending === 'SEANCE_ESSAI' || $pending === 'INSCRIPTION') {
                            $buttons_html = "<div class=\"mf-booking-buttons\"><a href=\"{$booking_url}\" target=\"_blank\" class=\"mf-booking-btn\">🎯 Réserver ma séance</a></div>";
                        } elseif ($pending === 'PLANNING') {
                            $planning_url = "https://www.magicfit.fr/planning-{$club->slug}/";
                            $buttons_html = "<div class=\"mf-booking-buttons\"><a href=\"{$planning_url}\" target=\"_blank\" class=\"mf-booking-btn\">📅 Voir le planning</a></div>";
                        }
                    }
                    
                    return mf_response(
                        $response_text,
                        $pending,
                        $club->id,
                        null,
                        array('buttons' => $buttons_html)
                    );
                }
            }
            
            // Sinon, réponse générique LOCALISATION
            return mf_response(
                "📍 **MagicFit {$club_display_name}**\n\n📍 {$club->address}, {$club->postal_code} {$club->city}\n📞 {$club->phone}",
                'LOCALISATION',
                $club->id
            );
        }
        // Pas de club trouvé, continuer le flow normal
    }
    
    // MUSCULATION - Priorité absolue
    if ($msg_clean === 'musculation' || $msg_clean === 'muscu' || 
        strpos($msg_clean, 'perdre du poids') !== false ||
        strpos($msg_clean, 'maigrir') !== false ||
        strpos($msg_clean, 'mincir') !== false) {
        $intention_data = mf_get_intention_data('MUSCULATION');
        return mf_response(
            $intention_data ? $intention_data->response_sans_club : "💪 Conseils musculation ! Dis-moi ton code postal.",
            'MUSCULATION'
        );
    }
    
    // LOCALISATION - Noms de villes MagicFit (inclut "club X" et "X" seul)
    $club_cities = array('maisons-laffitte', 'maisons laffitte', 'maisonslaffitte', 
                         'mere', 'maintenon', 'serris', 'paris 15', 'auenheim', 
                         'marseille', 'agneaux', 'vertaizon', 'pont-du-chateau', 
                         'pont du chateau', 'macon');
    foreach ($club_cities as $city) {
        // Match "ville" ou "club ville" ou "salle ville"
        if (strpos($msg_clean, $city) !== false) {
            // Chercher le club correspondant
            global $wpdb;
            $club = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}mf_clubs WHERE LOWER(name) LIKE %s AND is_active = 1 LIMIT 1",
                '%' . $city . '%'
            ));
            if ($club) {
                // Sauvegarder le club_id pour la suite de la conversation (30 minutes)
                if (!empty($session_id)) {
                    update_option('mf_club_' . md5($session_id), $club->id);
                }
                
                // Nettoyer le nom du club (éviter "MagicFit Magicfit")
                $club_display_name = $club->name;
                if (stripos($club_display_name, 'magicfit') === 0) {
                    $club_display_name = trim(substr($club_display_name, 8));
                }
                
                $intention_data = mf_get_intention_data('LOCALISATION');
                return mf_response(
                    "📍 **MagicFit {$club_display_name}**\n\n" . ($club->address ?? '') . "\n📞 " . ($club->phone ?? ''),
                    'LOCALISATION',
                    $club->id
                );
            }
            return mf_response("📍 Dis-moi ton **code postal** pour trouver le club le plus proche !", 'LOCALISATION');
        }
    }
    
    // ============================================
    // ÉTAPE 1 : CORRECTIONS FORCÉES (BDD - priorité max)
    // ============================================
    $forced = mf_check_forced_response($msg_clean);
    if ($forced) {
        if ($forced['needs_club']) {
            if (!empty($session_id)) {
                update_option('mf_pending_' . md5($session_id), $forced['intention']);
            }
            return mf_response($forced['response'], $forced['intention'], null, $forced['intention']);
        }
        return mf_response($forced['response'], $forced['intention']);
    }
    
    // ============================================
    // ÉTAPE 2 : CHERCHER LE CLUB D'ABORD
    // ============================================
    $club_id = $context_club_id;
    $club = null;
    
    // Charger le club si on a un club_id mémorisé
    if ($club_id && !$club) {
        global $wpdb;
        $club = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mf_clubs WHERE id = %d AND is_active = 1",
            $club_id
        ));
    }
    
    // Si c'est un code postal
    if (preg_match('/^\d{5}$/', $message)) {
        $club = mf_find_club_by_postal($message);
        if ($club) {
            $club_id = $club->id;
        }
    }
    
    // v10.1 - TOUJOURS chercher un club dans le message, peu importe l'intention
    if (!$club_id) {
        $club = mf_find_club_by_name($msg_clean);
        if ($club) {
            $club_id = $club->id;
        }
    }
    
    // ============================================
    // ÉTAPE 3 : DÉTECTER L'INTENTION (depuis BDD)
    // ============================================
    $detection = mf_detect_intention_from_db($msg_clean, $msg_lower);
    $intention = $detection['intention'];
    $intention_data = $detection['data'] ?? null;
    
    // ============================================
    // ÉTAPE 3.5 : AUTO-CORRECTION TEMPS RÉEL
    // ============================================
    // Si l'intention est GENERAL, tenter une auto-correction
    if ($intention === 'GENERAL') {
        // Charger le système d'intelligence si pas encore fait
        $intelligence_file = dirname(__FILE__) . '/mf-intelligence.php';
        if (file_exists($intelligence_file) && !function_exists('mf_auto_correct_message')) {
            require_once $intelligence_file;
        }
        
        if (function_exists('mf_auto_correct_message')) {
            $correction = mf_auto_correct_message($msg_clean);
            
            if ($correction) {
                // Correction trouvée ! Utiliser la bonne intention
                $intention = $correction['intention'];
                $intention_data = mf_get_intention_data($intention);
                
                // Log pour suivi
                error_log("🔄 MagicFit Auto-Correct: '{$msg_clean}' → {$intention} (via '{$correction['original_keyword']}')");
            }
        }
    }
    
    // Si on a trouvé un club ET on avait une intention en attente, l'appliquer
    if ($club_id && $pending_intention) {
        $intention = $pending_intention;
        $intention_data = mf_get_intention_data($intention);
        if (!empty($session_id)) {
            delete_option('mf_pending_' . md5($session_id));
        }
    }
    
    // Si on a trouvé un club mais pas d'intention spécifique, c'est une demande de localisation
    if ($club_id && $intention === 'GENERAL') {
        $intention = 'LOCALISATION';
        $intention_data = mf_get_intention_data('LOCALISATION');
    }
    
    // Charger le club si on a l'ID mais pas l'objet
    if ($club_id && !$club) {
        $club = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mf_clubs WHERE id = %d AND is_active = 1",
            $club_id
        ));
    }
    
    // ============================================
    // ÉTAPE 4 : VÉRIFIER SI ON A BESOIN D'UN CLUB
    // ============================================
    if ($intention_data && $intention_data->needs_club && !$club_id) {
        if (!empty($session_id)) {
            update_option('mf_pending_' . md5($session_id), $intention);
        }
        return mf_response(
            $intention_data->response_sans_club,
            $intention,
            null,
            $intention
        );
    }
    
    // ============================================
    // ÉTAPE 5 : GÉNÉRER LA RÉPONSE (depuis BDD)
    // ============================================
    $response = mf_generate_response_from_db($intention, $intention_data, $club);
    
    // ============================================
    // ÉTAPE 6 : LOGGING & APPRENTISSAGE
    // ============================================
    // Logger la conversation pour analytics
    if (function_exists('mf_log_conversation')) {
        mf_log_conversation(array(
            'session_id' => $session_id,
            'message' => $message,
            'intention' => $intention,
            'club_id' => $club_id,
            'club_name' => $club ? $club->name : '',
            'response' => $response,
            'has_buttons' => strpos($response, 'mf-booking-btn') !== false
        ));
    }
    
    // Si toujours GENERAL après toutes les tentatives, apprendre pour le futur
    if ($intention === 'GENERAL' && function_exists('mf_learn_potential_typo')) {
        mf_learn_potential_typo($msg_clean);
    }
    
    return mf_response($response, $intention, $club_id);
}

// ============================================
// RÉCUPÉRER UNE INTENTION DEPUIS LA BDD
// ============================================

function mf_get_intention_data($code) {
    global $wpdb;
    $table = $wpdb->prefix . 'mf_intentions';
    
    // Vérifier si la table existe
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
        return null;
    }
    
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table WHERE code = %s AND is_active = 1",
        $code
    ));
}

// ============================================
// DÉTECTION D'INTENTION DEPUIS LA BDD
// ============================================

function mf_detect_intention_from_db($msg_clean, $msg_original) {
    global $wpdb;
    $table_kw = $wpdb->prefix . 'mf_keywords';
    $table_int = $wpdb->prefix . 'mf_intentions';
    
    // =============================================
    // PRIORITÉ ABSOLUE - Détections forcées (avant BDD)
    // =============================================
    
    // MUSCULATION - Priorité sur ACTIVITES
    if ($msg_clean === 'musculation' || $msg_clean === 'muscu' || 
        strpos($msg_clean, 'perdre du poids') !== false ||
        strpos($msg_clean, 'maigrir') !== false ||
        strpos($msg_clean, 'mincir') !== false) {
        return array('intention' => 'MUSCULATION', 'data' => mf_get_intention_data('MUSCULATION'));
    }
    
    // EQUIPEMENTS - Priorité sur ACTIVITES  
    if ($msg_clean === 'equipements' || $msg_clean === 'equipement' ||
        $msg_clean === 'machines' || $msg_clean === 'materiel') {
        return array('intention' => 'EQUIPEMENTS', 'data' => mf_get_intention_data('EQUIPEMENTS'));
    }
    
    // LOCALISATION - Noms de villes MagicFit (priorité sur COURS_COLLECTIFS)
    $club_cities = array('maisons-laffitte', 'maisons laffitte', 'maisonslaffitte', 
                         'mere', 'maintenon', 'serris', 'paris 15', 'auenheim', 
                         'marseille', 'agneaux', 'vertaizon', 'pont-du-chateau', 
                         'pont du chateau', 'macon');
    foreach ($club_cities as $city) {
        if (strpos($msg_clean, $city) !== false) {
            return array('intention' => 'LOCALISATION', 'data' => mf_get_intention_data('LOCALISATION'));
        }
    }
    
    // =============================================
    // Recherche normale en BDD
    // =============================================
    
    // Vérifier si les tables existent
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_kw'") !== $table_kw) {
        // Fallback vers détection classique si pas de tables
        return mf_detect_intention_fallback($msg_clean, $msg_original);
    }
    
    // Récupérer tous les mots-clés actifs
    $keywords = $wpdb->get_results("
        SELECT k.*, i.priority as intention_priority 
        FROM $table_kw k 
        JOIN $table_int i ON k.intention_code = i.code 
        WHERE k.is_active = 1 AND i.is_active = 1
        ORDER BY i.priority DESC, k.priority DESC
    ");
    
    $words = explode(' ', $msg_clean);
    $best_match = null;
    $best_priority = -1;
    
    foreach ($keywords as $kw) {
        $matched = false;
        
        // Match exact du mot-clé principal
        if ($msg_clean === $kw->keyword || strpos($msg_clean, $kw->keyword) !== false) {
            $matched = true;
        }
        
        // Match sur les variantes
        if (!$matched && !empty($kw->variantes)) {
            $variantes = array_map('trim', explode(',', $kw->variantes));
            foreach ($variantes as $v) {
                if (!empty($v) && ($msg_clean === $v || strpos($msg_clean, $v) !== false)) {
                    $matched = true;
                    break;
                }
            }
        }
        
        if ($matched && $kw->intention_priority > $best_priority) {
            $best_match = $kw;
            $best_priority = $kw->intention_priority;
        }
    }
    
    if ($best_match) {
        $intention_data = mf_get_intention_data($best_match->intention_code);
        return array(
            'intention' => $best_match->intention_code,
            'data' => $intention_data
        );
    }
    
    // v10.1 - Aucun match BDD → essayer le fallback (gère les fautes de frappe)
    $fallback = mf_detect_intention_fallback($msg_clean, $msg_original);
    if ($fallback['intention'] !== 'GENERAL') {
        // Le fallback a trouvé une intention !
        $fallback['data'] = mf_get_intention_data($fallback['intention']);
        return $fallback;
    }
    
    // Aucun match - retourner GENERAL
    $general_data = mf_get_intention_data('GENERAL');
    return array(
        'intention' => 'GENERAL',
        'data' => $general_data
    );
}

// ============================================
// FALLBACK SI PAS DE TABLES
// ============================================

function mf_detect_intention_fallback($msg_clean, $msg_original) {
    $words = explode(' ', trim($msg_clean));
    $word_count = count($words);
    
    if ($word_count <= 3) {
        // SUSPENSION - avec variantes de fautes de frappe
        if (preg_match('/^(suspension|suspendre|suspenssion|suspention|suspand|suspend)/', $msg_clean) ||
            strpos($msg_clean, 'suspendre') !== false ||
            strpos($msg_clean, 'suspen') !== false) {
            return array('intention' => 'SUSPENSION', 'data' => null);
        }
        
        // RÉSILIATION - avec variantes de fautes de frappe
        // resiliation, resilier, resiler, resilation, resiliatio, resilition, résilier, résiliation
        if (preg_match('/^(resili|résili|resila|resili|resiliat)/', $msg_clean) ||
            strpos($msg_clean, 'resilier') !== false ||
            strpos($msg_clean, 'resiliation') !== false ||
            strpos($msg_clean, 'resiler') !== false ||
            strpos($msg_clean, 'resilation') !== false ||
            strpos($msg_clean, 'resiliatio') !== false ||
            strpos($msg_clean, 'resilition') !== false ||
            strpos($msg_clean, 'annuler abonnement') !== false ||
            strpos($msg_clean, 'annuler mon abonnement') !== false ||
            strpos($msg_clean, 'arreter abonnement') !== false ||
            strpos($msg_clean, 'arreter mon abonnement') !== false) {
            return array('intention' => 'RESILIATION', 'data' => null);
        }
        
        // MUSCULATION
        if ($msg_clean === 'musculation' || $msg_clean === 'muscu' || $msg_clean === 'musculatio') {
            return array('intention' => 'MUSCULATION', 'data' => null);
        }
        
        // COURS COLLECTIFS - avec variantes
        if ($msg_clean === 'cours' || 
            strpos($msg_clean, 'cours co') !== false ||
            strpos($msg_clean, 'cour co') !== false ||
            strpos($msg_clean, 'collectif') !== false ||
            strpos($msg_clean, 'colectif') !== false) {
            return array('intention' => 'COURS_COLLECTIFS', 'data' => null);
        }
        
        // TARIFS - avec variantes
        if (in_array($msg_clean, array('tarifs', 'tarif', 'prix', 'abonnement', 'combien', 'tarrif', 'tarrifs', 'tariff'))) {
            return array('intention' => 'TARIFS', 'data' => null);
        }
        
        // HORAIRES - avec variantes
        if (in_array($msg_clean, array('horaires', 'horaire', 'heures', 'ouverture', 'horraire', 'horraires', 'oraire', 'oraires'))) {
            return array('intention' => 'HORAIRES', 'data' => null);
        }
        
        // PLANNING
        if ($msg_clean === 'planning' || $msg_clean === 'planing' || $msg_clean === 'plannings') {
            return array('intention' => 'PLANNING', 'data' => null);
        }
        
        // SEANCE_ESSAI - avec variantes
        if (preg_match('/^(seance|séance|essai|tester|decouvrir|découvrir|decouvert|test)/', $msg_clean) ||
            strpos($msg_clean, 'seance') !== false ||
            strpos($msg_clean, 'essai') !== false ||
            strpos($msg_clean, 'tester') !== false ||
            strpos($msg_clean, 'decouvrir') !== false ||
            strpos($msg_clean, 'essayer') !== false ||
            in_array($msg_clean, array('seance', 'séance', 'essai', 'test', 'tester', 'decouvrir', 'découvrir', 'essayer'))) {
            return array('intention' => 'SEANCE_ESSAI', 'data' => null);
        }
        
        // INSCRIPTION / RESERVATION - avec variantes
        if (preg_match('/^(inscri|reserver|réserver|reserv|inscription|abonner)/', $msg_clean) ||
            strpos($msg_clean, 'inscrire') !== false ||
            strpos($msg_clean, 'inscription') !== false ||
            strpos($msg_clean, 'reserver') !== false ||
            strpos($msg_clean, 'reservation') !== false ||
            strpos($msg_clean, 'abonner') !== false ||
            in_array($msg_clean, array('inscription', 'inscrire', 'reserver', 'réserver', 'reservation', 'réservation', 'abonner', "m'inscrire", "m'abonner"))) {
            return array('intention' => 'INSCRIPTION', 'data' => null);
        }
        
        // CONTACT
        if (in_array($msg_clean, array('contact', 'contacter', 'appeler', 'telephone', 'téléphone', 'tel', 'email', 'mail')) ||
            $msg_clean === 'contact') {
            return array('intention' => 'CONTACT', 'data' => null);
        }
        
        // COACHING / COACH PERSONNEL
        if (in_array($msg_clean, array('coach', 'coaching', 'coach personnel', 'coach perso', 'personal trainer', 'pt'))) {
            return array('intention' => 'COACHING', 'data' => null);
        }
        
        // EQUIPEMENTS
        if (in_array($msg_clean, array('equipements', 'equipement', 'machines', 'machine', 'materiel', 'matériel', 'appareils', 'appareil'))) {
            return array('intention' => 'EQUIPEMENTS', 'data' => null);
        }
        
        // LOCALISATION - Noms de villes seuls (clubs MagicFit)
        $club_cities = array('maisons-laffitte', 'maisons laffitte', 'maisonslaffitte', 'mere', 'méré', 
                             'maintenon', 'serris', 'paris', 'paris 15', 'auenheim', 'marseille', 
                             'agneaux', 'vertaizon', 'pont-du-chateau', 'pont du chateau', 'macon', 'mâcon');
        if (in_array($msg_clean, $club_cities)) {
            return array('intention' => 'LOCALISATION', 'data' => null);
        }
        
        // PAIEMENT
        if (in_array($msg_clean, array('paiement', 'payer', 'paye', 'cb', 'carte bancaire', 'prelevement', 'facture', 'reglement')) ||
            strpos($msg_clean, 'paiement') !== false ||
            strpos($msg_clean, 'payer') !== false) {
            return array('intention' => 'PAIEMENT', 'data' => null);
        }
        
        // RECETTES (mots courts + expressions)
        if (in_array($msg_clean, array('recette', 'recettes', 'cuisine', 'cuisiner')) ||
            strpos($msg_clean, 'recette') !== false) {
            return array('intention' => 'RECETTES', 'data' => null);
        }
        
        // SALUTATION (bonjour + au revoir + merci)
        if (in_array($msg_clean, array('bonjour', 'salut', 'hello', 'coucou', 'hey', 'bonsoir', 'au revoir', 'aurevoir', 'bye', 'a bientot', 'merci', 'merci beaucoup', 'thanks'))) {
            return array('intention' => 'SALUTATION', 'data' => null);
        }
    }
    
    // ==========================================
    // DÉTECTION POUR PHRASES PLUS LONGUES (> 3 mots)
    // ==========================================
    
    // SEANCE_ESSAI / RESERVATION - Phrases comme "je veux réserver une séance"
    if (strpos($msg_clean, 'reserver') !== false || 
        strpos($msg_clean, 'reservation') !== false ||
        strpos($msg_clean, 'essai') !== false ||
        strpos($msg_clean, 'seance') !== false ||
        strpos($msg_clean, 'tester') !== false ||
        strpos($msg_clean, 'decouvrir') !== false ||
        strpos($msg_clean, 'essayer') !== false) {
        
        // Si c'est une réservation/inscription
        if (strpos($msg_clean, 'reserver') !== false || strpos($msg_clean, 'reservation') !== false) {
            return array('intention' => 'INSCRIPTION', 'data' => null);
        }
        // Sinon c'est une séance d'essai
        return array('intention' => 'SEANCE_ESSAI', 'data' => null);
    }
    
    // TARIFS - Phrases comme "je voudrais connaître les tarifs"
    if (strpos($msg_clean, 'tarif') !== false || 
        strpos($msg_clean, 'prix') !== false ||
        strpos($msg_clean, 'combien') !== false ||
        strpos($msg_clean, 'cout') !== false ||
        strpos($msg_clean, 'abonnement') !== false) {
        return array('intention' => 'TARIFS', 'data' => null);
    }
    
    // HORAIRES - Phrases comme "quels sont vos horaires"
    if (strpos($msg_clean, 'horaire') !== false || 
        strpos($msg_clean, 'heure') !== false ||
        strpos($msg_clean, 'ouvert') !== false ||
        strpos($msg_clean, 'ferme') !== false) {
        return array('intention' => 'HORAIRES', 'data' => null);
    }
    
    // PLANNING - Phrases comme "je voudrais voir le planning"
    if (strpos($msg_clean, 'planning') !== false || 
        strpos($msg_clean, 'cours') !== false ||
        strpos($msg_clean, 'programme') !== false) {
        return array('intention' => 'PLANNING', 'data' => null);
    }
    
    // RESILIATION - Phrases comme "je voudrais résilier mon abonnement"
    if (strpos($msg_clean, 'resilier') !== false || 
        strpos($msg_clean, 'resiliation') !== false ||
        strpos($msg_clean, 'annuler') !== false ||
        strpos($msg_clean, 'arreter') !== false) {
        return array('intention' => 'RESILIATION', 'data' => null);
    }
    
    // SUSPENSION - Phrases comme "je voudrais suspendre mon abonnement"
    if (strpos($msg_clean, 'suspend') !== false || 
        strpos($msg_clean, 'pause') !== false ||
        strpos($msg_clean, 'geler') !== false) {
        return array('intention' => 'SUSPENSION', 'data' => null);
    }
    
    // CONTACT - Phrases comme "comment vous contacter"
    if (strpos($msg_clean, 'contact') !== false || 
        strpos($msg_clean, 'appeler') !== false ||
        strpos($msg_clean, 'telephone') !== false ||
        strpos($msg_clean, 'joindre') !== false) {
        return array('intention' => 'CONTACT', 'data' => null);
    }
    
    // COACHING - Coach personnel, personal trainer
    if (strpos($msg_clean, 'coach personnel') !== false ||
        strpos($msg_clean, 'coach perso') !== false ||
        strpos($msg_clean, 'personal trainer') !== false ||
        strpos($msg_clean, 'coaching prive') !== false ||
        strpos($msg_clean, 'entraineur personnel') !== false) {
        return array('intention' => 'COACHING', 'data' => null);
    }
    
    // EQUIPEMENTS - Machines, matériel, appareils
    if (strpos($msg_clean, 'equipement') !== false ||
        strpos($msg_clean, 'machine') !== false ||
        strpos($msg_clean, 'materiel') !== false ||
        strpos($msg_clean, 'appareil') !== false ||
        strpos($msg_clean, 'tapis de course') !== false ||
        strpos($msg_clean, 'velo elliptique') !== false) {
        return array('intention' => 'EQUIPEMENTS', 'data' => null);
    }
    
    // ==========================================
    // INTENTIONS GLOBALES (sans besoin de club)
    // ==========================================
    
    // MUSCULATION - Conseils muscu, exercices, programmes, perdre du poids
    if (strpos($msg_clean, 'muscul') !== false ||
        strpos($msg_clean, 'muscu') !== false ||
        strpos($msg_clean, 'exercice') !== false ||
        strpos($msg_clean, 'biceps') !== false ||
        strpos($msg_clean, 'triceps') !== false ||
        strpos($msg_clean, 'pectoraux') !== false ||
        strpos($msg_clean, 'abdos') !== false ||
        strpos($msg_clean, 'abdominaux') !== false ||
        strpos($msg_clean, 'jambes') !== false ||
        strpos($msg_clean, 'quadriceps') !== false ||
        strpos($msg_clean, 'fessiers') !== false ||
        strpos($msg_clean, 'dos') !== false ||
        strpos($msg_clean, 'epaules') !== false ||
        strpos($msg_clean, 'bras') !== false ||
        strpos($msg_clean, 'haltere') !== false ||
        strpos($msg_clean, 'poids') !== false ||
        strpos($msg_clean, 'squat') !== false ||
        strpos($msg_clean, 'developpe') !== false ||
        strpos($msg_clean, 'curl') !== false ||
        strpos($msg_clean, 'tirage') !== false ||
        strpos($msg_clean, 'rowing') !== false ||
        strpos($msg_clean, 'programme') !== false ||
        strpos($msg_clean, 'seche') !== false ||
        strpos($msg_clean, 'prise de masse') !== false ||
        strpos($msg_clean, 'perdre du poids') !== false ||
        strpos($msg_clean, 'maigrir') !== false ||
        strpos($msg_clean, 'mincir') !== false ||
        strpos($msg_clean, 'renforcement') !== false) {
        return array('intention' => 'MUSCULATION', 'data' => null);
    }
    
    // RECETTES - Recettes fitness, healthy, protéinées
    if (strpos($msg_clean, 'recette') !== false ||
        strpos($msg_clean, 'cuisine') !== false ||
        strpos($msg_clean, 'cuisiner') !== false ||
        strpos($msg_clean, 'preparer') !== false ||
        strpos($msg_clean, 'plat') !== false ||
        strpos($msg_clean, 'repas') !== false ||
        strpos($msg_clean, 'petit dejeuner') !== false ||
        strpos($msg_clean, 'dejeuner') !== false ||
        strpos($msg_clean, 'diner') !== false ||
        strpos($msg_clean, 'snack') !== false ||
        strpos($msg_clean, 'smoothie') !== false ||
        strpos($msg_clean, 'shake') !== false ||
        strpos($msg_clean, 'bowl') !== false ||
        strpos($msg_clean, 'salade') !== false ||
        strpos($msg_clean, 'pancake') !== false ||
        strpos($msg_clean, 'overnight') !== false ||
        strpos($msg_clean, 'porridge') !== false ||
        strpos($msg_clean, 'healthy') !== false ||
        strpos($msg_clean, 'manger') !== false) {
        return array('intention' => 'RECETTES', 'data' => null);
    }
    
    // NUTRITION - Conseils nutrition, régime, alimentation
    if (strpos($msg_clean, 'nutrition') !== false ||
        strpos($msg_clean, 'nutritio') !== false ||
        strpos($msg_clean, 'regime') !== false ||
        strpos($msg_clean, 'alimentation') !== false ||
        strpos($msg_clean, 'aliment') !== false ||
        strpos($msg_clean, 'manger') !== false ||
        strpos($msg_clean, 'proteine') !== false ||
        strpos($msg_clean, 'glucide') !== false ||
        strpos($msg_clean, 'lipide') !== false ||
        strpos($msg_clean, 'calorie') !== false ||
        strpos($msg_clean, 'macro') !== false ||
        strpos($msg_clean, 'vitamine') !== false ||
        strpos($msg_clean, 'complement') !== false ||
        strpos($msg_clean, 'supplement') !== false ||
        strpos($msg_clean, 'whey') !== false ||
        strpos($msg_clean, 'creatine') !== false ||
        strpos($msg_clean, 'bcaa') !== false ||
        strpos($msg_clean, 'maigrir') !== false ||
        strpos($msg_clean, 'perdre du poids') !== false ||
        strpos($msg_clean, 'grossir') !== false ||
        strpos($msg_clean, 'prendre du poids') !== false) {
        return array('intention' => 'NUTRITION', 'data' => null);
    }
    
    // CALCULATEURS - IMC, calories, 1RM, etc.
    if (strpos($msg_clean, 'calculer') !== false ||
        strpos($msg_clean, 'calcul') !== false ||
        strpos($msg_clean, 'calculateur') !== false ||
        strpos($msg_clean, 'calculatrice') !== false ||
        strpos($msg_clean, 'imc') !== false ||
        strpos($msg_clean, 'indice de masse') !== false ||
        strpos($msg_clean, 'img') !== false ||
        strpos($msg_clean, 'masse grasse') !== false ||
        strpos($msg_clean, '1rm') !== false ||
        strpos($msg_clean, 'rep max') !== false ||
        strpos($msg_clean, 'frequence cardiaque') !== false ||
        strpos($msg_clean, 'fcmax') !== false ||
        strpos($msg_clean, 'fc max') !== false ||
        strpos($msg_clean, 'metabolisme') !== false ||
        strpos($msg_clean, 'depense') !== false ||
        strpos($msg_clean, 'besoin calorique') !== false ||
        strpos($msg_clean, 'combien de calories') !== false) {
        return array('intention' => 'CALCULATEURS', 'data' => null);
    }
    
    // FRANCHISE - Ouvrir une salle, devenir franchisé
    if (strpos($msg_clean, 'franchise') !== false ||
        strpos($msg_clean, 'franchis') !== false ||
        strpos($msg_clean, 'ouvrir une salle') !== false ||
        strpos($msg_clean, 'ouvrir un club') !== false ||
        strpos($msg_clean, 'devenir partenaire') !== false ||
        strpos($msg_clean, 'investir') !== false ||
        strpos($msg_clean, 'investissement') !== false ||
        strpos($msg_clean, 'creer une salle') !== false ||
        strpos($msg_clean, 'monter une salle') !== false ||
        strpos($msg_clean, 'business') !== false) {
        return array('intention' => 'FRANCHISE', 'data' => null);
    }
    
    // RECRUTEMENT - Emploi, travailler chez MagicFit
    if (strpos($msg_clean, 'recrutement') !== false ||
        strpos($msg_clean, 'recrute') !== false ||
        strpos($msg_clean, 'emploi') !== false ||
        strpos($msg_clean, 'job') !== false ||
        strpos($msg_clean, 'poste') !== false ||
        strpos($msg_clean, 'travail') !== false ||
        strpos($msg_clean, 'travailler') !== false ||
        strpos($msg_clean, 'candidature') !== false ||
        strpos($msg_clean, 'cv') !== false ||
        strpos($msg_clean, 'coach') !== false ||
        strpos($msg_clean, 'embauche') !== false ||
        strpos($msg_clean, 'carriere') !== false) {
        return array('intention' => 'RECRUTEMENT', 'data' => null);
    }
    
    // PRESSE - Contact presse, médias
    if (strpos($msg_clean, 'presse') !== false ||
        strpos($msg_clean, 'journaliste') !== false ||
        strpos($msg_clean, 'media') !== false ||
        strpos($msg_clean, 'interview') !== false ||
        strpos($msg_clean, 'article') !== false ||
        strpos($msg_clean, 'reportage') !== false) {
        return array('intention' => 'PRESSE', 'data' => null);
    }
    
    // PARRAINAGE
    if (strpos($msg_clean, 'parrain') !== false ||
        strpos($msg_clean, 'filleul') !== false ||
        strpos($msg_clean, 'recommander') !== false ||
        strpos($msg_clean, 'parrainer') !== false ||
        strpos($msg_clean, 'cadeau') !== false ||
        strpos($msg_clean, 'reduction ami') !== false) {
        return array('intention' => 'PARRAINAGE', 'data' => null);
    }
    
    // COURS COLLECTIFS - Zumba, Body Pump, Yoga, etc.
    if (strpos($msg_clean, 'zumba') !== false ||
        strpos($msg_clean, 'body pump') !== false ||
        strpos($msg_clean, 'bodypump') !== false ||
        strpos($msg_clean, 'body attack') !== false ||
        strpos($msg_clean, 'body combat') !== false ||
        strpos($msg_clean, 'body balance') !== false ||
        strpos($msg_clean, 'rpm') !== false ||
        strpos($msg_clean, 'cycling') !== false ||
        strpos($msg_clean, 'spinning') !== false ||
        strpos($msg_clean, 'yoga') !== false ||
        strpos($msg_clean, 'pilates') !== false ||
        strpos($msg_clean, 'stretching') !== false ||
        strpos($msg_clean, 'step') !== false ||
        strpos($msg_clean, 'hiit') !== false ||
        strpos($msg_clean, 'cross training') !== false ||
        strpos($msg_clean, 'crossfit') !== false ||
        strpos($msg_clean, 'boxe') !== false ||
        strpos($msg_clean, 'boxing') !== false ||
        strpos($msg_clean, 'aquagym') !== false ||
        strpos($msg_clean, 'aquabike') !== false ||
        strpos($msg_clean, 'caf') !== false ||
        strpos($msg_clean, 'abdos fessiers') !== false) {
        return array('intention' => 'COURS_COLLECTIFS', 'data' => null);
    }
    
    return array('intention' => 'GENERAL', 'data' => null);
}

// ============================================
// GÉNÉRATION RÉPONSE DEPUIS BDD
// ============================================

function mf_generate_response_from_db($intention, $intention_data, $club = null) {
    global $wpdb;
    
    // Si pas de données d'intention, utiliser fallback
    if (!$intention_data) {
        return mf_generate_response_fallback($intention, $club);
    }
    
    // Récupérer les données du club
    $data = array();
    $club_status = 'ouvert'; // Par défaut
    
    if ($club) {
        $club_data = $wpdb->get_results($wpdb->prepare(
            "SELECT data_key, data_value FROM {$wpdb->prefix}mf_club_data WHERE club_id = %d",
            $club->id
        ));
        foreach ($club_data as $row) {
            $data[$row->data_key] = $row->data_value;
        }
        
        // v10.1 - Récupérer le statut du club
        $club_status = $data['club_status'] ?? 'ouvert';
    }
    
    // v10.1 - Si le club n'est PAS ouvert, générer une réponse spéciale
    if ($club && $club_status !== 'ouvert') {
        return mf_generate_response_club_special($club, $club_status, $data);
    }
    
    // Choisir la réponse appropriée
    if ($club && !empty($intention_data->response_avec_club)) {
        $response = $intention_data->response_avec_club;
    } else {
        $response = $intention_data->response_sans_club;
    }
    
    // Remplacer les variables
    if ($club) {
        $response = str_replace('{club}', $club->name, $response);
        $response = str_replace('{adresse}', $club->address . ', ' . $club->postal_code . ' ' . $club->city, $response);
        $response = str_replace('{telephone}', $club->phone ?? 'Non communiqué', $response);
        $response = str_replace('{email}', $club->email ?? 'Non communiqué', $response);
        
        // URLs
        $contact_url = $data['contact_form_url'] ?? home_url('/contact-' . $club->slug . '/');
        $response = str_replace('{contact_url}', $contact_url, $response);
        
        $planning_url = $data['planning_url'] ?? $club->planning_url ?? "https://www.magicfit.fr/planning-{$club->slug}/";
        $response = str_replace('{planning_url}', $planning_url, $response);
        
        $tarifs_url = $data['pricing_page_url'] ?? home_url('/tarifs-' . $club->slug . '/');
        $response = str_replace('{tarifs_url}', $tarifs_url, $response);
        
        $booking_url = $data['booking_page_url'] ?? home_url('/reservation-' . $club->slug . '/');
        $response = str_replace('{booking_url}', $booking_url, $response);
        
        // Horaires
        $horaires = mf_build_horaires_text($club, $data);
        $response = str_replace('{horaires_semaine}', $horaires, $response);
        
        // v10.2 - Modes de paiement (depuis Gestion Clubs > Tarifs)
        // Accepte {modes_paiement} ET {modespaiement} (underscore parfois supprimé)
        if (strpos($response, '{modes_paiement}') !== false || strpos($response, '{modespaiement}') !== false) {
            $modes_paiement = mf_get_formatted_payment_methods($club->id);
            $response = str_replace('{modes_paiement}', $modes_paiement, $response);
            $response = str_replace('{modespaiement}', $modes_paiement, $response);
        }
        
        // v10.2 - Activités (depuis Gestion Clubs > Activités)
        // Accepte avec et sans underscore
        if (strpos($response, '{activites}') !== false) {
            $activites = mf_get_formatted_activities($club->id, 'inline');
            $response = str_replace('{activites}', $activites, $response);
        }
        if (strpos($response, '{activites_liste}') !== false || strpos($response, '{activitesliste}') !== false) {
            $activites = mf_get_formatted_activities($club->id, 'list');
            $response = str_replace('{activites_liste}', $activites, $response);
            $response = str_replace('{activitesliste}', $activites, $response);
        }
        if (strpos($response, '{activites_count}') !== false || strpos($response, '{activitescount}') !== false) {
            $activites = mf_get_formatted_activities($club->id, 'count');
            $response = str_replace('{activites_count}', $activites, $response);
            $response = str_replace('{activitescount}', $activites, $response);
        }
    }
    
    // Ajouter les boutons
    if (!empty($intention_data->boutons)) {
        $response .= "\n\n" . mf_build_buttons_html($intention_data->boutons, $club, $data);
    }
    
    // Ajouter adresse à la fin si club
    if ($club && strpos($response, '📍') === false) {
        $response .= "\n\n📍 {$club->address}, {$club->postal_code} {$club->city}";
    }
    
    return $response;
}

// ============================================
// CONSTRUIRE LES BOUTONS HTML
// ============================================

function mf_build_buttons_html($boutons_str, $club = null, $data = array()) {
    $lines = array_filter(array_map('trim', explode(',', $boutons_str)));
    if (empty($lines)) return '';
    
    $html = '<div class="mf-booking-buttons">';
    
    foreach ($lines as $line) {
        $parts = explode('|', $line, 2);
        
        $text = trim($parts[0]);
        
        // v10.1 - Si pas d'URL, utiliser une URL par défaut selon le texte
        if (count($parts) < 2 || empty(trim($parts[1]))) {
            // Générer une URL par défaut selon le texte du bouton
            if ($club) {
                $text_lower = strtolower($text);
                if (strpos($text_lower, 'inscrire') !== false || strpos($text_lower, 'réserver') !== false || strpos($text_lower, 'reserver') !== false) {
                    $url = $data['booking_page_url'] ?? $club->booking_page_url ?? "https://www.magicfit.fr/reservation-{$club->slug}/";
                } elseif (strpos($text_lower, 'planning') !== false) {
                    $url = $data['planning_url'] ?? $club->planning_url ?? "https://www.magicfit.fr/planning-{$club->slug}/";
                } elseif (strpos($text_lower, 'tarif') !== false) {
                    $url = $data['pricing_page_url'] ?? "https://www.magicfit.fr/tarifs-{$club->slug}/";
                } elseif (strpos($text_lower, 'contact') !== false) {
                    $url = $data['contact_form_url'] ?? "https://www.magicfit.fr/contact-{$club->slug}/";
                } elseif (strpos($text_lower, 'espace') !== false || strpos($text_lower, 'membre') !== false) {
                    $url = "https://member.magicfit.fr/";
                } elseif (strpos($text_lower, 'appeler') !== false && !empty($club->phone)) {
                    $url = "tel:" . preg_replace('/\s+/', '', $club->phone);
                } else {
                    $url = "https://www.magicfit.fr/{$club->slug}/";
                }
            } else {
                $url = "https://www.magicfit.fr/";
            }
        } else {
            $url = trim($parts[1]);
        }
        
        // Remplacer les variables dans l'URL
        if ($club) {
            // URLs depuis les données du club
            $reservation_url = $data['reservation_url'] ?? '';
            $inscription_url = $data['inscription_url'] ?? '';
            $booking_url = !empty($reservation_url) ? $reservation_url : $inscription_url;
            if (empty($booking_url)) $booking_url = $data['booking_page_url'] ?? $club->booking_page_url ?? "https://www.magicfit.fr/reservation-{$club->slug}/";
            
            $url = str_replace('{contact_url}', $data['contact_form_url'] ?? "https://www.magicfit.fr/contact-{$club->slug}/", $url);
            $url = str_replace('{planning_url}', $data['planning_url'] ?? $club->planning_url ?? "https://www.magicfit.fr/planning-{$club->slug}/", $url);
            $url = str_replace('{tarifs_url}', !empty($inscription_url) ? $inscription_url : "https://www.magicfit.fr/tarifs-{$club->slug}/", $url);
            $url = str_replace('{booking_url}', $booking_url, $url);
            $url = str_replace('{reservation_url}', !empty($reservation_url) ? $reservation_url : $booking_url, $url);
            $url = str_replace('{inscription_url}', !empty($inscription_url) ? $inscription_url : $booking_url, $url);
        }
        
        // Ne pas générer de bouton si le texte est vide
        if (empty($text)) continue;
        
        $html .= '<a href="' . esc_url($url) . '" target="_blank" class="mf-booking-btn">' . esc_html($text) . '</a>';
    }
    
    $html .= '</div>';
    
    return $html;
}

// ============================================
// v10.1 - RÉPONSES SPÉCIALES SELON STATUT CLUB
// ============================================

function mf_generate_response_club_special($club, $status, $data = array()) {
    $name = $club->name;
    $city = $club->city;
    $address = $club->address . ', ' . $club->postal_code . ' ' . $club->city;
    
    // Date d'ouverture prévue si disponible
    $opening_date = $data['opening_date'] ?? $data['date_ouverture'] ?? '';
    
    switch ($status) {
        case 'projet':
            $response = "🚧 **{$name}** est un club en projet !\n\n";
            $response .= "📍 **Localisation prévue** : {$city}\n\n";
            
            if (!empty($opening_date)) {
                $response .= "📅 **Ouverture prévue** : {$opening_date}\n\n";
            }
            
            $response .= "Tu veux être informé de l'ouverture ? Laisse-nous tes coordonnées ! 📧\n\n";
            $response .= "En attendant, découvre nos autres clubs 💪";
            
            // Boutons
            $contact_url = $data['contact_form_url'] ?? "https://www.magicfit.fr/contact-{$club->slug}/";
            $response .= "\n\n<div class=\"mf-booking-buttons\">";
            $response .= "<a href=\"{$contact_url}\" target=\"_blank\" class=\"mf-booking-btn\">📧 Être informé de l'ouverture</a>";
            $response .= "<a href=\"https://www.magicfit.fr/nos-salles/\" target=\"_blank\" class=\"mf-booking-btn\">🏋️ Voir les autres clubs</a>";
            $response .= "</div>";
            break;
            
        case 'travaux':
            $response = "🔨 **{$name}** est actuellement en travaux !\n\n";
            $response .= "📍 {$address}\n\n";
            
            if (!empty($opening_date)) {
                $response .= "📅 **Réouverture prévue** : {$opening_date}\n\n";
            }
            
            $response .= "On prépare un super club pour toi ! Reviens vite nous voir 💪\n\n";
            $response .= "En attendant, tu peux profiter de nos autres clubs.";
            
            // Boutons
            $response .= "\n\n<div class=\"mf-booking-buttons\">";
            $response .= "<a href=\"https://www.magicfit.fr/nos-salles/\" target=\"_blank\" class=\"mf-booking-btn\">🏋️ Voir les autres clubs</a>";
            $response .= "</div>";
            break;
            
        case 'ferme':
        case 'fermé':
            $response = "😢 **{$name}** est malheureusement fermé.\n\n";
            $response .= "📍 {$address}\n\n";
            $response .= "Mais ne t'inquiète pas, on a d'autres clubs qui t'attendent ! 💪";
            
            // Boutons
            $response .= "\n\n<div class=\"mf-booking-buttons\">";
            $response .= "<a href=\"https://www.magicfit.fr/nos-salles/\" target=\"_blank\" class=\"mf-booking-btn\">🏋️ Trouver un autre club</a>";
            $response .= "</div>";
            break;
            
        case 'bientot':
        case 'prochainement':
            $response = "🎉 **{$name}** ouvre bientôt !\n\n";
            $response .= "📍 {$address}\n\n";
            
            if (!empty($opening_date)) {
                $response .= "📅 **Ouverture** : {$opening_date}\n\n";
            }
            
            $response .= "Inscris-toi pour profiter des offres de pré-ouverture ! 🎁";
            
            // Boutons
            $contact_url = $data['contact_form_url'] ?? "https://www.magicfit.fr/contact-{$club->slug}/";
            $response .= "\n\n<div class=\"mf-booking-buttons\">";
            $response .= "<a href=\"{$contact_url}\" target=\"_blank\" class=\"mf-booking-btn\">🎁 Offres pré-ouverture</a>";
            $response .= "</div>";
            break;
            
        default:
            // Statut inconnu - traiter comme ouvert
            $response = "📍 **{$name}**\n\n";
            $response .= "Adresse : {$address}\n";
            if (!empty($club->phone)) {
                $response .= "Téléphone : {$club->phone}\n";
            }
            $response .= "\nQue veux-tu savoir ? (tarifs, horaires, planning...) 💪";
            break;
    }
    
    return $response;
}

// ============================================
// CONSTRUIRE LE TEXTE DES HORAIRES
// ============================================

function mf_build_horaires_text($club, $data) {
    $jours = array(
        'schedule_monday' => 'Lundi',
        'schedule_tuesday' => 'Mardi', 
        'schedule_wednesday' => 'Mercredi',
        'schedule_thursday' => 'Jeudi',
        'schedule_friday' => 'Vendredi',
        'schedule_saturday' => 'Samedi',
        'schedule_sunday' => 'Dimanche'
    );
    
    $text = '';
    foreach ($jours as $key => $jour) {
        $h = $club->$key ?? ($data[$key] ?? '');
        if (!empty($h)) {
            $text .= "**{$jour}** : {$h}\n";
        }
    }
    
    return $text;
}

// ============================================
// FALLBACK RÉPONSES SI PAS DE BDD
// ============================================

function mf_generate_response_fallback($intention, $club = null) {
    $needs_club = array('TARIFS', 'HORAIRES', 'PLANNING', 'SEANCE_ESSAI', 'INSCRIPTION', 
                        'ACTIVITES', 'COURS_COLLECTIFS', 'CONTACT', 'RESILIATION', 'SUSPENSION', 
                        'PARRAINAGE', 'PAIEMENT', 'LOCALISATION');
    
    // v10.1 - Si on a un club, générer une réponse avec boutons
    if ($club && in_array($intention, $needs_club)) {
        // Récupérer les URLs depuis mf_club_data (clé/valeur)
        if (function_exists('mf_get_club_data')) {
            $booking_url = mf_get_club_data($club->id, 'inscription_url', '');
            $planning_url = mf_get_club_data($club->id, 'planning_url', '');
            $contact_url = mf_get_club_data($club->id, 'contact_form_url', '');
            $tarifs_url = mf_get_club_data($club->id, 'pricing_page_url', '');
        } else {
            $booking_url = '';
            $planning_url = '';
            $contact_url = '';
            $tarifs_url = '';
        }
        
        // Fallbacks si vide
        if (empty($booking_url)) $booking_url = "https://inscription.magicfit.fr/";
        if (empty($planning_url)) $planning_url = "https://www.magicfit.fr/planning-{$club->slug}/";
        if (empty($contact_url)) $contact_url = "https://www.magicfit.fr/contact-{$club->slug}/";
        if (empty($tarifs_url)) $tarifs_url = $booking_url; // Utiliser le lien d'inscription si pas de page tarifs
        
        $responses_with_club = array(
            'SEANCE_ESSAI' => array(
                'text' => "🎯 **Séance d'essai gratuite à {$club->name}** !\n\nViens découvrir notre club sans engagement.",
                'buttons' => "<div class=\"mf-booking-buttons\"><a href=\"{$booking_url}\" target=\"_blank\" class=\"mf-booking-btn\">🎯 Réserver ma séance</a></div>"
            ),
            'INSCRIPTION' => array(
                'text' => "📝 **Inscription {$club->name}**\n\nRejoins-nous ! Abonnement sans engagement.",
                'buttons' => "<div class=\"mf-booking-buttons\"><a href=\"{$booking_url}\" target=\"_blank\" class=\"mf-booking-btn\">📝 M'inscrire</a></div>"
            ),
            'TARIFS' => array(
                'text' => "💰 **Tarifs {$club->name}**\n\nDécouvre nos offres d'abonnement !",
                'buttons' => "<div class=\"mf-booking-buttons\"><a href=\"{$tarifs_url}\" target=\"_blank\" class=\"mf-booking-btn\">💰 Voir les tarifs</a></div>"
            ),
            'PLANNING' => array(
                'text' => "📅 **Planning {$club->name}**\n\nDécouvre tous nos cours collectifs !",
                'buttons' => "<div class=\"mf-booking-buttons\"><a href=\"{$planning_url}\" target=\"_blank\" class=\"mf-booking-btn\">📅 Voir le planning</a></div>"
            ),
            'CONTACT' => array(
                'text' => "📞 **Contact {$club->name}**\n\n📍 {$club->address}, {$club->postal_code} {$club->city}",
                'buttons' => "<div class=\"mf-booking-buttons\">" .
                    ($club->phone ? "<a href=\"tel:{$club->phone}\" class=\"mf-booking-btn\">📞 Appeler</a>" : "") .
                    "<a href=\"{$contact_url}\" target=\"_blank\" class=\"mf-booking-btn\">📧 Nous contacter</a></div>"
            ),
            'RESILIATION' => array(
                'text' => "📋 **Résiliation {$club->name}**\n\n**C'est simple :**\n• Connecte-toi à ton espace membre\n• Va dans \"Abonnement\" puis \"Résilier\"\n• Préavis de 30 jours\n• Zéro frais de résiliation !",
                'buttons' => "<div class=\"mf-booking-buttons\"><a href=\"https://member.magicfit.fr/\" target=\"_blank\" class=\"mf-booking-btn\">👤 Espace membre</a><a href=\"{$contact_url}\" target=\"_blank\" class=\"mf-booking-btn\">📧 Contacter le club</a></div>"
            ),
            'SUSPENSION' => array(
                'text' => "⏸️ **Suspension {$club->name}**\n\n**Comment suspendre ?**\n• Connecte-toi à ton espace membre\n• Ou contacte le club directement",
                'buttons' => "<div class=\"mf-booking-buttons\"><a href=\"https://member.magicfit.fr/\" target=\"_blank\" class=\"mf-booking-btn\">👤 Espace membre</a><a href=\"{$contact_url}\" target=\"_blank\" class=\"mf-booking-btn\">📧 Contacter le club</a></div>"
            ),
            'HORAIRES' => array(
                'text' => "🕐 **Horaires {$club->name}**\n\n📍 {$club->address}, {$club->postal_code} {$club->city}",
                'buttons' => "<div class=\"mf-booking-buttons\"><a href=\"https://www.magicfit.fr/{$club->slug}/\" target=\"_blank\" class=\"mf-booking-btn\">🏋️ Voir la fiche club</a></div>"
            )
        );
        
        if (isset($responses_with_club[$intention])) {
            $r = $responses_with_club[$intention];
            return $r['text'] . "\n\n📍 {$club->address}, {$club->postal_code} {$club->city}\n\n" . $r['buttons'];
        }
    }
    
    if (in_array($intention, $needs_club) && !$club) {
        $messages = array(
            'TARIFS' => "💰 Pour les tarifs, dis-moi ton **code postal** !",
            'HORAIRES' => "🕐 Pour les horaires, dis-moi ton **code postal** !",
            'PLANNING' => "📅 Pour le planning, dis-moi ton **code postal** !",
            'SEANCE_ESSAI' => "🎯 Pour réserver une séance d'essai, dis-moi ton **code postal** !",
            'INSCRIPTION' => "📝 Pour t'inscrire, dis-moi ton **code postal** !",
            'ACTIVITES' => "💪 Tu veux des infos sur les équipements ?\n\n📍 Dis-moi ton **code postal** !",
            'COURS_COLLECTIFS' => "🏋️ On propose +50 cours collectifs !\n\n📍 Dis-moi ton **code postal** !",
            'CONTACT' => "📞 Pour contacter un club, dis-moi ton **code postal** !",
            'RESILIATION' => "📋 Pour résilier, dis-moi ton **code postal** ou le nom de ton club !",
            'SUSPENSION' => "⏸️ Pour suspendre ton abonnement, dis-moi ton **code postal** !",
            'PARRAINAGE' => "🎁 Pour le parrainage, dis-moi ton **code postal** !",
            'PAIEMENT' => "💳 Pour les questions de paiement, dis-moi ton **code postal** !",
            'LOCALISATION' => "📍 Dis-moi ton **code postal** pour trouver ton club !"
        );
        return $messages[$intention] ?? "📍 Dis-moi ton **code postal** !";
    }
    
    if ($intention === 'SALUTATION') {
        return "Salut ! 👋 Comment je peux t'aider ?\n\n📍 Dis-moi ton **code postal** pour commencer !";
    }
    
    // ==========================================
    // INTENTIONS GLOBALES (PAS BESOIN DE CLUB)
    // Utilise le fichier mf-custom-responses.php si disponible
    // ==========================================
    
    // Liste des intentions globales
    $global_intentions = array('MUSCULATION', 'RECETTES', 'NUTRITION', 'CALCULATEURS', 
                               'FRANCHISE', 'RECRUTEMENT', 'PRESSE');
    
    // Permettre aux plugins d'ajouter des intentions globales
    $global_intentions = apply_filters('mf_global_intentions', $global_intentions);
    
    if (in_array($intention, $global_intentions)) {
        // Essayer d'abord le fichier custom (prioritaire)
        if (function_exists('mf_get_custom_response')) {
            $custom = mf_get_custom_response($intention);
            if ($custom !== null) {
                return $custom;
            }
        }
        
        // Fallback intégré si mf-custom-responses.php n'existe pas
        $fallback_responses = mf_get_global_responses_fallback();
        if (isset($fallback_responses[$intention])) {
            return $fallback_responses[$intention];
        }
    }
    
    return "Je peux t'aider ! 💪\n\n📍 Dis-moi ton **code postal** pour des infos personnalisées !";
}

/**
 * Réponses de secours si mf-custom-responses.php n'existe pas
 * Ces réponses ne seront utilisées que si le fichier custom est absent
 */
function mf_get_global_responses_fallback() {
    return array(
        'MUSCULATION' => "💪 **Conseils Musculation**\n\nJe peux t'aider avec les exercices et programmes d'entraînement !\n\n<div class=\"mf-booking-buttons\"><a href=\"https://www.magicfit.fr/tag/musculation/\" target=\"_blank\" class=\"mf-booking-btn\">💪 Conseils muscu</a><a href=\"https://www.magicfit.fr/nos-salles/\" target=\"_blank\" class=\"mf-booking-btn\">🏋️ Trouver un club</a></div>",
        
        'RECETTES' => "🥗 **Recettes Fitness**\n\nDécouvre nos recettes healthy !\n\n<div class=\"mf-booking-buttons\"><a href=\"https://www.magicfit.fr/tag/recettes-magicfit/\" target=\"_blank\" class=\"mf-booking-btn\">🥗 Voir les recettes</a></div>",
        
        'NUTRITION' => "🍎 **Conseils Nutrition**\n\nJe peux t'aider avec tes questions nutrition !\n\n<div class=\"mf-booking-buttons\"><a href=\"https://www.magicfit.fr/tag/recettes-magicfit/\" target=\"_blank\" class=\"mf-booking-btn\">🥗 Recettes fitness</a></div>",
        
        'CALCULATEURS' => "🧮 **Calculateurs Fitness**\n\nIMC, calories, macros...\n\n<div class=\"mf-booking-buttons\"><a href=\"https://www.magicfit.fr/tag/calculateurs-de-sports/\" target=\"_blank\" class=\"mf-booking-btn\">🧮 Calculateurs</a></div>",
        
        'FRANCHISE' => "🚀 **Devenir Franchisé MagicFit**\n\nRejoignez notre réseau !\n\n<div class=\"mf-booking-buttons\"><a href=\"https://www.magicfit.fr/franchise/\" target=\"_blank\" class=\"mf-booking-btn\">🚀 Devenir franchisé</a></div>",
        
        'RECRUTEMENT' => "👔 **Rejoindre l'équipe MagicFit**\n\nNous recrutons !\n\n<div class=\"mf-booking-buttons\"><a href=\"https://www.magicfit.fr/nous-contacter__trashed/contact-recrutement/\" target=\"_blank\" class=\"mf-booking-btn\">👔 Postuler</a></div>",
        
        'PRESSE' => "📰 **Contact Presse MagicFit**\n\n<div class=\"mf-booking-buttons\"><a href=\"https://www.magicfit.fr/nous-contacter/contact-presse/\" target=\"_blank\" class=\"mf-booking-btn\">📰 Contact presse</a></div>"
    );
}

// ============================================
// CORRECTIONS FORCÉES (BDD)
// ============================================

function mf_check_forced_response($msg_clean) {
    global $wpdb;
    $table = $wpdb->prefix . 'mf_force_responses';
    
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
        return null;
    }
    
    $forced = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table WHERE is_active = 1 AND keyword = %s ORDER BY priority DESC LIMIT 1",
        $msg_clean
    ));
    
    if ($forced) {
        return array(
            'response' => $forced->response,
            'intention' => $forced->intention,
            'needs_club' => (bool)$forced->needs_club
        );
    }
    
    return null;
}

// ============================================
// RECHERCHE DE CLUB
// ============================================

function mf_find_club_by_postal($postal_code) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}mf_clubs WHERE postal_code = %s AND is_active = 1 LIMIT 1",
        $postal_code
    ));
}

function mf_find_club_by_name($search) {
    global $wpdb;
    
    // Liste d'exclusion - mots qui ne sont PAS des villes
    $exclusions = array(
        'cours', 'cour', 'collectif', 'collectifs', 'colectif', 'colectifs',
        'sport', 'fitness', 'musculation', 'muscu',
        'abonnement', 'tarif', 'tarifs', 'prix', 'horaire', 'horaires', 'planning',
        'essai', 'inscription', 'contact', 'aide', 'help',
        'seance', 'séance', 'reserver', 'réserver', 'reservation', 'tester', 'test', 'decouvrir',
        'inscrire', 'abonner', 'contacter', 'appeler', 'telephone', 'email',
        'bonjour', 'salut', 'hello', 'merci', 'oui', 'non',
        'suspension', 'suspendre', 'resiliation', 'resilier',
        'yoga', 'pilates', 'zumba', 'cardio', 'step', 'rpm', 'biking',
        'magicfit', 'magic', 'fit' // Ignorer le nom de la marque
    );
    
    // Si le message entier est une intention, ne pas chercher de club
    $words = explode(' ', $search);
    $words = array_filter($words, function($w) use ($exclusions) {
        return strlen($w) >= 3 && !in_array($w, $exclusions);
    });
    
    // Si aucun mot restant, pas de club
    if (empty($words)) {
        return null;
    }
    
    // Si c'est clairement une intention, ne pas chercher de club
    if (preg_match('/(cours|tarif|horaire|planning|essai|inscri|resili|suspen|yoga|pilates)/i', $search)) {
        // Sauf si un nom de ville est aussi présent
        $has_city = false;
        $clubs = $wpdb->get_results("SELECT city FROM {$wpdb->prefix}mf_clubs WHERE is_active = 1");
        foreach ($clubs as $club) {
            $city = mf_normalize_text(mb_strtolower($club->city));
            if (strpos($search, $city) !== false) {
                $has_city = true;
                break;
            }
        }
        if (!$has_city) {
            return null;
        }
    }
    
    $clubs = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}mf_clubs WHERE is_active = 1");
    
    // 1. Chercher une correspondance exacte de ville DANS le message
    foreach ($clubs as $club) {
        $city = mf_normalize_text(mb_strtolower($club->city));
        
        // Match exact du message complet
        if ($search === $city) {
            return $club;
        }
        
        // v10.1 - Chercher si la ville est DANS le message
        if (strpos($search, $city) !== false) {
            return $club;
        }
        
        // Chercher chaque mot du message
        foreach ($words as $word) {
            if ($word === $city) {
                return $club;
            }
        }
    }
    
    // 2. Fuzzy matching (Levenshtein) pour les fautes de frappe légères
    // Mais seulement sur les mots non-exclus et de longueur similaire
    foreach ($clubs as $club) {
        $city = mf_normalize_text(mb_strtolower($club->city));
        
        foreach ($words as $word) {
            // Seulement si longueur similaire
            if (strlen($word) >= 4 && abs(strlen($word) - strlen($city)) <= 1) {
                $distance = levenshtein($word, $city);
                if ($distance <= 1) {
                    return $club;
                }
            }
        }
    }
    
    return null;
}

// ============================================
// HELPERS
// ============================================

function mf_normalize_text($text) {
    $accents = array('é','è','ê','ë','à','â','ä','ù','û','ü','ô','ö','î','ï','ç','ñ');
    $sans = array('e','e','e','e','a','a','a','u','u','u','o','o','i','i','c','n');
    return str_replace($accents, $sans, $text);
}

function mf_response($message, $intention, $club_id = null, $pending_intention = null, $extra = array()) {
    global $wpdb;
    
    // Nettoyer les slashes excessifs (problème d'échappement BDD)
    $message = stripslashes($message);
    $message = str_replace(array("\\'", "\'", "\\'"), "'", $message);
    $message = preg_replace('/\\\\+\'/', "'", $message);
    
    // Ajouter les boutons si fournis dans $extra
    if (!empty($extra['buttons'])) {
        $message .= "\n\n" . $extra['buttons'];
    }
    
    // Récupérer le nom du club si on a l'ID
    $club_name = '';
    if ($club_id) {
        $club = $wpdb->get_row($wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}mf_clubs WHERE id = %d",
            $club_id
        ));
        $club_name = $club ? $club->name : '';
    }
    
    // Vérifier si les boutons sont présents
    $has_buttons = strpos($message, 'mf-booking-btn') !== false;
    
    // Récupérer les suggestions proactives si activées
    $settings = get_option('mf_alert_settings', array());
    $suggestions = array();
    $quick_replies_html = '';
    
    if (!empty($settings['enable_proactive'])) {
        if (function_exists('mf_get_proactive_suggestions')) {
            $suggestions = mf_get_proactive_suggestions($intention, null, array());
        }
        if (!empty($suggestions) && function_exists('mf_build_quick_replies_html')) {
            $quick_replies_html = mf_build_quick_replies_html($suggestions);
        }
    }
    
    return array(
        'response' => $message,
        'intention' => $intention,
        'club_id' => $club_id,
        'club_name' => $club_name,
        'pending_intention' => $pending_intention,
        'handler_version' => MF_HANDLER_VERSION,
        'has_buttons' => $has_buttons,
        'suggestions' => $suggestions,
        'quick_replies_html' => $quick_replies_html
    );
}

// ============================================
// v10.2 - LOGGING APRÈS CHAQUE RÉPONSE
// ============================================

function mf_log_response($message, $response_data, $session_id) {
    if (!function_exists('mf_log_conversation')) {
        // Charger le fichier intelligence si pas déjà fait
        $intelligence_file = dirname(__FILE__) . '/mf-intelligence.php';
        if (file_exists($intelligence_file)) {
            require_once $intelligence_file;
        }
    }
    
    if (function_exists('mf_log_conversation')) {
        mf_log_conversation(array(
            'session_id' => $session_id,
            'message' => $message,
            'intention' => $response_data['intention'] ?? '',
            'club_id' => $response_data['club_id'] ?? null,
            'club_name' => $response_data['club_name'] ?? '',
            'response' => $response_data['response'] ?? '',
            'has_buttons' => $response_data['has_buttons'] ?? false
        ));
    }
}

// ============================================
// v10.2 - FORMATER LES MODES DE PAIEMENT
// ============================================

function mf_get_formatted_payment_methods($club_id) {
    // Labels des modes de paiement
    $labels = array(
        'cb' => '💳 Carte Bancaire',
        'sepa' => '🏦 Prélèvement SEPA',
        'cheque' => '📝 Chèque',
        'especes' => '💵 Espèces',
        'amex' => '💎 American Express',
        'alma' => '⏳ Alma (plusieurs fois)',
        'ancv_vacances' => '🏖️ Chèque Vacances',
        'ancv_sport' => '🎾 Chèque Sport ANCV',
        'coupon_sport' => '🎫 Coupon Sport',
        'pass_sport' => '🏃 Pass\'Sport',
    );
    
    // Récupérer les modes depuis la BDD
    if (!function_exists('mf_get_club_data')) {
        return '💳 CB • 🏦 SEPA • 📝 Chèque • 💵 Espèces';
    }
    
    $payments_json = mf_get_club_data($club_id, 'payment_methods', '["cb","sepa"]');
    $payments = json_decode($payments_json, true);
    
    if (!is_array($payments) || empty($payments)) {
        $payments = array('cb', 'sepa');
    }
    
    $formatted = array();
    foreach ($payments as $key) {
        if (isset($labels[$key])) {
            $formatted[] = $labels[$key];
        }
    }
    
    return !empty($formatted) ? implode(' • ', $formatted) : '💳 CB • 🏦 SEPA';
}

// ============================================
// v10.2 - FORMATER LES ACTIVITÉS
// ============================================

function mf_get_formatted_activities($club_id, $format = 'inline') {
    // Labels des activités
    $labels = array(
        'cours_collectifs' => '🏃 Cours collectifs',
        'musculation' => '💪 Musculation',
        'cardio' => '❤️ Cardio-training',
        'cross_training' => '🔥 Cross-training',
        'biking' => '🚴 Biking',
        'yoga' => '🧘 Yoga',
        'pilates' => '🧘‍♀️ Pilates',
        'boxe' => '🥊 Boxe',
        'piscine' => '🏊 Piscine',
        'sauna' => '🧖 Sauna',
        'hammam' => '♨️ Hammam',
        'coaching' => '👨‍🏫 Coaching',
        'small_group' => '👥 Small group',
        'pole_sante' => '🏥 Pôle Santé',
        'pole_bien_etre' => '🧘‍♂️ Pôle Bien-être',
        'aquagym' => '🏊 Aquagym',
        'aquabike' => '🚴 Aquabike',
        'zumba' => '💃 Zumba',
        'step' => '👟 Step',
        'bodypump' => '💪 BodyPump',
        'bodycombat' => '🥊 BodyCombat',
        'caf' => '🍑 CAF',
        'abdos' => '🔥 Abdos',
        'stretching' => '🙆 Stretching',
        'hiit' => '🔥 HIIT',
        'trx' => '🎗️ TRX',
        'functional' => '⚡ Functional Training',
        'electrostim' => '⚡ Électrostimulation',
        'kids' => '👶 Espace Kids',
    );
    
    // Récupérer les activités depuis la BDD
    if (!function_exists('mf_get_club_data')) {
        return '💪 Musculation • ❤️ Cardio • 🏃 Cours collectifs';
    }
    
    $activities_json = mf_get_club_data($club_id, 'activities', '[]');
    $activities = json_decode($activities_json, true);
    
    if (!is_array($activities) || empty($activities)) {
        return '💪 Musculation • ❤️ Cardio • 🏃 Cours collectifs';
    }
    
    $formatted = array();
    foreach ($activities as $key) {
        if (isset($labels[$key])) {
            $formatted[] = $labels[$key];
        } else {
            $formatted[] = '🏃 ' . ucfirst(str_replace('_', ' ', $key));
        }
    }
    
    if (empty($formatted)) {
        return '💪 Musculation • ❤️ Cardio • 🏃 Cours collectifs';
    }
    
    switch ($format) {
        case 'list':
            return "• " . implode("\n• ", $formatted);
        case 'count':
            return count($formatted) . ' activités';
        case 'short':
            if (count($formatted) > 5) {
                return implode(' • ', array_slice($formatted, 0, 5)) . ' et +' . (count($formatted) - 5) . ' autres';
            }
            return implode(' • ', $formatted);
        default:
            return implode(' • ', $formatted);
    }
}
