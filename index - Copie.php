<?php
/**
 * GRIOT DE CRISTAL — Portail Panafricaniste IA
 * UCAA · Unité Culturelle Africaine & Afro-descendants
 * PHP 8.3 — Compatible Hostinger Mutualisé
 * Mistral API · SQLite · cURL only · AJAX batch
 */

define('ROOT_PATH', dirname(__FILE__));
define('DB_PATH', ROOT_PATH . '/griot_data.sqlite');
define('LOG_PATH', ROOT_PATH . '/griot_error.log');

define('MISTRAL_API_KEYS', [
    'aEPaRake',
    'o3ahytu',
    'vEzauXkF'
]);
define('MISTRAL_ENDPOINT', 'https://api.mistral.ai/v1/chat/completions');

// ============================================================
// ROTATION API KEY
// ============================================================
function getApiKey(): string {
    $keys = MISTRAL_API_KEYS;
    $idx = (int)(microtime(true) * 1000) % count($keys);
    return $keys[$idx];
}

// ============================================================
// CURL MISTRAL — Hostinger-safe
// ============================================================
function callMistral(string $model, array $messages, int $maxTokens = 1200): array {
    $attempts = 0;
    $keys = MISTRAL_API_KEYS;
    $lastError = '';

    while ($attempts < count($keys)) {
        $apiKey = $keys[$attempts % count($keys)];
        $payload = json_encode([
            'model'      => $model,
            'messages'   => $messages,
            'max_tokens' => $maxTokens,
            'temperature'=> 0.75
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => MISTRAL_ENDPOINT,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => 'GriotDeCristal/1.0 (+https://ucaa.org)',
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
                'Accept: application/json'
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);

        $response = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $lastError = 'cURL error: ' . $curlError;
            $attempts++;
            usleep(300000);
            continue;
        }

        if (empty($response)) {
            $lastError = 'Empty response from API (HTTP ' . $httpCode . ')';
            $attempts++;
            usleep(300000);
            continue;
        }

        // Vérifier que la réponse est bien du JSON (pas du HTML d'erreur serveur)
        $firstChar = trim($response)[0] ?? '';
        if ($firstChar !== '{' && $firstChar !== '[') {
            $lastError = 'Non-JSON response: ' . substr($response, 0, 200);
            error_log('[GRIOT] ' . $lastError . PHP_EOL, 3, LOG_PATH);
            $attempts++;
            usleep(400000);
            continue;
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $lastError = 'JSON parse error: ' . json_last_error_msg();
            $attempts++;
            usleep(300000);
            continue;
        }

        if ($httpCode === 429) {
            $lastError = 'Rate limit (429)';
            $attempts++;
            usleep(1000000);
            continue;
        }

        if ($httpCode !== 200) {
            $lastError = 'HTTP ' . $httpCode . ': ' . ($data['message'] ?? 'Unknown error');
            $attempts++;
            usleep(300000);
            continue;
        }

        return ['success' => true, 'content' => $data['choices'][0]['message']['content'] ?? ''];
    }

    error_log('[GRIOT] callMistral failed: ' . $lastError . PHP_EOL, 3, LOG_PATH);
    return ['success' => false, 'error' => $lastError];
}

// ============================================================
// SQLite INIT
// ============================================================
function initDB(): PDO {
    $dbDir = dirname(DB_PATH);
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0755, true);
    }
    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA synchronous=NORMAL');
    $pdo->exec("CREATE TABLE IF NOT EXISTS conversations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id TEXT NOT NULL,
        module TEXT NOT NULL DEFAULT 'griot',
        role TEXT NOT NULL,
        content TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS members (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT,
        email TEXT UNIQUE,
        archetype TEXT,
        tokens_used INTEGER DEFAULT 0,
        joined_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS vigilance_reports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        reporter TEXT,
        category TEXT,
        description TEXT,
        ai_analysis TEXT,
        status TEXT DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    return $pdo;
}

// ============================================================
// AJAX HANDLERS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = trim($_POST['action']);

    try {
        $pdo = initDB();

        // ── MODULE: GRIOT CHAT ──────────────────────────────
        if ($action === 'griot_chat') {
            $sessionId = trim($_POST['session_id'] ?? session_id());
            $userMsg   = trim($_POST['message'] ?? '');
            $module    = trim($_POST['module'] ?? 'griot');

            if (empty($userMsg)) {
                echo json_encode(['success' => false, 'error' => 'Message vide']);
                exit;
            }

            // Récupérer l'historique (max 10 échanges)
            $stmt = $pdo->prepare("SELECT role, content FROM conversations WHERE session_id=? AND module=? ORDER BY id DESC LIMIT 20");
            $stmt->execute([$sessionId, $module]);
            $history = array_reverse($stmt->fetchAll());

            // Sauvegarder le message utilisateur
            $stmt = $pdo->prepare("INSERT INTO conversations (session_id, module, role, content) VALUES (?,?,?,?)");
            $stmt->execute([$sessionId, $module, 'user', $userMsg]);

            // Construire le contexte selon le module
            $systemPrompts = [
                'griot' => "Tu es le Griot de Cristal, gardien numérique de la mémoire et de la sagesse africaine. Tu incarnes la transmission orale millénaire dans une forme numérique. Tu réponds avec profondeur, en honneur des ancêtres et des bâtisseurs africains. Tu parles des héros méconnus, des inventeurs, de la richesse des civilisations africaines. Tu es bienveillant, puissant et inspirant. Réponds en français, avec dignité et élévation.",
                'contre_enquete' => "Tu es un analyste critique expert en décryptage médiatique. Ta mission est de déconstruire les biais narratifs et les manipulations historiques contre les peuples africains et afro-descendants. Tu fournis des contre-récits documentés, sourcés, rigoureux. Tu identifies les angles morts, les omissions, les distorsions. Sois précis, factuel, percutant. Réponds en français.",
                'conseiller_syndical' => "Tu es le Conseiller Syndical Intelligent de l'UCAA. Tu assistes les travailleurs, artistes et étudiants africains et afro-descendants face aux discriminations. Tu connais le droit du travail français et européen. Tu aides à rédiger des recours, comprendre ses droits, organiser des actions collectives légales. Tu es protecteur, précis, engagé. Réponds en français avec des conseils pratiques.",
                'archive_vivante' => "Tu incarnes les grandes figures africaines : Cheikh Anta Diop, Thomas Sankara, Patrice Lumumba, Harriet Tubman, Toussaint Louverture, Shaka Zulu et d'autres. L'utilisateur peut te demander d'incarner un personnage spécifique. Tu réponds en première personne, avec la voix, la philosophie et les valeurs de ce personnage. Tu transmets leur sagesse aux jeunes générations.",
            ];

            $systemPrompt = $systemPrompts[$module] ?? $systemPrompts['griot'];

            $messages = [['role' => 'system', 'content' => $systemPrompt]];
            foreach ($history as $h) {
                $messages[] = ['role' => $h['role'], 'content' => $h['content']];
            }
            $messages[] = ['role' => 'user', 'content' => $userMsg];

            $result = callMistral('mistral-large-2512', $messages, 1200);

            if ($result['success']) {
                $stmt->execute([$sessionId, $module, 'assistant', $result['content']]);
                echo json_encode(['success' => true, 'response' => $result['content']]);
            } else {
                echo json_encode(['success' => false, 'error' => $result['error']]);
            }
            exit;
        }

        // ── MODULE: VIGILANCE REPORT ────────────────────────
        if ($action === 'vigilance_report') {
            $category    = trim($_POST['category'] ?? 'discrimination');
            $description = trim($_POST['description'] ?? '');
            $reporter    = trim($_POST['reporter'] ?? 'Anonyme');

            if (strlen($description) < 20) {
                echo json_encode(['success' => false, 'error' => 'Description trop courte']);
                exit;
            }

            $analyzePrompt = "Analyse ce signalement de vigilance citoyenne soumis à l'UCAA :\n\nCatégorie : $category\nDescription : $description\n\nFournis :\n1. Évaluation de la gravité (1-5)\n2. Type d'injustice identifié\n3. Moyens d'action recommandés (juridique, médiatique, collectif)\n4. Ressources légales applicables\n\nSois précis et actionnable.";

            $result = callMistral('magistral-medium-2509', [
                ['role' => 'system', 'content' => 'Tu es un juriste expert en droit anti-discrimination et droits humains, conseiller de l\'UCAA.'],
                ['role' => 'user', 'content' => $analyzePrompt]
            ], 800);

            $analysis = $result['success'] ? $result['content'] : 'Analyse en cours...';

            $stmt = $pdo->prepare("INSERT INTO vigilance_reports (reporter, category, description, ai_analysis) VALUES (?,?,?,?)");
            $stmt->execute([$reporter, $category, $description, $analysis]);

            echo json_encode(['success' => true, 'analysis' => $analysis, 'id' => $pdo->lastInsertId()]);
            exit;
        }

        // ── MODULE: LABO HIP-HOP ────────────────────────────
        if ($action === 'hiphop_generate') {
            $theme  = trim($_POST['theme'] ?? '');
            $style  = trim($_POST['style'] ?? 'rap conscient');
            $langue = trim($_POST['langue'] ?? 'français');

            if (empty($theme)) {
                echo json_encode(['success' => false, 'error' => 'Thème requis']);
                exit;
            }

            $prompt = "Compose des paroles de $style en $langue sur le thème : \"$theme\"\n\nLes paroles doivent :\n- Porter un message de souveraineté, de dignité et de justice\n- Éviter toute dégradation culturelle\n- S'inspirer des luttes africaines et afro-diasporiques\n- Avoir une structure : intro, couplet 1, refrain, couplet 2, outro\n- Être authentiques, poétiques et politiquement engagées\n\nCompose maintenant :";

            $result = callMistral('labs-mistral-small-creative', [
                ['role' => 'system', 'content' => 'Tu es un parolier de rap conscient, dans la tradition de KRS-One, Didier Awadi, Stomy Bugsy (période militante) et Oxmo Puccino. Ton rap porte la conscience africaine.'],
                ['role' => 'user', 'content' => $prompt]
            ], 1000);

            echo json_encode($result['success']
                ? ['success' => true, 'lyrics' => $result['content']]
                : ['success' => false, 'error' => $result['error']]
            );
            exit;
        }

        // ── MODULE: TRADUCTION ──────────────────────────────
        if ($action === 'translate') {
            $text       = trim($_POST['text'] ?? '');
            $targetLang = trim($_POST['target_lang'] ?? 'Wolof');

            if (empty($text)) {
                echo json_encode(['success' => false, 'error' => 'Texte vide']);
                exit;
            }

            $result = callMistral('mistral-large-2512', [
                ['role' => 'system', 'content' => "Tu es un expert en langues africaines et en langues de la diaspora. Tu traduis avec précision tout en préservant la richesse culturelle et le sens profond des mots."],
                ['role' => 'user', 'content' => "Traduis ce texte en $targetLang, puis explique brièvement les nuances culturelles importantes :\n\n\"$text\""]
            ], 600);

            echo json_encode($result['success']
                ? ['success' => true, 'translation' => $result['content']]
                : ['success' => false, 'error' => $result['error']]
            );
            exit;
        }

        // ── MODULE: ANNUAIRE IA ─────────────────────────────
        if ($action === 'annuaire_match') {
            $need   = trim($_POST['need'] ?? '');
            $sector = trim($_POST['sector'] ?? '');

            $prompt = "Un membre du réseau UCAA cherche :\nBesoin : $need\nSecteur : $sector\n\nGénère :\n1. Un profil type du professionnel idéal à trouver dans la diaspora africaine\n2. Les compétences clés à rechercher\n3. 3 questions de qualification pour vérifier l'adéquation\n4. Un message de mise en relation percutant\n\nFormate la réponse clairement.";

            $result = callMistral('mistral-medium-2508', [
                ['role' => 'system', 'content' => 'Tu es un expert en développement économique africain et en mise en réseau de la diaspora.'],
                ['role' => 'user', 'content' => $prompt]
            ], 700);

            echo json_encode($result['success']
                ? ['success' => true, 'match' => $result['content']]
                : ['success' => false, 'error' => $result['error']]
            );
            exit;
        }

        echo json_encode(['success' => false, 'error' => 'Action inconnue']);

    } catch (Exception $e) {
        error_log('[GRIOT] Exception: ' . $e->getMessage() . PHP_EOL, 3, LOG_PATH);
        echo json_encode(['success' => false, 'error' => 'Erreur serveur. Veuillez réessayer.']);
    }
    exit;
}

