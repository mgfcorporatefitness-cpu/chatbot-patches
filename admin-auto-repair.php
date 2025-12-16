<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════════════╗
 * ║   MagicFit Auto-Repair v5.0 - AUTO-UPDATE GITHUB INSTANTANÉ                 ║
 * ║                                                                              ║
 * ║   ✅ Vérifie GitHub automatiquement à chaque visite                         ║
 * ║   ✅ Télécharge et applique les patches instantanément                      ║
 * ║   ✅ Répare fichiers + BDD en un clic                                       ║
 * ║   ✅ Zéro intervention manuelle requise                                     ║
 * ╚══════════════════════════════════════════════════════════════════════════════╝
 */

if (!defined('ABSPATH')) exit;

define('MF_REPAIR_VERSION', '5.0.1');
define('MF_TARGET_VERSION', '10.6.0');
define('MF_GITHUB_REPO', 'mgfcorporatefitness-cpu/chatbot-patches');
define('MF_GITHUB_BRANCH', 'main');
define('MF_GITHUB_RAW_URL', 'https://raw.githubusercontent.com/' . MF_GITHUB_REPO . '/' . MF_GITHUB_BRANCH);

add_action('admin_menu', 'mf_register_auto_repair_menu', 25);

function mf_register_auto_repair_menu() {
    add_submenu_page('magicfit', 'Auto-Repair', '🔧 Auto-Repair', 'manage_options', 'mf-auto-repair', 'mf_render_auto_repair_page');
}