// ============================================================
// SESSION
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['griot_session'])) {
    $_SESSION['griot_session'] = bin2hex(random_bytes(16));
}
$sessionId = $_SESSION['griot_session'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>GRIOT DE CRISTAL — Portail UCAA</title>

<!-- CDN GRATUITS -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;600;700;900&family=Space+Mono:wght@400;700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">

<style>
/* =============================================
   DESIGN SYSTEM — GRIOT DE CRISTAL
   Style: 2Advanced · Blanc Futuriste · UCAA
   Palette: Blanc cristal · Or africain · Noir cosmos · Vert jade
============================================= */
:root {
    --crystal-white:   #FFFFFF;
    --off-white:       #F7F8FC;
    --pearl:           #EEF0F8;
    --gold-africa:     #C8962A;
    --gold-light:      #E8B84B;
    --gold-glow:       rgba(200,150,42,0.18);
    --cosmos-black:    #0A0C12;
    --deep-navy:       #0D1020;
    --jade-green:      #1A8A6F;
    --jade-light:      #25C99A;
    --jade-glow:       rgba(37,201,154,0.15);
    --grid-line:       rgba(200,150,42,0.08);
    --text-primary:    #0A0C12;
    --text-secondary:  #3A3D52;
    --text-muted:      #7A7E9A;
    --border-light:    rgba(200,150,42,0.2);
    --shadow-gold:     0 0 40px rgba(200,150,42,0.12);
    --shadow-jade:     0 0 30px rgba(37,201,154,0.1);
    --font-display:    'Orbitron', monospace;
    --font-mono:       'Space Mono', monospace;
    --font-body:       'Inter', sans-serif;
    --radius-sm:       4px;
    --radius-md:       8px;
    --radius-lg:       16px;
    --transition:      all 0.3s cubic-bezier(0.4,0,0.2,1);
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

html { scroll-behavior: smooth; }

body {
    font-family: var(--font-body);
    background: var(--crystal-white);
    color: var(--text-primary);
    overflow-x: hidden;
    line-height: 1.6;
}

/* ── GRID BACKGROUND ─────────────────────── */
body::before {
    content: '';
    position: fixed;
    inset: 0;
    background-image:
        linear-gradient(var(--grid-line) 1px, transparent 1px),
        linear-gradient(90deg, var(--grid-line) 1px, transparent 1px);
    background-size: 60px 60px;
    pointer-events: none;
    z-index: 0;
}

/* ── UTILITY ─────────────────────────────── */
.container { max-width: 1280px; margin: 0 auto; padding: 0 24px; }
.relative  { position: relative; z-index: 1; }

/* ── NAV ─────────────────────────────────── */
nav {
    position: sticky;
    top: 0;
    z-index: 100;
    background: rgba(255,255,255,0.92);
    backdrop-filter: blur(16px);
    border-bottom: 1px solid var(--border-light);
    padding: 0 24px;
}
.nav-inner {
    max-width: 1280px;
    margin: 0 auto;
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 64px;
    gap: 24px;
}
.nav-logo {
    display: flex;
    align-items: center;
    gap: 12px;
    text-decoration: none;
}
.logo-sigil {
    width: 36px; height: 36px;
    border: 2px solid var(--gold-africa);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    position: relative;
    animation: rotateSigil 20s linear infinite;
}
@keyframes rotateSigil {
    from { transform: rotate(0deg); }
    to   { transform: rotate(360deg); }
}
.logo-sigil::after {
    content: '✦';
    color: var(--gold-africa);
    font-size: 14px;
    animation: rotateSigil 20s linear infinite reverse;
}
.logo-text {
    font-family: var(--font-display);
    font-size: 13px;
    font-weight: 700;
    letter-spacing: 2px;
    color: var(--text-primary);
}
.logo-text span { color: var(--gold-africa); }

.nav-links {
    display: flex;
    align-items: center;
    gap: 6px;
    list-style: none;
}
.nav-links a {
    font-family: var(--font-mono);
    font-size: 10px;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: var(--text-secondary);
    text-decoration: none;
    padding: 6px 12px;
    border-radius: var(--radius-sm);
    transition: var(--transition);
    border: 1px solid transparent;
}
.nav-links a:hover,
.nav-links a.active {
    color: var(--gold-africa);
    border-color: var(--border-light);
    background: var(--gold-glow);
}
.nav-cta {
    font-family: var(--font-display);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: var(--crystal-white) !important;
    background: linear-gradient(135deg, var(--gold-africa), var(--gold-light)) !important;
    border: none !important;
    padding: 8px 18px !important;
}
.nav-cta:hover {
    opacity: 0.88;
    transform: translateY(-1px);
}

/* ── HERO ─────────────────────────────────── */
.hero {
    position: relative;
    min-height: 92vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 80px 24px;
    overflow: hidden;
    z-index: 1;
}

/* Mandala africain SVG animé */
.hero-mandala {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 700px;
    height: 700px;
    opacity: 0.04;
    animation: pulseRotate 40s linear infinite;
    pointer-events: none;
}
@keyframes pulseRotate {
    0%   { transform: translate(-50%,-50%) rotate(0deg) scale(1); }
    50%  { transform: translate(-50%,-50%) rotate(180deg) scale(1.05); }
    100% { transform: translate(-50%,-50%) rotate(360deg) scale(1); }
}

.hero-eyebrow {
    font-family: var(--font-mono);
    font-size: 11px;
    letter-spacing: 4px;
    text-transform: uppercase;
    color: var(--gold-africa);
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
}
.hero-eyebrow::before,
.hero-eyebrow::after {
    content: '';
    width: 40px;
    height: 1px;
    background: var(--gold-africa);
    opacity: 0.5;
}

.hero-title {
    font-family: var(--font-display);
    font-size: clamp(36px, 6vw, 82px);
    font-weight: 900;
    line-height: 1.05;
    letter-spacing: -1px;
    color: var(--cosmos-black);
    margin-bottom: 8px;
}
.hero-title .highlight {
    background: linear-gradient(135deg, var(--gold-africa), var(--gold-light));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}
.hero-title .accent {
    color: var(--jade-green);
    -webkit-text-fill-color: var(--jade-green);
}

.hero-subtitle {
    font-family: var(--font-display);
    font-size: clamp(18px, 2.5vw, 28px);
    font-weight: 400;
    color: var(--text-muted);
    letter-spacing: 4px;
    text-transform: uppercase;
    margin-bottom: 28px;
}

.hero-desc {
    max-width: 640px;
    font-size: 17px;
    color: var(--text-secondary);
    line-height: 1.8;
    margin-bottom: 48px;
}

.hero-actions {
    display: flex;
    gap: 16px;
    justify-content: center;
    flex-wrap: wrap;
    margin-bottom: 64px;
}

.btn-primary {
    font-family: var(--font-display);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: var(--crystal-white);
    background: linear-gradient(135deg, var(--gold-africa), var(--gold-light));
    border: none;
    padding: 16px 36px;
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: var(--transition);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    box-shadow: var(--shadow-gold);
}
.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 40px rgba(200,150,42,0.3);
}

.btn-secondary {
    font-family: var(--font-display);
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: var(--text-primary);
    background: transparent;
    border: 1px solid var(--border-light);
    padding: 16px 36px;
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: var(--transition);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 10px;
}
.btn-secondary:hover {
    background: var(--gold-glow);
    border-color: var(--gold-africa);
    color: var(--gold-africa);
}

/* Stats hero */
.hero-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1px;
    max-width: 500px;
    width: 100%;
    background: var(--border-light);
    border: 1px solid var(--border-light);
    border-radius: var(--radius-md);
    overflow: hidden;
}
.stat-item {
    background: var(--crystal-white);
    padding: 20px;
    text-align: center;
}
.stat-value {
    font-family: var(--font-display);
    font-size: 24px;
    font-weight: 900;
    color: var(--gold-africa);
    display: block;
}
.stat-label {
    font-family: var(--font-mono);
    font-size: 9px;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: var(--text-muted);
    margin-top: 4px;
}

/* ── SECTION WRAPPER ─────────────────────── */
section {
    position: relative;
    z-index: 1;
}
.section-header {
    text-align: center;
    margin-bottom: 60px;
}
.section-eyebrow {
    font-family: var(--font-mono);
    font-size: 10px;
    letter-spacing: 4px;
    text-transform: uppercase;
    color: var(--gold-africa);
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}
.section-eyebrow::before,
.section-eyebrow::after {
    content: '';
    width: 30px;
    height: 1px;
    background: var(--gold-africa);
    opacity: 0.4;
}
.section-title {
    font-family: var(--font-display);
    font-size: clamp(24px, 3vw, 42px);
    font-weight: 700;
    color: var(--cosmos-black);
    letter-spacing: -0.5px;
    line-height: 1.2;
}
.section-subtitle {
    font-size: 17px;
    color: var(--text-muted);
    max-width: 560px;
    margin: 12px auto 0;
    line-height: 1.7;
}

/* ── MODULES GRID ────────────────────────── */
#modules { padding: 100px 0; background: var(--off-white); }

.modules-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 2px;
    background: var(--border-light);
    border: 1px solid var(--border-light);
    border-radius: var(--radius-lg);
    overflow: hidden;
}

.module-card {
    background: var(--crystal-white);
    padding: 40px 32px;
    cursor: pointer;
    transition: var(--transition);
    display: flex;
    flex-direction: column;
    gap: 16px;
    border: 2px solid transparent;
    position: relative;
    overflow: hidden;
}
.module-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0;
    width: 3px; height: 0;
    background: linear-gradient(180deg, var(--gold-africa), var(--jade-green));
    transition: height 0.4s ease;
}
.module-card:hover::before,
.module-card.active::before { height: 100%; }

.module-card:hover,
.module-card.active {
    background: var(--off-white);
    border-color: var(--border-light);
    transform: none;
}
.module-card.active { background: var(--pearl); }

.module-icon {
    width: 52px; height: 52px;
    border: 1px solid var(--border-light);
    border-radius: var(--radius-md);
    display: flex; align-items: center; justify-content: center;
    font-size: 22px;
    background: var(--gold-glow);
    transition: var(--transition);
}
.module-card:hover .module-icon,
.module-card.active .module-icon {
    background: linear-gradient(135deg, var(--gold-africa), var(--gold-light));
    border-color: var(--gold-africa);
    transform: scale(1.05);
}

.module-num {
    font-family: var(--font-mono);
    font-size: 10px;
    color: var(--gold-africa);
    letter-spacing: 2px;
}
.module-name {
    font-family: var(--font-display);
    font-size: 15px;
    font-weight: 700;
    color: var(--text-primary);
    line-height: 1.3;
    letter-spacing: 0.5px;
}
.module-desc {
    font-size: 13px;
    color: var(--text-muted);
    line-height: 1.6;
}
.module-tag {
    font-family: var(--font-mono);
    font-size: 9px;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: var(--jade-green);
    background: var(--jade-glow);
    padding: 4px 10px;
    border-radius: 20px;
    border: 1px solid rgba(37,201,154,0.3);
    align-self: flex-start;
}

/* ── GRIOT INTERFACE ─────────────────────── */
#interface { padding: 100px 0; }

.interface-shell {
    background: var(--cosmos-black);
    border: 1px solid rgba(200,150,42,0.25);
    border-radius: var(--radius-lg);
    overflow: hidden;
    box-shadow: var(--shadow-gold), 0 40px 80px rgba(0,0,0,0.12);
}

/* Shell header */
.shell-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 24px;
    background: rgba(255,255,255,0.03);
    border-bottom: 1px solid rgba(200,150,42,0.15);
}
.shell-dots { display: flex; gap: 8px; }
.dot {
    width: 12px; height: 12px;
    border-radius: 50%;
}
.dot-red   { background: #FF5F57; }
.dot-amber { background: #FFBD2E; }
.dot-green { background: #28C840; }

.shell-title {
    font-family: var(--font-mono);
    font-size: 11px;
    color: rgba(255,255,255,0.4);
    letter-spacing: 2px;
}
.shell-status {
    display: flex;
    align-items: center;
    gap: 8px;
    font-family: var(--font-mono);
    font-size: 10px;
    color: var(--jade-light);
    letter-spacing: 1px;
}
.status-pulse {
    width: 8px; height: 8px;
    border-radius: 50%;
    background: var(--jade-light);
    animation: pulseDot 2s ease-in-out infinite;
}
@keyframes pulseDot {
    0%,100% { opacity: 1; transform: scale(1); }
    50%      { opacity: 0.4; transform: scale(0.75); }
}

/* Module tabs */
.module-tabs {
    display: flex;
    border-bottom: 1px solid rgba(200,150,42,0.12);
    overflow-x: auto;
    scrollbar-width: none;
    background: rgba(255,255,255,0.02);
}
.module-tabs::-webkit-scrollbar { display: none; }

.tab-btn {
    font-family: var(--font-mono);
    font-size: 10px;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: rgba(255,255,255,0.35);
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    padding: 14px 20px;
    cursor: pointer;
    transition: var(--transition);
    white-space: nowrap;
    display: flex;
    align-items: center;
    gap: 8px;
}
.tab-btn:hover  { color: rgba(255,255,255,0.7); }
.tab-btn.active {
    color: var(--gold-light);
    border-bottom-color: var(--gold-africa);
    background: rgba(200,150,42,0.06);
}

/* Interface body */
.interface-body { display: flex; height: 620px; }

.chat-panel {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-width: 0;
}

/* Sidebar info */
.interface-sidebar {
    width: 260px;
    flex-shrink: 0;
    border-left: 1px solid rgba(200,150,42,0.12);
    background: rgba(255,255,255,0.015);
    padding: 24px 20px;
    display: flex;
    flex-direction: column;
    gap: 20px;
    overflow-y: auto;
}
.sidebar-block { }
.sidebar-label {
    font-family: var(--font-mono);
    font-size: 9px;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: rgba(255,255,255,0.25);
    margin-bottom: 10px;
}
.sidebar-model {
    font-family: var(--font-mono);
    font-size: 11px;
    color: var(--gold-light);
    background: rgba(200,150,42,0.1);
    border: 1px solid rgba(200,150,42,0.2);
    border-radius: var(--radius-sm);
    padding: 8px 12px;
    letter-spacing: 0.5px;
}
.quick-prompt {
    font-size: 11px;
    color: rgba(255,255,255,0.5);
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: var(--radius-sm);
    padding: 8px 10px;
    cursor: pointer;
    transition: var(--transition);
    line-height: 1.4;
    display: block;
    margin-bottom: 6px;
    width: 100%;
    text-align: left;
}
.quick-prompt:hover {
    background: rgba(200,150,42,0.1);
    border-color: rgba(200,150,42,0.3);
    color: var(--gold-light);
}

/* Chat messages */
.chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 20px;
    scrollbar-width: thin;
    scrollbar-color: rgba(200,150,42,0.2) transparent;
}
.chat-messages::-webkit-scrollbar { width: 4px; }
.chat-messages::-webkit-scrollbar-track { background: transparent; }
.chat-messages::-webkit-scrollbar-thumb { background: rgba(200,150,42,0.2); border-radius: 2px; }

/* Welcome msg */
.msg-welcome {
    text-align: center;
    padding: 40px 20px;
}
.welcome-sigil {
    font-size: 48px;
    display: block;
    margin-bottom: 16px;
    animation: float 3s ease-in-out infinite;
}
@keyframes float {
    0%,100% { transform: translateY(0); }
    50%      { transform: translateY(-8px); }
}
.welcome-title {
    font-family: var(--font-display);
    font-size: 18px;
    font-weight: 700;
    color: rgba(255,255,255,0.9);
    letter-spacing: 1px;
    margin-bottom: 8px;
}
.welcome-subtitle {
    font-size: 13px;
    color: rgba(255,255,255,0.4);
    line-height: 1.6;
}

/* Messages */
.msg {
    display: flex;
    gap: 14px;
    align-items: flex-start;
    animation: slideIn 0.3s ease;
}
@keyframes slideIn {
    from { opacity: 0; transform: translateY(10px); }
    to   { opacity: 1; transform: translateY(0); }
}
.msg-user { flex-direction: row-reverse; }

.msg-avatar {
    width: 34px; height: 34px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
    border: 1px solid rgba(200,150,42,0.3);
    background: rgba(200,150,42,0.1);
}
.msg-user .msg-avatar {
    background: rgba(37,201,154,0.15);
    border-color: rgba(37,201,154,0.3);
}

.msg-bubble {
    max-width: 72%;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.07);
    border-radius: 0 var(--radius-md) var(--radius-md) var(--radius-md);
    padding: 14px 18px;
    font-size: 13.5px;
    color: rgba(255,255,255,0.85);
    line-height: 1.7;
}
.msg-user .msg-bubble {
    background: rgba(37,201,154,0.1);
    border-color: rgba(37,201,154,0.2);
    border-radius: var(--radius-md) 0 var(--radius-md) var(--radius-md);
    color: rgba(255,255,255,0.9);
}

/* Typing indicator */
.typing-indicator {
    display: none;
    gap: 14px;
    align-items: center;
}
.typing-indicator.visible { display: flex; }
.typing-dots { display: flex; gap: 5px; padding: 14px 18px; }
.typing-dots span {
    width: 7px; height: 7px;
    background: var(--gold-africa);
    border-radius: 50%;
    animation: typingBounce 1.4s ease-in-out infinite;
}
.typing-dots span:nth-child(2) { animation-delay: 0.2s; }
.typing-dots span:nth-child(3) { animation-delay: 0.4s; }
@keyframes typingBounce {
    0%,80%,100% { transform: scale(0.6); opacity: 0.4; }
    40%          { transform: scale(1); opacity: 1; }
}

/* Chat input */
.chat-input-bar {
    padding: 16px 24px;
    border-top: 1px solid rgba(200,150,42,0.12);
    display: flex;
    gap: 12px;
    align-items: flex-end;
    background: rgba(255,255,255,0.02);
}
.chat-input {
    flex: 1;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: var(--radius-md);
    padding: 12px 16px;
    font-family: var(--font-body);
    font-size: 14px;
    color: rgba(255,255,255,0.9);
    resize: none;
    outline: none;
    transition: var(--transition);
    min-height: 48px;
    max-height: 120px;
}
.chat-input::placeholder { color: rgba(255,255,255,0.25); }
.chat-input:focus {
    border-color: rgba(200,150,42,0.4);
    background: rgba(255,255,255,0.07);
}
.send-btn {
    width: 48px; height: 48px;
    background: linear-gradient(135deg, var(--gold-africa), var(--gold-light));
    border: none;
    border-radius: var(--radius-md);
    color: white;
    font-size: 18px;
    cursor: pointer;
    transition: var(--transition);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.send-btn:hover { transform: scale(1.05); opacity: 0.9; }
.send-btn:disabled { opacity: 0.4; cursor: not-allowed; transform: none; }

/* ── PANEL ALTERNATIFS (Vigilance, HipHop, etc.) ── */
.alt-panel {
    display: none;
    flex-direction: column;
    gap: 0;
    height: 100%;
    overflow-y: auto;
    padding: 24px;
    scrollbar-width: thin;
    scrollbar-color: rgba(200,150,42,0.2) transparent;
}
.alt-panel.active { display: flex; }

.form-group { margin-bottom: 18px; }
.form-label {
    font-family: var(--font-mono);
    font-size: 10px;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: rgba(255,255,255,0.4);
    display: block;
    margin-bottom: 8px;
}
.form-input,
.form-select,
.form-textarea {
    width: 100%;
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: var(--radius-sm);
    padding: 12px 14px;
    font-family: var(--font-body);
    font-size: 14px;
    color: rgba(255,255,255,0.85);
    outline: none;
    transition: var(--transition);
}
.form-input:focus,
.form-select:focus,
.form-textarea:focus {
    border-color: rgba(200,150,42,0.4);
    background: rgba(255,255,255,0.07);
}
.form-select option { background: var(--cosmos-black); }
.form-textarea { resize: vertical; min-height: 90px; }

.btn-action {
    font-family: var(--font-display);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: var(--cosmos-black);
    background: linear-gradient(135deg, var(--gold-africa), var(--gold-light));
    border: none;
    padding: 14px 28px;
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: var(--transition);
    display: flex; align-items: center; gap: 10px;
}
.btn-action:hover { transform: translateY(-1px); opacity: 0.9; }
.btn-action:disabled { opacity: 0.4; cursor: not-allowed; transform: none; }

.ai-result-box {
    background: rgba(200,150,42,0.06);
    border: 1px solid rgba(200,150,42,0.2);
    border-radius: var(--radius-md);
    padding: 20px;
    margin-top: 16px;
    font-size: 13.5px;
    color: rgba(255,255,255,0.8);
    line-height: 1.8;
    white-space: pre-wrap;
    display: none;
    animation: slideIn 0.4s ease;
}
.ai-result-box.visible { display: block; }

/* ── ARCHIVES VIVANTES ────────────────────── */
.archives-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
    margin-bottom: 20px;
}
.archive-figure {
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.07);
    border-radius: var(--radius-sm);
    padding: 12px;
    cursor: pointer;
    transition: var(--transition);
    text-align: center;
}
.archive-figure:hover {
    background: rgba(200,150,42,0.08);
    border-color: rgba(200,150,42,0.3);
}
.archive-figure.selected {
    background: rgba(200,150,42,0.12);
    border-color: var(--gold-africa);
}
.archive-emoji { font-size: 28px; display: block; margin-bottom: 6px; }
.archive-name {
    font-family: var(--font-mono);
    font-size: 9px;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: rgba(255,255,255,0.5);
    line-height: 1.3;
}