function mf_render_auto_repair_page() {
    global $wpdb;
    if (!current_user_can('manage_options')) wp_die('Accès refusé');
    
    $repair_all = isset($_GET['repair_all']) && $_GET['repair_all'] === '1';
    $repair_files = isset($_GET['repair_files']) && $_GET['repair_files'] === '1';
    $repair_db = isset($_GET['repair_db']) && $_GET['repair_db'] === '1';
    $apply_updates = isset($_GET['apply_updates']) && $_GET['apply_updates'] === '1';
    
    $file_repairs = array();
    $db_repairs = array();
    $update_results = array();
    
    $github_status = mf_check_github_updates();
    
    if ($apply_updates || $repair_all) {
        $update_results = mf_apply_github_updates();
    }
    if ($repair_all || $repair_files) {
        $file_repairs = mf_repair_all_files();
    }
    if ($repair_all || $repair_db) {
        $db_repairs = mf_repair_database();
    }
    
    $diagnostic = mf_run_complete_diagnostic();
    ?>
    <style>
        .mf-wrap{max-width:1400px;margin:20px auto;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
        .mf-header{background:linear-gradient(135deg,#1a1a2e 0%,#16213e 50%,#0f3460 100%);color:#fff;padding:40px;border-radius:16px;margin-bottom:30px}
        .mf-header h1{margin:0 0 10px 0;font-size:32px}.mf-header p{margin:0;opacity:.8}
        .mf-badge{background:rgba(255,255,255,.2);padding:5px 15px;border-radius:20px;font-size:14px;margin-left:15px}
        .mf-github-bar{background:linear-gradient(135deg,#24292e 0%,#1b1f23 100%);border-radius:12px;padding:20px;margin-bottom:30px;display:flex;align-items:center;justify-content:space-between;color:#fff}
        .mf-github-bar.has-updates{background:linear-gradient(135deg,#f6c343 0%,#f59e0b 100%);color:#000}
        .mf-github-bar.up-to-date{background:linear-gradient(135deg,#10b981 0%,#059669 100%)}
        .mf-github-bar h3{margin:0;font-size:18px}.mf-github-bar p{margin:5px 0 0;opacity:.8;font-size:14px}
        .mf-github-btn{padding:12px 24px;background:#fff;color:#24292e;border:none;border-radius:8px;font-weight:600;cursor:pointer;text-decoration:none}
        .mf-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;margin-bottom:30px}
        @media(max-width:1200px){.mf-grid{grid-template-columns:repeat(2,1fr)}}
        .mf-card{background:#fff;border-radius:16px;padding:25px;box-shadow:0 4px 20px rgba(0,0,0,.08);text-align:center}
        .mf-card.ok{border-bottom:4px solid #4caf50}.mf-card.warning{border-bottom:4px solid #ff9800}.mf-card.error{border-bottom:4px solid #f44336}
        .mf-card .icon{font-size:40px;margin-bottom:10px}.mf-card .number{font-size:36px;font-weight:700;color:#1a1a2e}.mf-card .label{font-size:14px;color:#666;margin-top:5px}
        .mf-action-bar{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);border-radius:16px;padding:30px;margin-bottom:30px;display:flex;align-items:center;justify-content:space-between}
        .mf-action-bar.ok{background:linear-gradient(135deg,#4caf50 0%,#8bc34a 100%)}
        .mf-action-bar h2{margin:0;color:#fff;font-size:24px}.mf-action-bar p{margin:5px 0 0;color:rgba(255,255,255,.8)}
        .mf-btn{display:inline-flex;align-items:center;gap:10px;padding:18px 40px;font-size:18px;font-weight:700;color:#667eea;background:#fff;border:none;border-radius:12px;cursor:pointer;text-decoration:none}
        .mf-btn:hover{transform:scale(1.05)}.mf-btn.disabled{opacity:.5;pointer-events:none;color:#4caf50}
        .mf-section{background:#fff;border-radius:16px;padding:30px;box-shadow:0 4px 20px rgba(0,0,0,.08);margin-bottom:30px}
        .mf-section h2{margin:0 0 25px;font-size:22px;padding-bottom:15px;border-bottom:2px solid #f0f0f0}
        .mf-table{width:100%;border-collapse:collapse}.mf-table th,.mf-table td{padding:15px;text-align:left;border-bottom:1px solid #f0f0f0}
        .mf-table th{background:#f8f9fa;font-weight:600;font-size:13px;text-transform:uppercase;color:#666}
        .mf-status{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:20px;font-size:13px;font-weight:600}
        .mf-status.ok{background:#e8f5e9;color:#2e7d32}.mf-status.warning{background:#fff3e0;color:#ef6c00}.mf-status.error{background:#ffebee;color:#c62828}
        .mf-log{background:#e8f5e9;border-radius:12px;padding:25px;margin-bottom:30px;border-left:5px solid #4caf50}
        .mf-log.github{background:#e3f2fd;border-left-color:#2196f3}
        .mf-log h3{margin:0 0 15px;color:#2e7d32}.mf-log.github h3{color:#1565c0}
        .mf-log ul{margin:0;padding-left:25px}.mf-log li{margin:8px 0}
        .mf-progress{height:10px;background:#e0e0e0;border-radius:5px;overflow:hidden;margin-top:10px}
        .mf-progress-bar{height:100%;background:linear-gradient(90deg,#4caf50,#8bc34a)}
    </style>
    
    <div class="mf-wrap">
        <div class="mf-header">
            <h1>🔧 MagicFit Auto-Repair <span class="mf-badge">v<?php echo MF_REPAIR_VERSION; ?></span></h1>
            <p>Auto-Update GitHub instantané • Diagnostic + Réparation automatique</p>
        </div>
        
        <div class="mf-github-bar <?php echo $github_status['has_updates'] ? 'has-updates' : ($github_status['error'] ? '' : 'up-to-date'); ?>">
            <div>
                <h3><?php 
                if ($github_status['has_updates']) echo '⚡ Mise à jour disponible !';
                elseif ($github_status['error']) echo '⚠️ ' . esc_html($github_status['error']);
                else echo '✅ À jour avec GitHub';
                ?></h3>
                <p><?php 
                if ($github_status['has_updates']) echo 'Version ' . esc_html($github_status['remote_version']) . ' disponible';
                elseif (!$github_status['error']) echo 'Dernière vérification: ' . date('d/m/Y H:i:s');
                ?></p>
            </div>
            <div>
                <?php if ($github_status['has_updates']): ?>
                <form method="get" action="<?php echo admin_url('admin.php'); ?>" style="display:inline;">
                    <input type="hidden" name="page" value="mf-auto-repair">
                    <input type="hidden" name="apply_updates" value="1">
                    <button type="submit" class="mf-github-btn" style="border:none;">⬇️ Télécharger</button>
                </form>
                <?php else: ?>
                <a href="<?php echo admin_url('admin.php?page=mf-auto-repair'); ?>" class="mf-github-btn">🔄 Vérifier</a>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if (!empty($update_results)): ?>
        <div class="mf-log github"><h3>⬇️ Mises à jour GitHub</h3><ul>
        <?php foreach ($update_results as $r): ?><li><?php echo esc_html($r); ?></li><?php endforeach; ?>
        </ul></div>
        <?php endif; ?>
        
        <?php if (!empty($file_repairs) || !empty($db_repairs)): ?>
        <div class="mf-log"><h3>✅ Réparations effectuées</h3><ul>
        <?php foreach ($file_repairs as $r): ?><li>📁 <?php echo esc_html($r); ?></li><?php endforeach; ?>
        <?php foreach ($db_repairs as $r): ?><li>🗄️ <?php echo esc_html($r); ?></li><?php endforeach; ?>
        </ul></div>
        <?php endif; ?>
        
        <div class="mf-grid">
            <div class="mf-card <?php echo $diagnostic['score'] >= 80 ? 'ok' : ($diagnostic['score'] >= 50 ? 'warning' : 'error'); ?>">
                <div class="icon">📊</div><div class="number"><?php echo $diagnostic['score']; ?>%</div><div class="label">Score</div>
                <div class="mf-progress"><div class="mf-progress-bar" style="width:<?php echo $diagnostic['score']; ?>%"></div></div>
            </div>
            <div class="mf-card <?php echo $diagnostic['files_problems'] === 0 ? 'ok' : 'error'; ?>">
                <div class="icon">📁</div><div class="number"><?php echo count($diagnostic['files']) - $diagnostic['files_problems']; ?>/<?php echo count($diagnostic['files']); ?></div><div class="label">Fichiers</div>
            </div>
            <div class="mf-card <?php echo $diagnostic['db_problems'] === 0 ? 'ok' : 'warning'; ?>">
                <div class="icon">🗄️</div><div class="number"><?php echo count($diagnostic['db_checks']) - $diagnostic['db_problems']; ?>/<?php echo count($diagnostic['db_checks']); ?></div><div class="label">BDD</div>
            </div>
            <div class="mf-card <?php echo $diagnostic['total_problems'] === 0 ? 'ok' : 'error'; ?>">
                <div class="icon"><?php echo $diagnostic['total_problems'] === 0 ? '✅' : '⚠️'; ?></div><div class="number"><?php echo $diagnostic['total_problems']; ?></div><div class="label">Problèmes</div>
            </div>
        </div>
        
        <?php if ($diagnostic['total_problems'] > 0): ?>
        <div class="mf-action-bar">
            <div><h2>🚨 <?php echo $diagnostic['total_problems']; ?> problème(s)</h2><p>Cliquez pour tout réparer</p></div>
            <a href="<?php echo admin_url('admin.php?page=mf-auto-repair&repair_all=1'); ?>" class="mf-btn">🔧 TOUT RÉPARER</a>
        </div>
        <?php else: ?>
        <div class="mf-action-bar ok">
            <div><h2>✅ Tout fonctionne !</h2><p>Version <?php echo MF_TARGET_VERSION; ?></p></div>
            <span class="mf-btn disabled">✅ OK</span>
        </div>
        <?php endif; ?>
        
        <div class="mf-section">
            <h2>📁 Fichiers PHP</h2>
            <table class="mf-table">
                <thead><tr><th>Fichier</th><th>Version</th><th>Problèmes</th><th>Statut</th></tr></thead>
                <tbody>
                <?php foreach ($diagnostic['files'] as $f): ?>
                <tr>
                    <td><code><?php echo esc_html($f['name']); ?></code></td>
                    <td><?php echo $f['version'] ?: '?'; ?></td>
                    <td style="color:#c62828;font-size:12px"><?php echo $f['problems'] ?: '-'; ?></td>
                    <td><span class="mf-status <?php echo $f['ok'] ? 'ok' : 'error'; ?>"><?php echo $f['ok'] ? '✅' : '❌'; ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="mf-section">
            <h2>🗄️ Base de Données</h2>
            <table class="mf-table">
                <thead><tr><th>Vérification</th><th>Détails</th><th>Statut</th></tr></thead>
                <tbody>
                <?php foreach ($diagnostic['db_checks'] as $c): ?>
                <tr>
                    <td><?php echo esc_html($c['name']); ?></td>
                    <td><?php echo esc_html($c['details']); ?></td>
                    <td><span class="mf-status <?php echo $c['ok'] ? 'ok' : 'warning'; ?>"><?php echo $c['ok'] ? '✅' : '⚠️'; ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="mf-section">
            <h2>🧪 Tests</h2>
            <table class="mf-table">
                <thead><tr><th>Test</th><th>Résultat</th></tr></thead>
                <tbody>
                <?php foreach ($diagnostic['tests'] as $t): ?>
                <tr>
                    <td><?php echo esc_html($t['name']); ?></td>
                    <td><span class="mf-status <?php echo $t['ok'] ? 'ok' : 'error'; ?>"><?php echo $t['ok'] ? '✅' : '❌'; ?> <?php echo esc_html($t['message']); ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="mf-section">
            <h2>⚙️ GitHub</h2>
            <table class="mf-table">
                <tr><td>Dépôt</td><td><code><?php echo MF_GITHUB_REPO; ?></code></td></tr>
                <tr><td>Branche</td><td><code><?php echo MF_GITHUB_BRANCH; ?></code></td></tr>
            </table>
            <p style="margin-top:15px;color:#666">💡 Pour recevoir les mises à jour, créez le dépôt GitHub avec un fichier <code>version.json</code></p>
        </div>
        
        <div class="mf-section">
            <h2>🎛️ Actions</h2>
            <a href="<?php echo admin_url('admin.php?page=mf-auto-repair&repair_files=1'); ?>" class="button button-large">📁 Réparer Fichiers</a>
            <a href="<?php echo admin_url('admin.php?page=mf-auto-repair&repair_db=1'); ?>" class="button button-large">🗄️ Réparer BDD</a>
            <a href="<?php echo admin_url('admin.php?page=mf-auto-repair'); ?>" class="button button-large">🔄 Actualiser</a>
        </div>
        
        <p style="text-align:center;color:#999;margin-top:40px">Auto-Repair v<?php echo MF_REPAIR_VERSION; ?> | <?php echo date('d/m/Y H:i:s'); ?></p>
    </div>
    <?php
}

function mf_check_github_updates() {
    $result = array('has_updates' => false, 'remote_version' => null, 'error' => null, 'files' => array());
    
    $response = wp_remote_get(MF_GITHUB_RAW_URL . '/version.json', array('timeout' => 10, 'sslverify' => false));
    
    if (is_wp_error($response)) {
        $result['error'] = 'Dépôt GitHub non configuré';
        return $result;
    }
    
    if (wp_remote_retrieve_response_code($response) !== 200) {
        $result['error'] = 'Dépôt GitHub non trouvé';
        return $result;
    }
    
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!$data || !isset($data['version'])) {
        $result['error'] = 'version.json invalide';
        return $result;
    }
    
    $result['remote_version'] = $data['version'];
    $result['files'] = $data['files'] ?? array();
    $result['has_updates'] = version_compare($data['version'], MF_TARGET_VERSION, '>');
    
    return $result;
}

function mf_apply_github_updates() {
    $results = array();
    $plugin_dir = dirname(dirname(__FILE__)) . '/';
    $github_status = mf_check_github_updates();
    
    if ($github_status['error'] || empty($github_status['files'])) {
        $results[] = $github_status['error'] ?: 'Aucun fichier';
        return $results;
    }
    
    foreach ($github_status['files'] as $file_info) {
        $file_path = $file_info['path'] ?? '';
        $response = wp_remote_get(MF_GITHUB_RAW_URL . '/' . $file_path, array('timeout' => 30, 'sslverify' => false));
        
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            $results[] = "❌ $file_path";
            continue;
        }
        
        $local_path = $plugin_dir . $file_path;
        if (file_exists($local_path)) copy($local_path, $local_path . '.bak');
        
        $dir = dirname($local_path);
        if (!file_exists($dir)) wp_mkdir_p($dir);
        
        if (file_put_contents($local_path, wp_remote_retrieve_body($response))) {
            $results[] = "✅ $file_path";
        } else {
            $results[] = "❌ $file_path (écriture)";
        }
    }
    
    if (function_exists('opcache_reset')) opcache_reset();
    wp_cache_flush();
    if (function_exists('rocket_clean_domain')) rocket_clean_domain();
    $results[] = "✅ Caches vidés";
    
    return $results;
}

function mf_run_complete_diagnostic() {
    global $wpdb;
    $d = array('score' => 0, 'files' => array(), 'db_checks' => array(), 'tests' => array(), 'files_problems' => 0, 'db_problems' => 0, 'total_problems' => 0);
    $plugin_dir = dirname(dirname(__FILE__)) . '/includes/';
    
    $files = array('mf-chat-handler.php' => 'Chat', 'mf-scenarios-dashboard.php' => 'Scénarios', 'mf-enhancements.php' => 'Améliorations', 'admin-auto-repair.php' => 'Auto-Repair');
    
    foreach ($files as $file => $desc) {
        $path = $plugin_dir . $file;
        $exists = file_exists($path);
        $version = '';
        $problems = array();
        
        if ($exists) {
            $content = file_get_contents($path);
            if (preg_match('/v([0-9]+\.[0-9]+\.?[0-9]*)/i', $content, $m)) $version = $m[1];
            if ($file === 'mf-chat-handler.php' && strpos($content, "get_transient('mf_club_'") !== false) $problems[] = "transient";
            if ($file === 'mf-scenarios-dashboard.php' && strpos($content, "sanitize_textarea_field(\$") !== false && strpos($content, "boutons") !== false) $problems[] = "sanitize";
        }
        
        $ok = $exists && empty($problems);
        if (!$ok && $exists) $d['files_problems']++;
        $d['files'][] = array('name' => $file, 'description' => $desc, 'version' => $version, 'ok' => $ok, 'problems' => implode(', ', $problems));
    }
    
    $tables = array('mf_intentions' => 'Intentions', 'mf_keywords' => 'Mots-clés', 'mf_clubs' => 'Clubs', 'mf_club_data' => 'Données');
    foreach ($tables as $t => $label) {
        $full = $wpdb->prefix . $t;
        $exists = $wpdb->get_var("SHOW TABLES LIKE '$full'") === $full;
        $count = $exists ? $wpdb->get_var("SELECT COUNT(*) FROM $full") : 0;
        if (!$exists) $d['db_problems']++;
        $d['db_checks'][] = array('name' => $label, 'details' => $exists ? "$count rows" : "Manquante", 'ok' => $exists, 'warning' => false);
    }
    
    $d['tests'][] = array('name' => 'mf_process_message', 'ok' => function_exists('mf_process_message'), 'message' => function_exists('mf_process_message') ? 'OK' : 'Non');
    $d['tests'][] = array('name' => 'Écriture fichiers', 'ok' => is_writable($plugin_dir), 'message' => is_writable($plugin_dir) ? 'OK' : 'Non');
    $github = mf_check_github_updates();
    $d['tests'][] = array('name' => 'GitHub', 'ok' => !$github['error'], 'message' => $github['error'] ?: 'Connecté');
    
    $d['total_problems'] = $d['files_problems'] + $d['db_problems'];
    $total = count($d['files']) + count($d['db_checks']) + count($d['tests']);
    $ok = (count($d['files']) - $d['files_problems']) + (count($d['db_checks']) - $d['db_problems']);
    foreach ($d['tests'] as $t) if ($t['ok']) $ok++;
    $d['score'] = $total > 0 ? round(($ok / $total) * 100) : 0;
    
    return $d;
}

function mf_repair_all_files() {
    $repairs = array();
    $dir = dirname(dirname(__FILE__)) . '/includes/';
    
    $file = $dir . 'mf-chat-handler.php';
    if (file_exists($file)) {
        $content = file_get_contents($file);
        $orig = $content;
        $content = str_replace("get_transient('mf_club_' . md5(\$session_id))", "get_option('mf_club_' . md5(\$session_id), null)", $content);
        $content = preg_replace("/set_transient\('mf_club_'[^)]+\)/", "update_option('mf_club_' . md5(\$session_id), \$club->id)", $content);
        $content = str_replace("get_transient('mf_pending_' . md5(\$session_id))", "get_option('mf_pending_' . md5(\$session_id), null)", $content);
        $content = preg_replace("/set_transient\('mf_pending_'[^)]+\)/", "update_option('mf_pending_' . md5(\$session_id), \$intention)", $content);
        $content = str_replace("delete_transient('mf_pending_' . md5(\$session_id))", "delete_option('mf_pending_' . md5(\$session_id))", $content);
        if ($content !== $orig) { file_put_contents($file . '.bak', $orig); file_put_contents($file, $content); $repairs[] = "chat-handler patché"; }
        else $repairs[] = "chat-handler OK";
    }
    
    $file = $dir . 'mf-scenarios-dashboard.php';
    if (file_exists($file)) {
        $content = file_get_contents($file);
        $orig = $content;
        $content = str_replace("sanitize_textarea_field(\$_POST['boutons'])", "wp_kses_post(\$_POST['boutons'])", $content);
        $content = str_replace("sanitize_textarea_field(\$post['boutons'])", "wp_kses_post(\$post['boutons'])", $content);
        if ($content !== $orig) { file_put_contents($file . '.bak', $orig); file_put_contents($file, $content); $repairs[] = "scenarios patché"; }
        else $repairs[] = "scenarios OK";
    }
    
    if (function_exists('opcache_reset')) opcache_reset();
    wp_cache_flush();
    if (function_exists('rocket_clean_domain')) rocket_clean_domain();
    $repairs[] = "Caches vidés";
    
    return $repairs;
}

function mf_repair_database() {
    global $wpdb;
    $repairs = array();
    
    if (function_exists('mf_scenarios_create_tables')) { mf_scenarios_create_tables(); $repairs[] = "Tables OK"; }
    
    $table = $wpdb->prefix . 'mf_intentions';
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
        for ($i = 0; $i < 10; $i++) {
            $wpdb->query("UPDATE $table SET response_sans_club=REPLACE(response_sans_club,'\\\\',''), response_avec_club=REPLACE(response_avec_club,'\\\\',''), boutons=REPLACE(boutons,'\\\\','')");
        }
        $repairs[] = "Apostrophes OK";
    }
    
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'mf_club_%' OR option_name LIKE 'mf_pending_%'");
    $repairs[] = "Sessions OK";
    
    wp_cache_flush();
    $repairs[] = "Cache OK";
    
    return $repairs;
}