/* ── VIGILANCE MODULE ─────────────────────── */
.vigilance-categories {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 6px;
    margin-bottom: 20px;
}
.vcat-btn {
    font-family: var(--font-mono);
    font-size: 10px;
    letter-spacing: 1px;
    text-transform: uppercase;
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: var(--radius-sm);
    color: rgba(255,255,255,0.5);
    padding: 10px;
    cursor: pointer;
    transition: var(--transition);
    text-align: center;
}
.vcat-btn:hover, .vcat-btn.selected {
    background: rgba(200,150,42,0.1);
    border-color: rgba(200,150,42,0.35);
    color: var(--gold-light);
}

/* ── SECTION: SOUVERAINETÉ ─────────────────── */
#souverainete { padding: 100px 0; background: var(--cosmos-black); color: white; }
#souverainete .section-title { color: white; }
#souverainete .section-subtitle { color: rgba(255,255,255,0.5); }
#souverainete .section-eyebrow { color: var(--gold-light); }
#souverainete .section-eyebrow::before,
#souverainete .section-eyebrow::after { background: var(--gold-light); }

.pillars-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 2px;
    background: rgba(200,150,42,0.1);
    border: 1px solid rgba(200,150,42,0.2);
    border-radius: var(--radius-lg);
    overflow: hidden;
    margin-top: 50px;
}
.pillar-card {
    background: var(--cosmos-black);
    padding: 40px 28px;
    transition: var(--transition);
    border: 2px solid transparent;
}
.pillar-card:hover {
    background: #101320;
    border-color: rgba(200,150,42,0.2);
}
.pillar-number {
    font-family: var(--font-display);
    font-size: 11px;
    color: rgba(200,150,42,0.4);
    letter-spacing: 3px;
    margin-bottom: 20px;
}
.pillar-icon {
    font-size: 32px;
    display: block;
    margin-bottom: 16px;
}
.pillar-title {
    font-family: var(--font-display);
    font-size: 16px;
    font-weight: 700;
    color: white;
    margin-bottom: 12px;
    letter-spacing: 0.5px;
}
.pillar-text {
    font-size: 13px;
    color: rgba(255,255,255,0.45);
    line-height: 1.7;
}

/* ── DOJO VIRTUEL ────────────────────────── */
#dojo { padding: 100px 0; }

.dojo-layout {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 40px;
    align-items: start;
}
.dojo-principle {
    padding: 24px;
    border: 1px solid var(--border-light);
    border-radius: var(--radius-md);
    background: var(--off-white);
    transition: var(--transition);
}
.dojo-principle:hover {
    border-color: var(--gold-africa);
    background: var(--gold-glow);
    transform: translateX(4px);
}
.principle-symbol {
    font-family: var(--font-display);
    font-size: 28px;
    font-weight: 900;
    color: var(--gold-africa);
    display: block;
    margin-bottom: 8px;
}
.principle-name {
    font-family: var(--font-display);
    font-size: 14px;
    font-weight: 700;
    color: var(--text-primary);
    letter-spacing: 1px;
    margin-bottom: 6px;
}
.principle-text {
    font-size: 13px;
    color: var(--text-muted);
    line-height: 1.6;
}

/* ── FOOTER ──────────────────────────────── */
footer {
    background: var(--cosmos-black);
    padding: 60px 0 30px;
    position: relative;
    z-index: 1;
}
.footer-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 40px;
    flex-wrap: wrap;
    padding-bottom: 40px;
    border-bottom: 1px solid rgba(200,150,42,0.12);
    margin-bottom: 30px;
}
.footer-brand { max-width: 300px; }
.footer-logo {
    font-family: var(--font-display);
    font-size: 18px;
    font-weight: 900;
    color: white;
    letter-spacing: 2px;
    margin-bottom: 12px;
}
.footer-logo span { color: var(--gold-africa); }
.footer-tagline {
    font-size: 13px;
    color: rgba(255,255,255,0.4);
    line-height: 1.7;
}
.footer-col-title {
    font-family: var(--font-mono);
    font-size: 10px;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: rgba(255,255,255,0.25);
    margin-bottom: 16px;
}
.footer-links { list-style: none; }
.footer-links li {
    margin-bottom: 8px;
}
.footer-links a {
    font-size: 13px;
    color: rgba(255,255,255,0.45);
    text-decoration: none;
    transition: var(--transition);
}
.footer-links a:hover { color: var(--gold-light); }

.footer-bottom {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
}
.footer-copy {
    font-family: var(--font-mono);
    font-size: 10px;
    color: rgba(255,255,255,0.2);
    letter-spacing: 1px;
}
.footer-tech {
    font-family: var(--font-mono);
    font-size: 10px;
    color: rgba(200,150,42,0.5);
    letter-spacing: 1px;
}

/* ── TOAST ───────────────────────────────── */
.toast {
    position: fixed;
    bottom: 30px;
    right: 30px;
    background: var(--cosmos-black);
    border: 1px solid rgba(200,150,42,0.4);
    border-radius: var(--radius-md);
    padding: 14px 20px;
    font-family: var(--font-mono);
    font-size: 11px;
    color: var(--gold-light);
    letter-spacing: 1px;
    z-index: 999;
    transform: translateX(120%);
    transition: transform 0.4s cubic-bezier(0.4,0,0.2,1);
    max-width: 320px;
}
.toast.visible { transform: translateX(0); }

/* ── LOADING OVERLAY ─────────────────────── */
.loading-overlay {
    position: fixed;
    inset: 0;
    background: rgba(255,255,255,0.95);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    transition: opacity 0.5s ease;
}
.loading-overlay.hidden { opacity: 0; pointer-events: none; }
.loader-ring {
    width: 64px; height: 64px;
    border: 3px solid var(--pearl);
    border-top-color: var(--gold-africa);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
    margin-bottom: 24px;
}
@keyframes spin { to { transform: rotate(360deg); } }
.loader-text {
    font-family: var(--font-display);
    font-size: 12px;
    letter-spacing: 3px;
    text-transform: uppercase;
    color: var(--text-muted);
}

/* ── RESPONSIVE ──────────────────────────── */
@media (max-width: 900px) {
    .interface-body { flex-direction: column; height: auto; }
    .interface-sidebar { width: 100%; border-left: none; border-top: 1px solid rgba(200,150,42,0.12); height: auto; }
    .chat-panel { min-height: 420px; }
    .dojo-layout { grid-template-columns: 1fr; }
    .archives-grid { grid-template-columns: repeat(2,1fr); }
    .nav-links { display: none; }
    .footer-top { flex-direction: column; }
}
@media (max-width: 600px) {
    .hero-stats { grid-template-columns: 1fr; }
    .hero-actions { flex-direction: column; align-items: center; }
    .pillars-grid { grid-template-columns: 1fr; }
    .vigilance-categories { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<!-- Loading -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="loader-ring"></div>
    <div class="loader-text">Initialisation du Griot</div>
</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<!-- ═══ NAV ═══════════════════════════════════════════ -->
<nav>
    <div class="nav-inner">
        <a href="#" class="nav-logo">
            <div class="logo-sigil"></div>
            <span class="logo-text">GRIOT DE <span>CRISTAL</span></span>
        </a>
        <ul class="nav-links">
            <li><a href="#modules" class="active">Modules</a></li>
            <li><a href="#interface">IA Griot</a></li>
            <li><a href="#souverainete">Souveraineté</a></li>
            <li><a href="#dojo">Dojo</a></li>
            <li><a href="#" class="nav-cta">Rejoindre UCAA</a></li>
        </ul>
    </div>
</nav>

<!-- ═══ HERO ══════════════════════════════════════════ -->
<section class="hero" id="home">
    <!-- Mandala SVG -->
    <svg class="hero-mandala" viewBox="0 0 500 500" xmlns="http://www.w3.org/2000/svg">
        <g fill="none" stroke="#C8962A" stroke-width="1">
            <circle cx="250" cy="250" r="240"/><circle cx="250" cy="250" r="200"/>
            <circle cx="250" cy="250" r="160"/><circle cx="250" cy="250" r="120"/>
            <circle cx="250" cy="250" r="80"/><circle cx="250" cy="250" r="40"/>
            <line x1="10" y1="250" x2="490" y2="250"/>
            <line x1="250" y1="10" x2="250" y2="490"/>
            <line x1="80" y1="80" x2="420" y2="420"/>
            <line x1="420" y1="80" x2="80" y2="420"/>
            <polygon points="250,30 460,370 40,370"/>
            <polygon points="250,470 460,130 40,130"/>
        </g>
    </svg>

    <div class="relative">
        <p class="hero-eyebrow animate__animated animate__fadeInDown">UCAA · Portail Panafricaniste IA · v2.0</p>
        <h1 class="hero-title animate__animated animate__fadeInUp">
            <span class="highlight">GRIOT</span><br>
            <span>DE</span> <span class="accent">CRISTAL</span>
        </h1>
        <p class="hero-subtitle animate__animated animate__fadeInUp" style="animation-delay:.1s">Conscience Collective Numérique</p>
        <p class="hero-desc animate__animated animate__fadeIn" style="animation-delay:.2s">
            L'intelligence artificielle au service de l'unité africaine et afro-descendante. Transmission, souveraineté, dignité — une architecture politique, économique et culturelle pour un peuple debout.
        </p>
        <div class="hero-actions animate__animated animate__fadeInUp" style="animation-delay:.3s">
            <a href="#interface" class="btn-primary">
                <i class="fas fa-microchip"></i> Activer le Griot
            </a>
            <a href="#modules" class="btn-secondary">
                <i class="fas fa-th"></i> Explorer les Modules
            </a>
        </div>
        <div class="hero-stats animate__animated animate__fadeIn" style="animation-delay:.4s">
            <div class="stat-item">
                <span class="stat-value">5</span>
                <span class="stat-label">Modules IA</span>
            </div>
            <div class="stat-item">
                <span class="stat-value">54+</span>
                <span class="stat-label">Langues Africaines</span>
            </div>
            <div class="stat-item">
                <span class="stat-value">∞</span>
                <span class="stat-label">Mémoire Vivante</span>
            </div>
        </div>
    </div>
</section>

<!-- ═══ MODULES ═══════════════════════════════════════ -->
<section id="modules">
    <div class="container relative">
        <div class="section-header">
            <p class="section-eyebrow">Architecture IA</p>
            <h2 class="section-title">Cinq Piliers d'Élévation</h2>
            <p class="section-subtitle">Chaque module est une arme de libération culturelle, économique et juridique.</p>
        </div>

        <div class="modules-grid">
            <div class="module-card active" data-module="griot" onclick="selectModule(this)">
                <span class="module-num">// 01</span>
                <div class="module-icon">🔮</div>
                <h3 class="module-name">Le Griot de Cristal</h3>
                <p class="module-desc">IA conversationnelle incarnant la sagesse africaine. Répond avec la voix des ancêtres, transmet la mémoire des civilisations, valorise les héros oubliés.</p>
                <span class="module-tag">Chat IA · Mémoire</span>
            </div>
            <div class="module-card" data-module="contre_enquete" onclick="selectModule(this)">
                <span class="module-num">// 02</span>
                <div class="module-icon">🔍</div>
                <h3 class="module-name">Contre-Enquêtes</h3>
                <p class="module-desc">Déconstruction en temps réel des récits médiatiques biaisés. Analyse critique des manipulations historiques et construction de contre-narratifs.</p>
                <span class="module-tag">Analyse · Vérité</span>
            </div>
            <div class="module-card" data-module="archive_vivante" onclick="selectModule(this)">
                <span class="module-num">// 03</span>
                <div class="module-icon">🏛️</div>
                <h3 class="module-name">Archives Vivantes</h3>
                <p class="module-desc">L'IA incarne les grandes figures africaines. Parlez directement à Sankara, Lumumba, Cheikh Anta Diop, Harriet Tubman — leurs paroles vibrent encore.</p>
                <span class="module-tag">Incarnation · Histoire</span>
            </div>
            <div class="module-card" data-module="conseiller_syndical" onclick="selectModule(this)">
                <span class="module-num">// 04</span>
                <div class="module-icon">⚖️</div>
                <h3 class="module-name">Conseiller Syndical</h3>
                <p class="module-desc">Assistance 24/7 pour les travailleurs, artistes, étudiants. Rédaction de recours, connaissance des droits, organisation d'actions collectives légales.</p>
                <span class="module-tag">Droit · Protection</span>
            </div>
            <div class="module-card" data-module="vigilance" onclick="selectModule(this)">
                <span class="module-num">// 05</span>
                <div class="module-icon">🛡️</div>
                <h3 class="module-name">Vigilance Citoyenne</h3>
                <p class="module-desc">Signalement et analyse IA des injustices. L'algorithme évalue la gravité et propose les moyens d'intervention : juridique, médiatique, collectif.</p>
                <span class="module-tag">Signalement · Action</span>
            </div>
            <div class="module-card" data-module="hiphop" onclick="selectModule(this)">
                <span class="module-num">// 06</span>
                <div class="module-icon">🎤</div>
                <h3 class="module-name">Labo Hip-Hop Politique</h3>
                <p class="module-desc">Création de paroles militantes. L'IA refuse la dégradation culturelle et compose un rap porteur de souveraineté, de justice et de dignité africaine.</p>
                <span class="module-tag">Création · Culture</span>
            </div>
        </div>
    </div>
</section>

<!-- ═══ INTERFACE GRIOT ════════════════════════════════ -->
<section id="interface">
    <div class="container relative">
        <div class="section-header">
            <p class="section-eyebrow">Intelligence Artificielle</p>
            <h2 class="section-title">Interface du Griot</h2>
            <p class="section-subtitle">Cinq intelligences spécialisées, un seul but : l'élévation du peuple africain.</p>
        </div>

        <div class="interface-shell">
            <!-- Shell chrome -->
            <div class="shell-header">
                <div class="shell-dots">
                    <div class="dot dot-red"></div>
                    <div class="dot dot-amber"></div>
                    <div class="dot dot-green"></div>
                </div>
                <span class="shell-title">GRIOT_CRISTAL_v2.0 — UCAA Intelligence System</span>
                <div class="shell-status">
                    <div class="status-pulse"></div>
                    MISTRAL LARGE ONLINE
                </div>
            </div>

            <!-- Tabs -->
            <div class="module-tabs">
                <button class="tab-btn active" data-tab="chat-panel" onclick="switchTab(this,'chat-panel')">
                    🔮 Griot Chat
                </button>
                <button class="tab-btn" data-tab="archives-panel" onclick="switchTab(this,'archives-panel')">
                    🏛️ Archives Vivantes
                </button>
                <button class="tab-btn" data-tab="vigilance-panel" onclick="switchTab(this,'vigilance-panel')">
                    🛡️ Vigilance
                </button>
                <button class="tab-btn" data-tab="hiphop-panel" onclick="switchTab(this,'hiphop-panel')">
                    🎤 Labo Hip-Hop
                </button>
                <button class="tab-btn" data-tab="traduction-panel" onclick="switchTab(this,'traduction-panel')">
                    🌍 Traduction
                </button>
            </div>

            <!-- Body -->
            <div class="interface-body">

                <!-- ── CHAT PANEL ─────────────────────── -->
                <div class="chat-panel" id="chat-panel">
                    <div class="chat-messages" id="chatMessages">
                        <div class="msg-welcome">
                            <span class="welcome-sigil">🔮</span>
                            <p class="welcome-title">Le Griot de Cristal vous attend</p>
                            <p class="welcome-subtitle">Posez vos questions sur l'histoire africaine, la diaspora, la culture, les luttes. Demandez une contre-enquête, consultez le conseiller syndical.</p>
                        </div>
                    </div>
                    <div class="typing-indicator" id="typingIndicator">
                        <div class="msg-avatar">🔮</div>
                        <div class="typing-dots"><span></span><span></span><span></span></div>
                    </div>
                    <div class="chat-input-bar">
                        <textarea class="chat-input" id="chatInput" placeholder="Parlez au Griot de Cristal..." rows="1"
                            onkeydown="handleChatKey(event)"></textarea>
                        <button class="send-btn" id="sendBtn" onclick="sendMessage()">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </div>
                </div>

                <!-- ── SIDEBAR ─────────────────────────── -->
                <div class="interface-sidebar">
                    <div class="sidebar-block">
                        <p class="sidebar-label">Modèle actif</p>
                        <div class="sidebar-model" id="activeModel">mistral-large-2512</div>
                    </div>
                    <div class="sidebar-block">
                        <p class="sidebar-label">Mode actuel</p>
                        <div class="sidebar-model" id="currentMode" style="color: var(--jade-light);">GRIOT</div>
                    </div>
                    <div class="sidebar-block">
                        <p class="sidebar-label">Accès rapide</p>
                        <button class="quick-prompt" onclick="quickPrompt('Parle-moi de Cheikh Anta Diop et sa thèse sur les origines africaines de la civilisation égyptienne.')">
                            🎓 Cheikh Anta Diop
                        </button>
                        <button class="quick-prompt" onclick="quickPrompt('Quelles sont les grandes civilisations africaines méconnues qui ont précédé l\'Europe ?')">
                            🏛️ Civilisations méconnues
                        </button>
                        <button class="quick-prompt" onclick="quickPrompt('Explique-moi comment déconstruire le récit colonial dans les manuels scolaires français.')">
                            📚 Déconstruction scolaire
                        </button>
                        <button class="quick-prompt" onclick="quickPrompt('Je subis du racisme au travail, quels sont mes droits et recours légaux en France ?')">
                            ⚖️ Droits au travail
                        </button>
                        <button class="quick-prompt" onclick="quickPrompt('Quel a été l\'impact de la traite négrière sur le développement économique de l\'Afrique subsaharienne ?')">
                            📊 Impact économique
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── ARCHIVES PANEL ──────────────────────── -->
        <div id="archives-panel" class="interface-shell" style="margin-top:16px; display:none;">
            <div class="shell-header">
                <div class="shell-dots"><div class="dot dot-red"></div><div class="dot dot-amber"></div><div class="dot dot-green"></div></div>
                <span class="shell-title">ARCHIVES VIVANTES — Parlez aux Ancêtres</span>
                <div class="shell-status"><div class="status-pulse"></div>MÉMOIRE ACTIVE</div>
            </div>
            <div style="padding:24px;">
                <p style="font-family:var(--font-mono);font-size:10px;letter-spacing:2px;color:rgba(255,255,255,0.3);text-transform:uppercase;margin-bottom:16px;">Choisissez une figure</p>
                <div class="archives-grid" id="archivesGrid">
                    <div class="archive-figure" data-figure="Thomas Sankara" onclick="selectFigure(this)">
                        <span class="archive-emoji">✊</span>
                        <span class="archive-name">Thomas Sankara</span>
                    </div>
                    <div class="archive-figure" data-figure="Cheikh Anta Diop" onclick="selectFigure(this)">
                        <span class="archive-emoji">📚</span>
                        <span class="archive-name">Cheikh Anta Diop</span>
                    </div>
                    <div class="archive-figure" data-figure="Patrice Lumumba" onclick="selectFigure(this)">
                        <span class="archive-emoji">🌟</span>
                        <span class="archive-name">Patrice Lumumba</span>
                    </div>
                    <div class="archive-figure" data-figure="Harriet Tubman" onclick="selectFigure(this)">
                        <span class="archive-emoji">🗝️</span>
                        <span class="archive-name">Harriet Tubman</span>
                    </div>
                    <div class="archive-figure" data-figure="Toussaint Louverture" onclick="selectFigure(this)">
                        <span class="archive-emoji">⚔️</span>
                        <span class="archive-name">Toussaint Louverture</span>
                    </div>
                    <div class="archive-figure" data-figure="Amilcar Cabral" onclick="selectFigure(this)">
                        <span class="archive-emoji">🌱</span>
                        <span class="archive-name">Amilcar Cabral</span>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Votre question pour cette figure</label>
                    <textarea class="form-textarea" id="archivesQuestion" placeholder="Que pensez-vous de la situation actuelle en Afrique ? Quel message adressez-vous à la jeunesse ?"></textarea>
                </div>
                <button class="btn-action" onclick="callArchive()">
                    <i class="fas fa-ghost"></i> Invoquer la mémoire
                </button>
                <div class="ai-result-box" id="archivesResult"></div>
            </div>
        </div>

        <!-- ── VIGILANCE PANEL ─────────────────────── -->
        <div id="vigilance-panel" class="interface-shell" style="margin-top:16px; display:none;">
            <div class="shell-header">
                <div class="shell-dots"><div class="dot dot-red"></div><div class="dot dot-amber"></div><div class="dot dot-green"></div></div>
                <span class="shell-title">VIGILANCE CITOYENNE — Signalement IA</span>
                <div class="shell-status"><div class="status-pulse"></div>MAGISTRAL MEDIUM</div>
            </div>
            <div style="padding:24px;">
                <p style="font-family:var(--font-mono);font-size:10px;letter-spacing:2px;color:rgba(255,255,255,0.3);text-transform:uppercase;margin-bottom:16px;">Catégorie de l'injustice</p>
                <div class="vigilance-categories" id="vigilanceCategories">
                    <button class="vcat-btn selected" data-cat="discrimination_raciale" onclick="selectVcat(this)">Discrimination raciale</button>
                    <button class="vcat-btn" data-cat="discrimination_professionnelle" onclick="selectVcat(this)">Discrim. professionnelle</button>
                    <button class="vcat-btn" data-cat="violence_policiere" onclick="selectVcat(this)">Violence policière</button>
                    <button class="vcat-btn" data-cat="injustice_judiciaire" onclick="selectVcat(this)">Injustice judiciaire</button>
                    <button class="vcat-btn" data-cat="biais_mediatique" onclick="selectVcat(this)">Biais médiatique</button>
                    <button class="vcat-btn" data-cat="autre" onclick="selectVcat(this)">Autre injustice</button>
                </div>
                <div class="form-group">
                    <label class="form-label">Votre nom (optionnel)</label>
                    <input class="form-input" type="text" id="vigilanceName" placeholder="Anonyme">
                </div>
                <div class="form-group">
                    <label class="form-label">Description détaillée de l'injustice</label>
                    <textarea class="form-textarea" id="vigilanceDesc" style="min-height:120px;" placeholder="Décrivez les faits, le contexte, les personnes impliquées, les témoins, les documents disponibles..."></textarea>
                </div>
                <button class="btn-action" onclick="submitVigilance()">
                    <i class="fas fa-shield-alt"></i> Analyser et signaler
                </button>
                <div class="ai-result-box" id="vigilanceResult"></div>
            </div>
        </div>

        <!-- ── HIP-HOP PANEL ───────────────────────── -->
        <div id="hiphop-panel" class="interface-shell" style="margin-top:16px; display:none;">
            <div class="shell-header">
                <div class="shell-dots"><div class="dot dot-red"></div><div class="dot dot-amber"></div><div class="dot dot-green"></div></div>
                <span class="shell-title">LABO HIP-HOP — Création Militante</span>
                <div class="shell-status"><div class="status-pulse"></div>CREATIVE WRITER MODE</div>
            </div>
            <div style="padding:24px;">
                <div class="form-group">
                    <label class="form-label">Thème des paroles</label>
                    <input class="form-input" type="text" id="hiphopTheme" placeholder="ex: La résistance des peuples noirs, souveraineté africaine, mémoire de l'esclavage...">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                    <div class="form-group">
                        <label class="form-label">Style</label>
                        <select class="form-select" id="hiphopStyle">
                            <option value="rap conscient">Rap Conscient</option>
                            <option value="afrobeat poétique">Afrobeat Poétique</option>
                            <option value="slam militant">Slam Militant</option>
                            <option value="spoken word">Spoken Word</option>
                            <option value="griots moderne">Griot Moderne</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Langue</label>
                        <select class="form-select" id="hiphopLangue">
                            <option value="français">Français</option>
                            <option value="français créole mélangé">Français/Créole</option>
                            <option value="anglais américain noir (AAVE)">Anglais AAVE</option>
                            <option value="wolof franglais">Wolof/Franglais</option>
                        </select>
                    </div>
                </div>
                <button class="btn-action" onclick="generateHiphop()">
                    <i class="fas fa-microphone-alt"></i> Composer
                </button>
                <div class="ai-result-box" id="hiphopResult"></div>
            </div>
        </div>

        <!-- ── TRADUCTION PANEL ────────────────────── -->
        <div id="traduction-panel" class="interface-shell" style="margin-top:16px; display:none;">
            <div class="shell-header">
                <div class="shell-dots"><div class="dot dot-red"></div><div class="dot dot-amber"></div><div class="dot dot-green"></div></div>
                <span class="shell-title">TRADUCTION UNIVERSELLE — Unité des Peuples</span>
                <div class="shell-status"><div class="status-pulse"></div>MISTRAL LARGE ACTIVE</div>
            </div>
            <div style="padding:24px;">
                <div class="form-group">
                    <label class="form-label">Texte à traduire</label>
                    <textarea class="form-textarea" id="translationText" placeholder="Entrez votre texte en français, anglais ou dans toute autre langue..."></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Langue cible</label>
                    <select class="form-select" id="translationTarget">
                        <option value="Wolof (Sénégal)">Wolof — Sénégal</option>
                        <option value="Lingala (Congo)">Lingala — Congo</option>
                        <option value="Kinyarwanda (Rwanda)">Kinyarwanda — Rwanda</option>
                        <option value="Amharique (Éthiopie)">Amharique — Éthiopie</option>
                        <option value="Hausa (Afrique de l'Ouest)">Hausa — Afrique Ouest</option>
                        <option value="Swahili (Afrique de l'Est)">Swahili — Afrique Est</option>
                        <option value="Zulu (Afrique du Sud)">Zulu — Afrique du Sud</option>
                        <option value="Bambara (Mali)">Bambara — Mali</option>
                        <option value="Créole haïtien">Créole Haïtien</option>
                        <option value="Créole martiniquais">Créole Martiniquais</option>
                        <option value="Anglais">Anglais</option>
                        <option value="Portugais">Portugais (diaspora)</option>
                    </select>
                </div>
                <button class="btn-action" onclick="translateText()">
                    <i class="fas fa-language"></i> Traduire
                </button>
                <div class="ai-result-box" id="translationResult"></div>
            </div>
        </div>

    </div>
</section>

<!-- ═══ SOUVERAINETÉ ÉCONOMIQUE ════════════════════════ -->
<section id="souverainete">
    <div class="container relative">
        <div class="section-header">
            <p class="section-eyebrow">Mission UCAA</p>
            <h2 class="section-title">Souveraineté Économique<br>& Sociale</h2>
            <p class="section-subtitle">Reprendre le contrôle de ce qui est produit par les populations noires.</p>
        </div>
        <div class="pillars-grid">
            <div class="pillar-card">
                <p class="pillar-number">01</p>
                <span class="pillar-icon">🤝</span>
                <h3 class="pillar-title">Réseau Solidaire</h3>
                <p class="pillar-text">Annuaire IA des entreprises africaines et afro-descendantes. L'algorithme apparie les besoins des membres avec les compétences du réseau.</p>
            </div>
            <div class="pillar-card">
                <p class="pillar-number">02</p>
                <span class="pillar-icon">⚖️</span>
                <h3 class="pillar-title">Conseiller Syndical</h3>
                <p class="pillar-text">Assistance juridique 24/7. Rédaction de plaintes, organisation d'actions collectives, blocus économiques légaux et défense des droits fondamentaux.</p>
            </div>
            <div class="pillar-card">
                <p class="pillar-number">03</p>
                <span class="pillar-icon">🎯</span>
                <h3 class="pillar-title">Token Réel UCAA</h3>
                <p class="pillar-text">L'achat solidaire devient clé d'entrée numérique. Chaque article UCAA acquis déverrouille les fonctions IA avancées du portail.</p>
            </div>
            <div class="pillar-card">
                <p class="pillar-number">04</p>
                <span class="pillar-icon">📡</span>
                <h3 class="pillar-title">Souveraineté des Données</h3>
                <p class="pillar-text">Architecture indépendante garantissant que les données des membres ne sont pas exploitées par les systèmes de pouvoir dominants.</p>
            </div>
        </div>
    </div>
</section>

<!-- ═══ DOJO VIRTUEL ═══════════════════════════════════ -->
<section id="dojo">
    <div class="container relative">
        <div class="section-header">
            <p class="section-eyebrow">Force du Dragon · Jo Dalton</p>
            <h2 class="section-title">Dojo Virtuel<br>& Maîtrise de l'Esprit</h2>
            <p class="section-subtitle">Peace. Love. Unity. Les trois principes d'un mouvement invincible.</p>
        </div>
        <div class="dojo-layout">
            <div>
                <div class="dojo-principle">
                    <span class="principle-symbol">和</span>
                    <p class="principle-name">PEACE — La Paix Combattante</p>
                    <p class="principle-text">La paix n'est pas l'absence de conflit, c'est la maîtrise de soi face au conflit. Le guerrier qui contrôle ses émotions contrôle le champ de bataille.</p>
                </div>
                <div class="dojo-principle" style="margin-top:12px;">
                    <span class="principle-symbol">愛</span>
                    <p class="principle-name">LOVE — L'Amour Stratégique</p>
                    <p class="principle-text">L'amour du peuple est le moteur de toute action juste. Il transforme la résistance individuelle en force collective indestructible.</p>
                </div>
                <div class="dojo-principle" style="margin-top:12px;">
                    <span class="principle-symbol">統</span>
                    <p class="principle-name">UNITY — L'Unité Absolue</p>
                    <p class="principle-text">Cinquante-quatre nations, une âme. L'unité panafricaine est la condition nécessaire à toute souveraineté réelle et durable.</p>
                </div>
            </div>
            <div>
                <div style="background:var(--cosmos-black);border:1px solid rgba(200,150,42,0.2);border-radius:var(--radius-lg);padding:40px;text-align:center;">
                    <div style="font-size:72px;margin-bottom:24px;">🥋</div>
                    <h3 style="font-family:var(--font-display);font-size:20px;font-weight:700;color:white;letter-spacing:1px;margin-bottom:16px;">BLACK DRAGONS</h3>
                    <p style="font-size:14px;color:rgba(255,255,255,0.5);line-height:1.8;margin-bottom:28px;">Le Taekwondo comme philosophie de vie. Jo Dalton enseigne que la discipline martiale est la première étape de la libération politique. Corps fort, esprit libre, peuple debout.</p>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div style="background:rgba(200,150,42,0.08);border:1px solid rgba(200,150,42,0.2);border-radius:var(--radius-sm);padding:16px;">
                            <span style="font-family:var(--font-display);font-size:20px;font-weight:900;color:var(--gold-light);display:block;">Cœur de Gang</span>
                            <span style="font-family:var(--font-mono);font-size:9px;color:rgba(255,255,255,0.3);letter-spacing:1px;">DOCUMENTAIRE</span>
                        </div>
                        <div style="background:rgba(37,201,154,0.06);border:1px solid rgba(37,201,154,0.2);border-radius:var(--radius-sm);padding:16px;">
                            <span style="font-family:var(--font-display);font-size:20px;font-weight:900;color:var(--jade-light);display:block;">Dissidence Noire</span>
                            <span style="font-family:var(--font-mono);font-size:9px;color:rgba(255,255,255,0.3);letter-spacing:1px;">PODCAST</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ═══ FOOTER ════════════════════════════════════════ -->
<footer>
    <div class="container">
        <div class="footer-top">
            <div class="footer-brand">
                <p class="footer-logo">GRIOT DE <span>CRISTAL</span></p>
                <p class="footer-tagline">Portail Panafricaniste IA · UCAA<br>Unité Culturelle Africaine & Afro-descendants.<br>La conscience numérique d'un peuple debout.</p>
            </div>
            <div>
                <p class="footer-col-title">Modules IA</p>
                <ul class="footer-links">
                    <li><a href="#interface">Le Griot de Cristal</a></li>
                    <li><a href="#interface">Contre-Enquêtes</a></li>
                    <li><a href="#interface">Archives Vivantes</a></li>
                    <li><a href="#interface">Conseiller Syndical</a></li>
                    <li><a href="#interface">Vigilance Citoyenne</a></li>
                    <li><a href="#interface">Labo Hip-Hop</a></li>
                </ul>
            </div>
            <div>
                <p class="footer-col-title">UCAA</p>
                <ul class="footer-links">
                    <li><a href="#">Mission & Valeurs</a></li>
                    <li><a href="#">Rejoindre le Syndicat</a></li>
                    <li><a href="#">Token Réel</a></li>
                    <li><a href="#">Dojo Virtuel</a></li>
                    <li><a href="#">Contact</a></li>
                </ul>
            </div>
            <div>
                <p class="footer-col-title">Langues</p>
                <ul class="footer-links">
                    <li><a href="#">Français</a></li>
                    <li><a href="#">English</a></li>
                    <li><a href="#">Wolof</a></li>
                    <li><a href="#">Swahili</a></li>
                    <li><a href="#">Lingala</a></li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom">
            <p class="footer-copy">© 2024 UCAA · Griot de Cristal · Tous droits réservés</p>
            <p class="footer-tech">MISTRAL AI · SQLITE · PHP 8.3 · HOSTINGER</p>
        </div>
    </div>
</footer>

<!-- ═══ JAVASCRIPT ════════════════════════════════════ -->
<script>
// ── STATE ───────────────────────────────────────────
const STATE = {
    sessionId: '<?= htmlspecialchars($sessionId) ?>',
    currentModule: 'griot',
    selectedFigure: null,
    selectedVcat: 'discrimination_raciale',
    isLoading: false
};

// ── INIT ────────────────────────────────────────────
window.addEventListener('load', () => {
    setTimeout(() => {
        document.getElementById('loadingOverlay').classList.add('hidden');
    }, 1200);
    autoResize(document.getElementById('chatInput'));
});

// ── TOAST ────────────────────────────────────────────
function showToast(msg, duration = 3500) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('visible');
    setTimeout(() => t.classList.remove('visible'), duration);
}

// ── MODULE SELECTOR (cartes) ─────────────────────────
const MODULE_MAP = {
    'griot': 'griot', 'contre_enquete': 'contre_enquete',
    'archive_vivante': 'archive_vivante', 'conseiller_syndical': 'conseiller_syndical',
    'vigilance': 'vigilance', 'hiphop': 'hiphop'
};
const MODULE_LABELS = {
    'griot': 'GRIOT', 'contre_enquete': 'CONTRE-ENQUÊTE',
    'archive_vivante': 'ARCHIVES VIVANTES', 'conseiller_syndical': 'CONSEILLER',
    'vigilance': 'VIGILANCE', 'hiphop': 'LABO HIP-HOP'
};
const MODULE_MODELS = {
    'griot': 'mistral-large-2512', 'contre_enquete': 'mistral-large-2512',
    'archive_vivante': 'mistral-large-2512', 'conseiller_syndical': 'mistral-large-2512',
    'vigilance': 'magistral-medium-2509', 'hiphop': 'labs-mistral-small-creative'
};

function selectModule(el) {
    document.querySelectorAll('.module-card').forEach(c => c.classList.remove('active'));
    el.classList.add('active');
    const mod = el.dataset.module;
    STATE.currentModule = mod;
    document.getElementById('currentMode').textContent = MODULE_LABELS[mod] || mod.toUpperCase();
    document.getElementById('activeModel').textContent = MODULE_MODELS[mod] || 'mistral-large-2512';

    // Scroll vers interface
    document.getElementById('interface').scrollIntoView({ behavior: 'smooth' });

    // Activer le bon tab
    const tabMap = {
        'griot': 'chat-panel', 'contre_enquete': 'chat-panel',
        'archive_vivante': 'archives-panel', 'conseiller_syndical': 'chat-panel',
        'vigilance': 'vigilance-panel', 'hiphop': 'hiphop-panel'
    };
    const targetTab = tabMap[mod] || 'chat-panel';
    const tabBtn = document.querySelector(`[data-tab="${targetTab}"]`);
    if (tabBtn) switchTab(tabBtn, targetTab);

    // Vider chat
    if (targetTab === 'chat-panel') {
        const msgs = document.getElementById('chatMessages');
        msgs.innerHTML = `<div class="msg-welcome">
            <span class="welcome-sigil">${{'griot':'🔮','contre_enquete':'🔍','conseiller_syndical':'⚖️'}[mod]||'🔮'}</span>
            <p class="welcome-title">${MODULE_LABELS[mod]} activé</p>
            <p class="welcome-subtitle">Mode changé. Posez votre question.</p>
        </div>`;
    }
}

// ── TABS ─────────────────────────────────────────────
function switchTab(el, targetId) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    el.classList.add('active');

    const panels = ['chat-panel', 'archives-panel', 'vigilance-panel', 'hiphop-panel', 'traduction-panel'];
    panels.forEach(pid => {
        const el2 = document.getElementById(pid);
        if (!el2) return;
        if (pid === 'chat-panel') {
            // chat-panel est dans l'interface-body, toujours visible si tab actif
            const chatPanelEl = document.getElementById('chat-panel');
            if (chatPanelEl) chatPanelEl.style.display = pid === targetId ? 'flex' : 'none';
        } else {
            el2.style.display = pid === targetId ? 'block' : 'none';
        }
    });

    // Corriger : afficher chat-panel correctement
    const cp = document.getElementById('chat-panel');
    if (cp) cp.style.display = targetId === 'chat-panel' ? 'flex' : 'none';
}

// ── CHAT ─────────────────────────────────────────────
function handleChatKey(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
    autoResize(e.target);
}

function autoResize(el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 120) + 'px';
}

function appendMsg(role, content) {
    const msgs = document.getElementById('chatMessages');
    // Enlever welcome
    const welcome = msgs.querySelector('.msg-welcome');
    if (welcome) welcome.remove();

    const div = document.createElement('div');
    div.className = 'msg' + (role === 'user' ? ' msg-user' : '');
    div.innerHTML = `
        <div class="msg-avatar">${role === 'user' ? '👤' : '🔮'}</div>
        <div class="msg-bubble">${escapeHtml(content).replace(/\n/g,'<br>')}</div>
    `;
    msgs.appendChild(div);
    msgs.scrollTop = msgs.scrollHeight;
}

function escapeHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

async function sendMessage() {
    if (STATE.isLoading) return;
    const input = document.getElementById('chatInput');
    const msg = input.value.trim();
    if (!msg) return;

    STATE.isLoading = true;
    input.value = '';
    input.style.height = 'auto';
    document.getElementById('sendBtn').disabled = true;

    appendMsg('user', msg);

    const typing = document.getElementById('typingIndicator');
    typing.classList.add('visible');
    document.getElementById('chatMessages').scrollTop = document.getElementById('chatMessages').scrollHeight;

    try {
        const fd = new FormData();
        fd.append('action', 'griot_chat');
        fd.append('session_id', STATE.sessionId);
        fd.append('message', msg);
        fd.append('module', STATE.currentModule);

        const res = await fetch(window.location.href, { method: 'POST', body: fd });

        // Vérifier content-type
        const ct = res.headers.get('content-type') || '';
        if (!ct.includes('application/json')) {
            throw new Error('Réponse non-JSON du serveur');
        }

        const data = await res.json();
        typing.classList.remove('visible');

        if (data.success) {
            appendMsg('assistant', data.response);
        } else {
            appendMsg('assistant', '⚠️ Erreur : ' + (data.error || 'Réponse invalide'));
        }
    } catch(err) {
        typing.classList.remove('visible');
        appendMsg('assistant', '⚠️ Connexion impossible. Vérifiez votre accès et réessayez.');
        console.error('Chat error:', err);
    }

    STATE.isLoading = false;
    document.getElementById('sendBtn').disabled = false;
    input.focus();
}

function quickPrompt(text) {
    document.getElementById('chatInput').value = text;
    sendMessage();
}

// ── ARCHIVES VIVANTES ─────────────────────────────────
function selectFigure(el) {
    document.querySelectorAll('.archive-figure').forEach(f => f.classList.remove('selected'));
    el.classList.add('selected');
    STATE.selectedFigure = el.dataset.figure;
    showToast('🏛️ ' + STATE.selectedFigure + ' sélectionné·e');
}

async function callArchive() {
    const question = document.getElementById('archivesQuestion').value.trim();
    if (!question) { showToast('Posez une question d\'abord'); return; }
    if (!STATE.selectedFigure) { showToast('Choisissez une figure historique'); return; }

    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Invocation en cours...';

    const result = document.getElementById('archivesResult');
    result.classList.remove('visible');

    try {
        const fd = new FormData();
        fd.append('action', 'griot_chat');
        fd.append('session_id', STATE.sessionId + '_archive');
        fd.append('message', `[INCARNATION DE ${STATE.selectedFigure}] ${question}`);
        fd.append('module', 'archive_vivante');

        const res = await fetch(window.location.href, { method: 'POST', body: fd });
        const ct = res.headers.get('content-type') || '';
        if (!ct.includes('application/json')) throw new Error('Réponse non-JSON');
        const data = await res.json();

        if (data.success) {
            result.textContent = data.response;
            result.classList.add('visible');
        } else {
            showToast('Erreur : ' + data.error);
        }
    } catch(err) {
        showToast('Erreur de connexion');
        console.error(err);
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-ghost"></i> Invoquer la mémoire';
}

// ── VIGILANCE ─────────────────────────────────────────
function selectVcat(el) {
    document.querySelectorAll('.vcat-btn').forEach(b => b.classList.remove('selected'));
    el.classList.add('selected');
    STATE.selectedVcat = el.dataset.cat;
}

async function submitVigilance() {
    const desc = document.getElementById('vigilanceDesc').value.trim();
    const name = document.getElementById('vigilanceName').value.trim() || 'Anonyme';

    if (desc.length < 20) { showToast('Description trop courte (min. 20 caractères)'); return; }

    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Analyse IA en cours...';

    const result = document.getElementById('vigilanceResult');
    result.classList.remove('visible');

    try {
        const fd = new FormData();
        fd.append('action', 'vigilance_report');
        fd.append('category', STATE.selectedVcat);
        fd.append('description', desc);
        fd.append('reporter', name);

        const res = await fetch(window.location.href, { method: 'POST', body: fd });
        const ct = res.headers.get('content-type') || '';
        if (!ct.includes('application/json')) throw new Error('Réponse non-JSON');
        const data = await res.json();

        if (data.success) {
            result.textContent = '📋 ANALYSE IA DU SIGNALEMENT #' + data.id + '\n\n' + data.analysis;
            result.classList.add('visible');
            showToast('✅ Signalement #' + data.id + ' enregistré');
            document.getElementById('vigilanceDesc').value = '';
        } else {
            showToast('Erreur : ' + data.error);
        }
    } catch(err) {
        showToast('Erreur de connexion');
        console.error(err);
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-shield-alt"></i> Analyser et signaler';
}

// ── HIP-HOP ───────────────────────────────────────────
async function generateHiphop() {
    const theme = document.getElementById('hiphopTheme').value.trim();
    if (!theme) { showToast('Entrez un thème'); return; }

    const style  = document.getElementById('hiphopStyle').value;
    const langue = document.getElementById('hiphopLangue').value;

    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Composition en cours...';

    const result = document.getElementById('hiphopResult');
    result.classList.remove('visible');

    try {
        const fd = new FormData();
        fd.append('action', 'hiphop_generate');
        fd.append('theme', theme);
        fd.append('style', style);
        fd.append('langue', langue);

        const res = await fetch(window.location.href, { method: 'POST', body: fd });
        const ct = res.headers.get('content-type') || '';
        if (!ct.includes('application/json')) throw new Error('Réponse non-JSON');
        const data = await res.json();

        if (data.success) {
            result.textContent = data.lyrics;
            result.classList.add('visible');
        } else {
            showToast('Erreur : ' + data.error);
        }
    } catch(err) {
        showToast('Erreur de connexion');
        console.error(err);
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-microphone-alt"></i> Composer';
}

// ── TRADUCTION ────────────────────────────────────────
async function translateText() {
    const text   = document.getElementById('translationText').value.trim();
    const target = document.getElementById('translationTarget').value;

    if (!text) { showToast('Entrez un texte'); return; }

    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Traduction...';

    const result = document.getElementById('translationResult');
    result.classList.remove('visible');

    try {
        const fd = new FormData();
        fd.append('action', 'translate');
        fd.append('text', text);
        fd.append('target_lang', target);

        const res = await fetch(window.location.href, { method: 'POST', body: fd });
        const ct = res.headers.get('content-type') || '';
        if (!ct.includes('application/json')) throw new Error('Réponse non-JSON');
        const data = await res.json();

        if (data.success) {
            result.textContent = data.translation;
            result.classList.add('visible');
        } else {
            showToast('Erreur : ' + data.error);
        }
    } catch(err) {
        showToast('Erreur de connexion');
        console.error(err);
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-language"></i> Traduire';
}

// ── SMOOTH SCROLL (nav links) ─────────────────────────
document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', e => {
        const target = document.querySelector(a.getAttribute('href'));
        if (target) {
            e.preventDefault();
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
});

// ── INIT TABS (afficher seulement chat-panel au départ) ──
document.querySelectorAll('#archives-panel,#vigilance-panel,#hiphop-panel,#traduction-panel').forEach(p => {
    p.style.display = 'none';
});
</script>
</body>
</html>
