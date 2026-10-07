<?php
// =============================================================================
//  BẢN TIN BÃO + BẢN ĐỒ ĐỒNG BỘ (DỰNG VIDEO)
//  -----------------------------------------------------------------------------
//  Nguồn dữ liệu: bảng jma_typhoons (giống ty.php) - spec_json + track_json của JMA.
//    ?storm=<tc_id>             : xem bản tin + trình phát bản đồ
//    ?storm=<tc_id>&api=json    : trả về JSON (đoạn văn + kịch bản cue)
//  config.php nằm ở thư mục cha (../config.php).
//
//  Mỗi câu của bản tin là 1 "cue" gắn với 1 hành động trên bản đồ:
//    intro     -> vẽ đường đi quá khứ, hiện vị trí hiện tại
//    position  -> vị trí tâm bão nhấp nháy (có 'from' thì tịnh tiến tới trước)
//    wind      -> nhãn sức gió phóng to rồi thu nhỏ
//    radius7   -> vẽ vùng gió mạnh cấp 7 trở lên
//    radius10  -> vẽ vùng gió mạnh cấp 10 trở lên
//    move      -> bão tịnh tiến từ điểm 'from' tới điểm dự báo 'pt'
//    dissipate -> bão tan dần (mờ dần)
//    history   -> màn hình các cơn bão cùng tên trong lịch sử (bản tin đầu tiên mạnh lên thành bão)
//    rank      -> màn hình xếp hạng cường độ trong năm (bản tin đầu tiên đạt cấp gió mạnh nhất vòng đời)
//    info      -> chỉ hiển thị chữ
// =============================================================================

require_once __DIR__ . '/../config.php';
date_default_timezone_set('Asia/Ho_Chi_Minh');

// =============================================================================
//  GIỌNG ĐỌC VBEE (tham khảo news.php)
//  - Mỗi câu (cue) có 1 file mp3 riêng -> chữ, giọng và hoạt ảnh bản đồ khớp theo từng câu.
//  - "Văn bản đọc" tách khỏi "văn bản hiển thị": phiên âm (từ điển + sửa tay từng câu) chỉ gửi cho Vbee,
//    phụ đề / kịch bản vẫn giữ nguyên chữ gốc.
//  - Cache theo sha1(giọng|tốc độ|văn bản đọc): câu không đổi thì không gọi lại Vbee.
//  Khai báo trong ../config.php:
//    define('VBEE_APP_ID', '...');  define('VBEE_TOKEN', '...');  // (tuỳ chọn) define('VBEE_CALLBACK_URL', 'https://.../video.php?vbee_callback=1');
// =============================================================================
const VB_TTS_DIR  = __DIR__ . '/tts_cache';
const VB_TTS_DATA = __DIR__ . '/tts_cache/_settings.json';
const VB_TTS_VOICES = [
    'hn_female_ngochuyen_full_48k-fhg'  => 'Ngọc Huyền - Nữ miền Bắc',
    'hn_female_hachi_book_22k-vc'       => 'Hà Chi - Nữ miền Bắc (đọc sách)',
    'hn_male_manhdung_news_48k-fhg'     => 'Mạnh Dũng - Nam miền Bắc (tin tức)',
    'hn_male_phuthang_news65dt_44k-fhg' => 'Phú Thắng - Nam miền Bắc (tin tức)',
    'sg_female_thaotrinh_full_48k-fhg'  => 'Thảo Trinh - Nữ miền Nam',
    'sg_male_minhhoang_full_48k-fhg'    => 'Minh Hoàng - Nam miền Nam',
    'hue_female_huonggiang_full_48k-fhg'=> 'Hương Giang - Nữ miền Trung',
];

function vb_tts_conf($key) {
    if (defined($key)) return (string)constant($key);
    $v = getenv($key);
    return $v === false ? '' : (string)$v;
}

function vb_tts_configured() {
    return vb_tts_conf('VBEE_APP_ID') !== '' && vb_tts_conf('VBEE_TOKEN') !== '';
}

function vb_tts_voice($v) {
    $v = trim((string)$v);
    return preg_match('/^[A-Za-z0-9_\-.]{3,80}$/', $v) ? $v : array_key_first(VB_TTS_VOICES);
}

function vb_tts_speed($s) {
    $s = is_numeric($s) ? (float)$s : 1.0;
    return sprintf('%.2f', max(0.5, min(2.0, $s)));
}

// Văn bản đọc: gộp khoảng trắng để "sửa thừa dấu cách" không làm mất cache.
function vb_tts_text($t) {
    return trim(preg_replace('/\s+/u', ' ', (string)$t));
}

function vb_tts_key($voice, $speed, $text) {
    return sha1($voice . '|' . $speed . '|' . $text);
}

function vb_tts_file($key) {
    return VB_TTS_DIR . '/' . $key . '.mp3';
}

function vb_tts_url($key) {
    return 'tts_cache/' . $key . '.mp3?v=' . filemtime(vb_tts_file($key));
}

function vb_tts_valid_key($k) {
    return is_string($k) && preg_match('/^[a-f0-9]{40}$/', $k);
}

function vb_tts_ensure_dir() {
    if (!is_dir(VB_TTS_DIR) && !@mkdir(VB_TTS_DIR, 0755, true)) return false;
    $ht = VB_TTS_DIR . '/.htaccess';
    if (!file_exists($ht)) @file_put_contents($ht, "<Files \"_settings.json\">\n  Require all denied\n</Files>\n");
    return is_writable(VB_TTS_DIR);
}

function vb_tts_load() {
    $d = is_file(VB_TTS_DATA) ? json_decode((string)file_get_contents(VB_TTS_DATA), true) : null;
    $d = is_array($d) ? $d : [];
    return [
        'voice'     => vb_tts_voice($d['voice'] ?? ''),
        'speed'     => vb_tts_speed($d['speed'] ?? 1),
        'dict'      => is_array($d['dict'] ?? null) ? array_values($d['dict']) : [],
        'overrides' => is_array($d['overrides'] ?? null) ? $d['overrides'] : new stdClass(),
    ];
}

function vb_tts_http($method, $url, $body = null) {
    $token = vb_tts_conf('VBEE_TOKEN');
    if (stripos($token, 'Bearer ') !== 0) $token = 'Bearer ' . $token;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: ' . $token],
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    return ['code' => $code, 'json' => is_array($json) ? $json : null, 'error' => $err, 'raw' => is_string($raw) ? substr($raw, 0, 300) : ''];
}

// Vbee trả {request_id, audio_link, status} ở gốc hoặc lồng trong "result".
function vb_tts_pick(array $j, $k) {
    return $j[$k] ?? ($j['result'][$k] ?? null);
}

function vb_tts_download($audioUrl, $key) {
    if (!preg_match('#^https://#i', (string)$audioUrl)) return false;
    $ch = curl_init($audioUrl);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 120]);
    $bin = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !is_string($bin) || strlen($bin) < 512) return false;
    $tmp = vb_tts_file($key) . '.part';
    if (file_put_contents($tmp, $bin, LOCK_EX) === false) return false;
    return rename($tmp, vb_tts_file($key));
}

function vb_tts_self_url() {
    $cb = vb_tts_conf('VBEE_CALLBACK_URL');
    if ($cb !== '') return $cb;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . strtok($_SERVER['REQUEST_URI'] ?? '/video.php', '?') . '?vbee_callback=1';
}

function vb_tts_out(array $data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Vbee gọi lại khi xong (response_type = indirect). Trình duyệt đã tự hỏi trạng thái nên chỉ cần trả 200.
if (isset($_GET['vbee_callback'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo '{"ok":true}';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && strpos((string)($_POST['action'] ?? ''), 'tts_') === 0) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $action = $_POST['action'];

    if ($action === 'tts_save') {
        if (!vb_tts_ensure_dir()) vb_tts_out(['ok' => false, 'error' => 'Không ghi được thư mục tts_cache'], 500);
        $dict = json_decode((string)($_POST['dict'] ?? '[]'), true);
        $ovr  = json_decode((string)($_POST['overrides'] ?? '{}'), true);
        $cleanDict = [];
        foreach (is_array($dict) ? $dict : [] as $p) {
            if (!is_array($p) || count($p) < 2) continue;
            $from = vb_tts_text($p[0]); $to = vb_tts_text($p[1]);
            if ($from !== '' && mb_strlen($from) <= 80 && mb_strlen($to) <= 160) $cleanDict[] = [$from, $to];
            if (count($cleanDict) >= 500) break;
        }
        $cleanOvr = [];
        foreach (is_array($ovr) ? $ovr : [] as $orig => $read) {
            $orig = vb_tts_text($orig); $read = vb_tts_text($read);
            if ($orig !== '' && $read !== '' && mb_strlen($read) <= 2000) $cleanOvr[$orig] = $read;
        }
        $cleanOvr = array_slice($cleanOvr, -3000, null, true);
        $data = ['voice' => vb_tts_voice($_POST['voice'] ?? ''), 'speed' => vb_tts_speed($_POST['speed'] ?? 1), 'dict' => $cleanDict, 'overrides' => (object)$cleanOvr];
        $ok = file_put_contents(VB_TTS_DATA, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) !== false;
        vb_tts_out(['ok' => $ok]);
    }

    $voice = vb_tts_voice($_POST['voice'] ?? '');
    $speed = vb_tts_speed($_POST['speed'] ?? 1);

    // Câu n��o đã có sẵn mp3 cho đúng giọng + tốc độ + văn bản đọc.
    if ($action === 'tts_lookup') {
        $texts = json_decode((string)($_POST['texts'] ?? '[]'), true);
        $urls = [];
        foreach (is_array($texts) ? $texts : [] as $t) {
            $key = vb_tts_key($voice, $speed, vb_tts_text($t));
            $urls[] = is_file(vb_tts_file($key)) ? vb_tts_url($key) : null;
        }
        vb_tts_out(['ok' => true, 'urls' => $urls]);
    }

    if ($action === 'tts_start') {
        $text = vb_tts_text($_POST['text'] ?? '');
        if ($text === '') vb_tts_out(['ok' => false, 'error' => 'Văn bản đọc trống'], 400);
        if (mb_strlen($text) > 2000) vb_tts_out(['ok' => false, 'error' => 'Câu quá dài (tối đa 2000 ký tự)'], 400);
        if (!vb_tts_ensure_dir()) vb_tts_out(['ok' => false, 'error' => 'Không ghi được thư mục tts_cache'], 500);
        $key = vb_tts_key($voice, $speed, $text);
        if (!empty($_POST['force']) && is_file(vb_tts_file($key))) @unlink(vb_tts_file($key));
        if (is_file(vb_tts_file($key))) vb_tts_out(['ok' => true, 'status' => 'ready', 'key' => $key, 'url' => vb_tts_url($key)]);
        if (!vb_tts_configured()) vb_tts_out(['ok' => false, 'error' => 'Chưa cấu hình VBEE_APP_ID / VBEE_TOKEN trong config.php'], 500);

        $r = vb_tts_http('POST', 'https://vbee.vn/api/v1/tts', [
            'app_id'        => vb_tts_conf('VBEE_APP_ID'),
            'response_type' => 'indirect',
            'callback_url'  => vb_tts_self_url(),
            'input_text'    => $text,
            'voice_code'    => $voice,
            'audio_type'    => 'mp3',
            'speed_rate'    => (float)$speed,
        ]);
        $rid = $r['json'] ? vb_tts_pick($r['json'], 'request_id') : null;
        if (!$rid) {
            $msg = $r['json'] ? (vb_tts_pick($r['json'], 'error_message') ?? vb_tts_pick($r['json'], 'message') ?? $r['raw']) : ($r['error'] ?: 'HTTP ' . $r['code']);
            vb_tts_out(['ok' => false, 'error' => 'Vbee từ chối yêu cầu: ' . (is_string($msg) ? $msg : json_encode($msg, JSON_UNESCAPED_UNICODE))], 502);
        }
        vb_tts_out(['ok' => true, 'status' => 'pending', 'key' => $key, 'request_id' => (string)$rid]);
    }

    if ($action === 'tts_poll') {
        $key = (string)($_POST['key'] ?? '');
        $rid = (string)($_POST['request_id'] ?? '');
        if (!vb_tts_valid_key($key) || !preg_match('/^[A-Za-z0-9\-_]{6,80}$/', $rid)) vb_tts_out(['ok' => false, 'error' => 'Tham số không hợp lệ'], 400);
        if (is_file(vb_tts_file($key))) vb_tts_out(['ok' => true, 'status' => 'ready', 'url' => vb_tts_url($key)]);
        $r = vb_tts_http('GET', 'https://vbee.vn/api/v1/tts/' . rawurlencode($rid));
        if (!$r['json']) vb_tts_out(['ok' => true, 'status' => 'pending']);
        $link = vb_tts_pick($r['json'], 'audio_link');
        $st = strtoupper((string)(vb_tts_pick($r['json'], 'status') ?? ''));
        if ($link) {
            if (!vb_tts_download($link, $key)) vb_tts_out(['ok' => false, 'error' => 'Không tải được file mp3 từ Vbee'], 502);
            vb_tts_out(['ok' => true, 'status' => 'ready', 'url' => vb_tts_url($key)]);
        }
        if (in_array($st, ['FAILURE', 'FAILED', 'ERROR'], true)) vb_tts_out(['ok' => false, 'error' => 'Vbee báo lỗi khi tổng hợp giọng'], 502);
        vb_tts_out(['ok' => true, 'status' => 'pending']);
    }

    vb_tts_out(['ok' => false, 'error' => 'Hành động không hợp lệ'], 400);
}

// =============================================================================
//  YOUTUBE (tham khảo news.php)
//  - OAuth Authorization Code Flow -> lưu access/refresh token vào file (dùng chung với news.php ở thư mục cha).
//  - Trình duyệt nhận access token hợp lệ từ máy chủ rồi upload thẳng lên YouTube (resumable upload).
//  - Có thể khai báo trong ../config.php: define('YOUTUBE_CLIENT_ID', '...'); define('YOUTUBE_CLIENT_SECRET', '...');
//  - Nhớ thêm Redirect URI của video.php (vd: https://domain/thu-muc/video.php) vào Google Cloud Console.
// =============================================================================
const VB_YT_SCOPES = 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly';

function vb_yt_client_id() {
    $v = vb_tts_conf('YOUTUBE_CLIENT_ID');
    return $v;
}

function vb_yt_client_secret() {
    $v = vb_tts_conf('YOUTUBE_CLIENT_SECRET');
    return $v;
}

function vb_yt_token_file() {
    $f = vb_tts_conf('YOUTUBE_TOKEN_FILE');
    return $f !== '' ? $f : __DIR__ . '/youtube_tokens.json';
}

function vb_yt_redirect_uri() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['PHP_SELF'] ?? '/video.php');
}

function vb_yt_save_tokens(array $tokens) {
    $tokens['saved_at'] = time();
    return file_put_contents(vb_yt_token_file(), json_encode($tokens, JSON_PRETTY_PRINT), LOCK_EX) !== false;
}

function vb_yt_get_tokens() {
    $f = vb_yt_token_file();
    if (!is_file($f)) return null;
    $t = json_decode((string)file_get_contents($f), true);
    return is_array($t) ? $t : null;
}

function vb_yt_post_form($url, array $fields) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    return [$code, is_array($json) ? $json : null];
}

function vb_yt_refresh() {
    $tokens = vb_yt_get_tokens();
    if (!$tokens || empty($tokens['refresh_token'])) return null;
    [$code, $new] = vb_yt_post_form('https://oauth2.googleapis.com/token', [
        'client_id'     => vb_yt_client_id(),
        'client_secret' => vb_yt_client_secret(),
        'refresh_token' => $tokens['refresh_token'],
        'grant_type'    => 'refresh_token',
    ]);
    if ($code !== 200 || empty($new['access_token'])) return null;
    if (empty($new['refresh_token'])) $new['refresh_token'] = $tokens['refresh_token'];
    vb_yt_save_tokens($new);
    return $new['access_token'];
}

function vb_yt_valid_token($forceRefresh = false) {
    $tokens = vb_yt_get_tokens();
    if (!$tokens) return null;
    $expiresAt = (int)($tokens['saved_at'] ?? 0) + (int)($tokens['expires_in'] ?? 3600) - 300;
    if (!$forceRefresh && time() < $expiresAt && !empty($tokens['access_token'])) return $tokens['access_token'];
    return vb_yt_refresh();
}

// Google OAuth gọi lại: state = "youtube_auth:<mã bão>" để quay về đúng cơn bão đang xem.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['code'], $_GET['state']) && strpos((string)$_GET['state'], 'youtube_auth') === 0) {
    $back = strtok(vb_yt_redirect_uri(), '?');
    $stormBack = substr((string)$_GET['state'], strlen('youtube_auth:'));
    $qs = preg_match('/^[A-Za-z0-9_\-]{1,40}$/', $stormBack) ? 'storm=' . rawurlencode($stormBack) . '&' : '';
    [$code, $tokens] = vb_yt_post_form('https://oauth2.googleapis.com/token', [
        'code'          => (string)$_GET['code'],
        'client_id'     => vb_yt_client_id(),
        'client_secret' => vb_yt_client_secret(),
        'redirect_uri'  => vb_yt_redirect_uri(),
        'grant_type'    => 'authorization_code',
    ]);
    $ok = $code === 200 && !empty($tokens['access_token']) && vb_yt_save_tokens($tokens);
    header('Location: ' . $back . '?' . $qs . 'youtube_auth=' . ($ok ? 'success' : 'error'));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && strpos((string)($_POST['action'] ?? ''), 'yt_') === 0) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $action = $_POST['action'];

    if ($action === 'yt_check') {
        $token = vb_yt_valid_token(!empty($_POST['force']));
        vb_tts_out($token ? ['ok' => true, 'has_token' => true, 'access_token' => $token] : ['ok' => true, 'has_token' => false]);
    }

    if ($action === 'yt_auth_url') {
        $storm = (string)($_POST['storm'] ?? '');
        $state = 'youtube_auth' . (preg_match('/^[A-Za-z0-9_\-]{1,40}$/', $storm) ? ':' . $storm : '');
        vb_tts_out(['ok' => true, 'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id'     => vb_yt_client_id(),
            'redirect_uri'  => vb_yt_redirect_uri(),
            'response_type' => 'code',
            'scope'         => VB_YT_SCOPES,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ])]);
    }

    if ($action === 'yt_save_token') {
        $access = trim((string)($_POST['access_token'] ?? ''));
        if ($access === '' || strlen($access) > 4096) vb_tts_out(['ok' => false, 'error' => 'Access token không hợp lệ'], 400);
        $old = vb_yt_get_tokens() ?: [];
        $ok = vb_yt_save_tokens([
            'access_token'  => $access,
            'expires_in'    => max(60, min(7200, (int)($_POST['expires_in'] ?? 3600))),
            'refresh_token' => $old['refresh_token'] ?? '',
        ]);
        vb_tts_out(['ok' => $ok]);
    }

    if ($action === 'yt_revoke') {
        $tokens = vb_yt_get_tokens();
        if ($tokens && !empty($tokens['access_token'])) vb_yt_post_form('https://oauth2.googleapis.com/revoke', ['token' => $tokens['access_token']]);
        if (is_file(vb_yt_token_file())) @unlink(vb_yt_token_file());
        vb_tts_out(['ok' => true]);
    }

    // Lưu lại video đã upload vào bảng tv_ytb (giống news.php).
    if ($action === 'yt_log') {
        $link = trim((string)($_POST['link_ytb'] ?? ''));
        $title = trim((string)($_POST['tieu_de'] ?? ''));
        $desc = (string)($_POST['mo_ta'] ?? '');
        if (!preg_match('#^https://www\.youtube\.com/watch\?v=[A-Za-z0-9_\-]{6,20}$#', $link) || $title === '') vb_tts_out(['ok' => false, 'error' => 'Dữ liệu không hợp lệ'], 400);
        if (!isset($conn) || $conn->connect_error) vb_tts_out(['ok' => false, 'error' => 'Không kết nối được cơ sở dữ liệu'], 500);
        $conn->query("CREATE TABLE IF NOT EXISTS tv_ytb (
            stt INT AUTO_INCREMENT PRIMARY KEY,
            link_ytb VARCHAR(255) NOT NULL,
            tieu_de VARCHAR(500) NOT NULL,
            mo_ta TEXT,
            ngay_dang DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $now = (new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Y-m-d H:i:s');
        $stmt = $conn->prepare('INSERT INTO tv_ytb (link_ytb, tieu_de, mo_ta, ngay_dang) VALUES (?, ?, ?, ?)');
        if (!$stmt) vb_tts_out(['ok' => false, 'error' => 'Không chuẩn bị được câu lệnh'], 500);
        $stmt->bind_param('ssss', $link, $title, $desc, $now);
        $ok = $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();
        vb_tts_out($ok ? ['ok' => true, 'insert_id' => $id] : ['ok' => false, 'error' => 'Không lưu được vào tv_ytb'], $ok ? 200 : 500);
    }

    vb_tts_out(['ok' => false, 'error' => 'Hành động không hợp lệ'], 400);
}



const VB_COMPASS_VI = [
    'N' => 'Bắc', 'NNE' => 'Bắc Đông Bắc', 'NE' => 'Đông Bắc', 'ENE' => 'Đông Đông Bắc',
    'E' => 'Đông', 'ESE' => 'Đông Đông Nam', 'SE' => 'Đông Nam', 'SSE' => 'Nam Đông Nam',
    'S' => 'Nam', 'SSW' => 'Nam Tây Nam', 'SW' => 'Tây Nam', 'WSW' => 'Tây Tây Nam',
    'W' => 'Tây', 'WNW' => 'Tây Tây Bắc', 'NW' => 'Tây Bắc', 'NNW' => 'Bắc Tây Bắc',
];
const VB_COMPASS_JP = [
    '北' => 'N', '北北東' => 'NNE', '北東' => 'NE', '東北東' => 'ENE',
    '東' => 'E', '東南東' => 'ESE', '南東' => 'SE', '南南東' => 'SSE',
    '南' => 'S', '南南西' => 'SSW', '南西' => 'SW', '西南西' => 'WSW',
    '西' => 'W', '西北西' => 'WNW', '北西' => 'NW', '北北西' => 'NNW',
];
// Hướng của vùng gió (galeWarning/stormWarning) -> phương vị (giống NM_AREA_BEARING trong ty.php).
const VB_AREA_BEARING = [
    '北' => 0, 'N' => 0, '北側' => 0,       '北東' => 45, 'NE' => 45, '北東側' => 45,
    '東' => 90, 'E' => 90, '東側' => 90,    '南東' => 135, 'SE' => 135, '南東側' => 135,
    '南' => 180, 'S' => 180, '南側' => 180, '南西' => 225, 'SW' => 225, '南西側' => 225,
    '西' => 270, 'W' => 270, '西側' => 270, '北西' => 315, 'NW' => 315, '北西側' => 315,
];
const VB_BEARING_VI = [0 => 'Bắc', 45 => 'Đông Bắc', 90 => 'Đông', 135 => 'Đông Nam', 180 => 'Nam', 225 => 'Tây Nam', 270 => 'Tây', 315 => 'Tây Bắc'];
const VB_SOURCE_LINE = 'Bản tin sử dụng dữ liệu được cập nhật mới nhất từ Cơ quan Khí tượng Nhật Bản.';

function vb_e($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// Chuỗi nhãn của JMA có thể là string hoặc {en, jp}.
function vb_str($v) {
    if (is_array($v)) $v = $v['jp'] ?? $v['ja'] ?? $v['en'] ?? '';
    return is_string($v) || is_numeric($v) ? trim((string)$v) : '';
}

function vb_part_text($item) {
    $p = $item['part'] ?? '';
    if (is_array($p)) return trim(($p['en'] ?? '') . ' ' . ($p['jp'] ?? $p['ja'] ?? ''));
    return is_string($p) ? $p : '';
}

function vb_is_analysis($item) {
    $p = vb_part_text($item);
    return stripos($p, 'Analysis') !== false || strpos($p, '実況') !== false;
}

function vb_is_estimate($item) {
    $p = vb_part_text($item);
    return stripos($p, 'Estimate') !== false || strpos($p, '推定') !== false;
}

// Luôn quy đổi từ kt gốc (JMA làm tròn m/s theo quy ước riêng, dễ tụt 1 cấp - giống ty.php).
function vb_wind_ms($w) {
    if (!is_array($w)) return null;
    if (isset($w['kt']) && is_numeric($w['kt']) && (float)$w['kt'] > 0) return (float)$w['kt'] * 0.514444;
    if (isset($w['m/s']) && is_numeric($w['m/s']) && (float)$w['m/s'] > 0) return (float)$w['m/s'];
    return null;
}

// Thang Beaufort mở rộng tới cấp 17 (cùng ngưỡng với getBeaufort() trong ty.php).
function vb_level($ms) {
    if ($ms === null || $ms <= 0) return null;
    $limits = [0.3, 1.6, 3.4, 5.5, 8.0, 10.8, 13.9, 17.2, 20.8, 24.5, 28.5, 32.7, 37.0, 41.5, 46.2, 51.0, 56.1];
    foreach ($limits as $lv => $max) if ($ms < $max) return $lv;
    return 17;
}

// Gió giật có thể vượt cấp 17 (> 61,2 m/s ~ 220 km/h).
function vb_gust_text($ms) {
    if ($ms === null || $ms <= 0) return null;
    return $ms >= 61.3 ? 'trên cấp 17' : 'cấp ' . vb_level($ms);
}

// Phân loại theo cấp gió mạnh nhất. null = không còn số liệu gió -> tan dần.
function vb_class($lv) {
    if ($lv === null) return 'tan';
    if ($lv >= 16) return 'siêu bão';
    if ($lv >= 8) return 'bão';
    if ($lv >= 6) return 'áp thấp nhiệt đới';
    return 'vùng áp thấp';
}

function vb_num($v) {
    $s = number_format(abs((float)$v), 1, ',', '');
    return preg_replace('/,0$/', '', $s);
}

function vb_latlon($item) {
    $deg = $item['position']['deg'] ?? null;
    if (!is_array($deg) || count($deg) < 2 || !is_numeric($deg[0]) || !is_numeric($deg[1])) return [null, null];
    return [(float)$deg[0], (float)$deg[1]];
}

function vb_coord_text($lat, $lon) {
    if ($lat === null || $lon === null) return null;
    return vb_num($lat) . ' độ vĩ ' . ($lat >= 0 ? 'Bắc' : 'Nam') . ', '
         . vb_num($lon) . ' độ kinh ' . ($lon >= 0 ? 'Đông' : 'Tây');
}

// validtime của JMA theo giờ Nhật (JST) -> đổi sang giờ Việt Nam.
function vb_ts($item) {
    $vt = $item['validtime'] ?? null;
    $raw = is_array($vt) ? ($vt['JST'] ?? $vt['UTC'] ?? '') : (is_string($vt) ? $vt : '');
    if ($raw === '') return null;
    try {
        $hasTz = preg_match('/(Z|[+\-]\d{2}:?\d{2})$/', $raw);
        $tz = new DateTimeZone(is_array($vt) && isset($vt['JST']) ? 'Asia/Tokyo' : 'UTC');
        $d = $hasTz ? new DateTime($raw) : new DateTime($raw, $tz);
        return $d->getTimestamp();
    } catch (Exception $e) {
        return null;
    }
}

function vb_vn_date($ts) {
    return (new DateTime('@' . $ts))->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'));
}

function vb_time_text($ts) {
    if ($ts === null) return null;
    $d = vb_vn_date($ts);
    $min = (int)$d->format('i');
    return (int)$d->format('G') . ' giờ' . ($min ? ' ' . $min . ' phút' : '')
         . ' ngày ' . (int)$d->format('j') . ' tháng ' . (int)$d->format('n');
}

function vb_time_short($ts) {
    if ($ts === null) return null;
    $d = vb_vn_date($ts);
    $min = (int)$d->format('i');
    return (int)$d->format('G') . 'h' . ($min ? $d->format('i') : '') . ' ' . $d->format('d/m');
}

// Hướng + tốc độ di chuyển.
function vb_move_parts($item) {
    $course = vb_str($item['course'] ?? '');
    $speedRaw = $item['speed'] ?? null;
    $speedStr = vb_str(is_array($speedRaw) ? ($speedRaw['jp'] ?? $speedRaw['en'] ?? '') : $speedRaw);
    $kmh = (is_array($speedRaw) && isset($speedRaw['km/h']) && is_numeric($speedRaw['km/h']) && (float)$speedRaw['km/h'] > 0)
        ? (int)round((float)$speedRaw['km/h']) : null;

    $all = $course . ' ' . $speedStr;
    $stationary = strpos($all, '停滞') !== false || stripos($all, 'STATIONARY') !== false;
    $slow = strpos($all, 'ゆっくり') !== false || stripos($all, 'SLOW') !== false;

    $key = strtoupper(preg_replace('/\s+|ゆっくり|SLOWLY|SLOW/iu', '', $course));
    $dir = null;
    if (isset(VB_COMPASS_JP[$key])) $dir = VB_COMPASS_VI[VB_COMPASS_JP[$key]];
    elseif (isset(VB_COMPASS_VI[$key])) $dir = VB_COMPASS_VI[$key];

    $speedClass = vb_speed_class($kmh, $stationary, $slow);
    return ['dir' => $dir, 'kmh' => $kmh, 'slow' => $slow, 'stationary' => $stationary || $speedClass === 'still', 'speedClass' => $speedClass];
}

// Phân loại tốc độ di chuyển của bão theo km/h:
// < 5 gần như đứng yên | 5-<10 rất chậm | 10-<15 chậm | 15-<20 bình thường (không ghi trạng thái) | 20-<25 nhanh | >= 25 rất nhanh.
// Không có số km/h thì dựa vào nhãn "chậm"/"đứng yên" của JMA.
function vb_speed_class($kmh, $stationary = false, $slow = false) {
    if ($stationary) return 'still';
    if ($kmh === null) return $slow ? 'slow' : null;
    if ($kmh < 5) return 'still';
    if ($kmh < 10) return 'veryslow';
    if ($kmh < 15) return 'slow';
    if ($kmh < 20) return null;
    if ($kmh < 25) return 'fast';
    return 'veryfast';
}

// Cụm trạng thái tốc độ đi kèm động từ di chuyển ('' nếu không có).
function vb_speed_adverb($class) {
    switch ($class) {
        case 'veryslow': return ' rất chậm';
        case 'slow':     return ' chậm';
        case 'fast':     return ' nhanh';
        case 'veryfast': return ' rất nhanh';
    }
    return '';
}

// Câu di chuyển: "di chuyển nhanh theo hướng Tây Tây Bắc, mỗi giờ ��i được khoảng 22 km".
// $withSpeed = false: chỉ nêu hướng (khi câu "đổi tốc độ" ngay sau đã nói tốc độ).
function vb_move($item, $withSpeed = true) {
    $m = vb_move_parts($item);
    if ($m['stationary']) return vb_pick('still', ['gần như đứng yên', 'hầu như không di chuyển', 'gần như không dịch chuyển']);
    if (!$withSpeed && $m['dir']) return vb_pick('move-verb', ['di chuyển', 'dịch chuyển']) . ' theo hướng ' . $m['dir'];
    $speedText = $m['kmh'] ? vb_pick('speed', [
        'mỗi giờ đi được khoảng ' . $m['kmh'] . ' km',
        'với tốc độ khoảng ' . $m['kmh'] . ' km/h',
        'tốc độ di chuyển khoảng ' . $m['kmh'] . ' km mỗi giờ',
    ]) : null;
    $verb = vb_pick('move-verb', ['di chuyển', 'dịch chuyển']);
    $adv = vb_speed_adverb($m['speedClass']);
    if ($m['dir']) return $verb . $adv . ' theo hướng ' . $m['dir'] . ($speedText ? ', ' . $speedText : '');
    if ($speedText) return 'di chuyển' . $adv . ', ' . $speedText;
    return $adv !== '' ? 'di chuyển' . $adv : '';
}

// Nhãn ngắn hiển thị trên bản đồ khi bão tịnh tiến.
function vb_move_tag($item) {
    $m = vb_move_parts($item);
    if ($m['stationary']) return 'Gần như đứng yên';
    $label = trim(vb_speed_adverb($m['speedClass']));
    $parts = array_filter([$m['dir'] ? 'Hướng ' . $m['dir'] : null, $m['kmh'] ? $m['kmh'] . ' km/h' : null, $label !== '' ? vb_ucfirst($label) : null]);
    return $parts ? implode(' · ', $parts) : null;
}

// ----------------------------------------------------------------------------- Đa dạng câu chữ
// Chọn cách diễn đạt theo hạt giống (mã bão + thời điểm phân tích): cùng một bản tin luôn ra cùng câu
// (giọng đọc, JSON, đoạn văn khớp nhau), nhưng mỗi lần cập nhật/mỗi cơn bão lại có cách nói khác.
// Cùng một loại câu không dùng lặp lại một cách diễn đạt hai lần liên tiếp.
function vb_vary_state($reset = null) {
    static $st = ['seed' => '', 'n' => 0, 'last' => []];
    if ($reset !== null) $st = ['seed' => (string)$reset, 'n' => 0, 'last' => []];
    return $st;
}
function vb_pick($slot, array $opts) {
    $opts = array_values($opts);
    $c = count($opts);
    if ($c <= 1) return $opts[0] ?? '';
    static $last = [], $seedSeen = null, $n = 0;
    $st = vb_vary_state();
    if ($seedSeen !== $st['seed']) { $seedSeen = $st['seed']; $last = []; $n = 0; }
    $i = (int)(sprintf('%u', crc32($st['seed'] . '|' . $slot . '|' . ($n++))) % $c);
    if (isset($last[$slot]) && $last[$slot] === $i) $i = ($i + 1) % $c;
    $last[$slot] = $i;
    return $opts[$i];
}

// Xu hướng cường độ giữa 2 mốc (dạng vị ngữ, đứng được sau chủ ngữ hoặc sau "và").
function vb_trend($prevLv, $nextLv) {
    $prevC = vb_class($prevLv);
    $nextC = vb_class($nextLv);
    // Mốc tan: câu "suy yếu và tan dần" riêng đã nói, không lặp ở câu di chuyển.
    if ($nextLv === null || $prevLv === null) return '';
    if ($nextC !== $prevC) {
        if ($nextLv > $prevLv) return vb_pick('trend-up-cls', ['có khả năng mạnh lên thành ', 'có thể mạnh lên thành ', 'nhiều khả năng mạnh lên thành ']) . $nextC;
        $to = $nextC === 'vùng áp thấp' ? 'một vùng áp thấp' : $nextC;
        return vb_pick('trend-down-cls', ['suy yếu thành ', 'giảm cấp, suy yếu thành ', 'có khả năng suy yếu thành ']) . $to;
    }
    if ($nextLv > $prevLv) return vb_pick('trend-up', ['có khả năng mạnh thêm', 'có thể tiếp tục mạnh lên', 'nhiều khả năng còn mạnh thêm']);
    if ($nextLv < $prevLv) return vb_pick('trend-down', ['có xu hướng suy yếu dần', 'có xu hướng yếu đi', 'suy yếu dần']);
    return '';
}

// Chủ ngữ trong câu dự báo: xen kẽ "bão" / "cơn bão" / "bão <tên>".
function vb_subject_var($cls, $name) {
    if ($cls === 'bão') return vb_pick('subj', array_filter(['bão', 'cơn bão', $name ? 'bão ' . $name : null]));
    if ($cls === 'siêu bão') return vb_pick('subj', array_filter(['siêu bão', $name ? 'siêu bão ' . $name : null]));
    return $cls;
}

// Danh từ chỉ tâm: "bão" (cả siêu bão), "áp thấp nhiệt đới", "vùng áp thấp".
function vb_center_noun($cls) {
    return ($cls === 'bão' || $cls === 'siêu bão' || $cls === 'tan') ? 'bão' : $cls;
}

function vb_wind_sentence($cls, $lv, $gustMs) {
    if ($lv === null) return null;
    $n = vb_center_noun($cls);
    $g = vb_gust_text($gustMs);
    if ($lv < 6) {
        $s = vb_pick('wind', [
            'Sức gió mạnh nhất vùng gần tâm ' . $n . ' mạnh dưới cấp 6',
            'Vùng gần tâm ' . $n . ' có sức gió mạnh nhất dưới cấp 6',
        ]);
        return $s . ($g ? ', giật ' . $g : '') . '.';
    }
    $lvTxt = 'cấp ' . $lv;
    $opts = [
        ['Sức gió mạnh nhất vùng gần tâm ' . $n . ' mạnh ' . $lvTxt, ', giật '],
        ['Gió mạnh nhất ở vùng gần tâm ' . $n . ' đạt ' . $lvTxt, ', giật '],
        ['Vùng gần tâm ' . $n . ' có sức gió mạnh nhất ' . $lvTxt, ', giật '],
        ['Cường độ gió mạnh nhất vùng gần tâm ' . $n . ' ở mức ' . $lvTxt, ', có lúc giật '],
    ];
    [$s, $gl] = vb_pick('wind', $opts);
    return $s . ($g ? $gl . $g : '') . '.';
}

// Phân t��ch galeWarning/stormWarning (giống parseWindArea() trong ty.php).
// null | {uniform:true, km} | {uniform:false, longKm, longBearing, shortKm, shortBearing}
function vb_wind_area($arr) {
    if (!is_array($arr) || !$arr) return null;
    $allKm = null;
    $dirs = [];
    foreach ($arr as $w) {
        if (!is_array($w)) continue;
        $a = $w['area'] ?? '';
        $en = is_array($a) ? (string)($a['en'] ?? '') : (string)$a;
        $jp = is_array($a) ? (string)($a['jp'] ?? $a['ja'] ?? '') : (string)$a;
        $km = $w['range']['km'] ?? null;
        if (!is_numeric($km) || (float)$km <= 0) continue;
        $km = (float)$km;
        if ($en === 'All' || $en === '全域' || $jp === '全域') { $allKm = $km; continue; }
        $b = VB_AREA_BEARING[$jp] ?? VB_AREA_BEARING[strtoupper($en)] ?? null;
        if ($b !== null) $dirs[] = ['km' => $km, 'bearing' => $b];
    }
    if (!$dirs) return $allKm !== null ? ['uniform' => true, 'km' => (int)round($allKm)] : null;
    if (count($dirs) === 1) return ['uniform' => true, 'km' => (int)round($dirs[0]['km'])];
    $lng = $dirs[0]; $sht = $dirs[0];
    foreach ($dirs as $d) { if ($d['km'] > $lng['km']) $lng = $d; if ($d['km'] < $sht['km']) $sht = $d; }
    if ($lng['km'] == $sht['km']) return ['uniform' => true, 'km' => (int)round($lng['km'])];
    return [
        'uniform' => false,
        'longKm' => (int)round($lng['km']), 'longBearing' => $lng['bearing'],
        'shortKm' => (int)round($sht['km']), 'shortBearing' => $sht['bearing'],
    ];
}

// Bán kính đều (km) từ track_json phần Analysis: galeWarningArea.radius (m), stormWarningArea.arc[0][1] (m).
function vb_track_radii($trackJson) {
    $t = json_decode((string)$trackJson, true);
    $out = ['gale' => null, 'storm' => null];
    if (!is_array($t)) return $out;
    foreach ($t as $d) {
        if (!is_array($d)) continue;
        $p = $d['part'] ?? '';
        if (!($p === 'Analysis' || (is_array($p) && ($p['en'] ?? '') === 'Analysis'))) continue;
        $g = $d['galeWarningArea']['radius'] ?? null;
        if (is_numeric($g) && $g > 0) $out['gale'] = (int)round($g / 1000);
        $arc = $d['stormWarningArea']['arc'][0] ?? null;
        if (is_array($arc) && isset($arc[1]) && is_numeric($arc[1]) && $arc[1] > 0) $out['storm'] = (int)round($arc[1] / 1000);
        break;
    }
    return $out;
}

// Giống ty.php: spec_json quyết định có vẽ hay không; vùng đều thì ưu tiên bán kính track_json.
function vb_merge_radius($area, $trackKm) {
    if (!$area) return null;
    if ($area['uniform'] && $trackKm) $area['km'] = $trackKm;
    return $area;
}

function vb_radius_sentence($area, $level, $noun) {
    if (!$area) return null;
    $L = 'cấp ' . $level . ' trở lên';
    if ($area['uniform']) {
        $km = $area['km'] . ' km';
        return vb_pick('radius', [
            'Bán kính gió mạnh từ ' . $L . ' khoảng ' . $km . ' tính từ tâm ' . $noun . '.',
            'Vùng có gió mạnh từ ' . $L . ' bao trùm bán kính khoảng ' . $km . ' quanh tâm ' . $noun . '.',
            'Gió mạnh từ ' . $L . ' ảnh hưởng trong phạm vi bán kính khoảng ' . $km . ' tính từ tâm ' . $noun . '.',
        ]);
    }
    $lk = $area['longKm'] . ' km'; $ld = VB_BEARING_VI[$area['longBearing']];
    $sk = $area['shortKm'] . ' km'; $sd = VB_BEARING_VI[$area['shortBearing']];
    return vb_pick('radius', [
        'Vùng gió mạnh từ ' . $L . ' có bán kính khoảng ' . $lk . ' về phía ' . $ld . ' và khoảng ' . $sk . ' về phía ' . $sd . ' tính từ tâm ' . $noun . '.',
        'Gió mạnh từ ' . $L . ' lan rộng tới khoảng ' . $lk . ' về phía ' . $ld . ' của tâm ' . $noun . ', trong khi ở phía ' . $sd . ' chỉ khoảng ' . $sk . '.',
        'Tính từ tâm ' . $noun . ', vùng gió mạnh từ ' . $L . ' rộng nhất khoảng ' . $lk . ' về phía ' . $ld . ' và hẹp nhất khoảng ' . $sk . ' về phía ' . $sd . '.',
    ]);
}

function vb_name($raw) {
    $n = trim((string)$raw);
    if ($n === '' || preg_match('/^(TD|TC|N\/A|-+|NONAME|UNNAMED)$/i', $n)) return '';
    return mb_convert_case(mb_strtolower($n, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
}

function vb_ucfirst($s) {
    return mb_strtoupper(mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($s, 1, null, 'UTF-8');
}

// Áp suất thấp nhất gần tâm (hPa) - cùng trường "pressure" mà ty.php đọc từ spec_json của JMA.
function vb_pressure($item) {
    $p = $item['pressure'] ?? null;
    if (is_array($p)) $p = $p['hPa'] ?? $p['hpa'] ?? null;
    if (!is_numeric($p)) return null;
    $p = (float)$p;
    return ($p >= 850 && $p <= 1100) ? (int)round($p) : null;
}

function vb_pressure_sentence($cls, $pres, $now = true) {
    if ($pres === null || $cls === 'tan') return null;
    $n = vb_center_noun($cls);
    $val = $pres . ' hPa';
    if ($now) {
        return vb_pick('pres-now', [
            'Áp suất thấp nhất gần tâm ' . $n . ' khoảng ' . $val . '.',
            'Khí áp thấp nhất ở vùng gần tâm ' . $n . ' vào khoảng ' . $val . '.',
            'Hiện áp suất thấp nhất gần tâm ' . $n . ' đạt khoảng ' . $val . '.',
        ]);
    }
    return vb_pick('pres-fc', [
        'Áp suất thấp nhất gần tâm ' . $n . ' dự báo khoảng ' . $val . '.',
        'Khí áp thấp nhất gần tâm ' . $n . ' được dự báo vào khoảng ' . $val . '.',
        'Dự báo áp suất thấp nhất gần tâm ' . $n . ' ở mức ' . $val . '.',
    ]);
}

function vb_point($item) {
    $sus = vb_wind_ms($item['maximumWind']['sustained'] ?? null);
    $gust = vb_wind_ms($item['maximumWind']['gust'] ?? null);
    $lv = vb_level($sus);
    [$lat, $lon] = vb_latlon($item);
    return [
        'item'  => $item,
        'ts'    => vb_ts($item),
        'lat'   => $lat,
        'lon'   => $lon,
        'coord' => vb_coord_text($lat, $lon),
        'sus'   => $sus,
        'gust'  => $gust,
        'pres'  => vb_pressure($item),
        'lv'    => $lv,
        'cls'   => vb_class($lv),
        'move'  => vb_move($item),
        'mp'    => vb_move_parts($item),
    ];
}

// Phương vị (độ) của tên hướng tiếng Việt, null nếu không rõ.
function vb_dir_deg($name) {
    if (!$name) return null;
    $i = array_search($name, array_values(VB_COMPASS_VI), true);
    return $i === false ? null : $i * 22.5;
}

// Bậc tốc độ: 0 đứng yên, 1 rất chậm, 2 chậm, 3 bình thường, 4 nhanh, 5 rất nhanh; null nếu không rõ.
function vb_speed_rank($mp) {
    if (!$mp) return null;
    switch ($mp['speedClass']) {
        case 'still': return 0;
        case 'veryslow': return 1;
        case 'slow': return 2;
        case 'fast': return 4;
        case 'veryfast': return 5;
    }
    return $mp['kmh'] !== null ? 3 : null;
}

// Đổi bậc tốc độ đáng kể: khác bậc, và nếu có số km/h thì chênh tối thiểu 5 km/h (18 -> 20 km/h không tính).
function vb_speed_changed($pm, $fm) {
    $r0 = vb_speed_rank($pm); $r1 = vb_speed_rank($fm);
    if ($r0 === null || $r1 === null || $r0 === $r1) return false;
    if ($pm['kmh'] !== null && $fm['kmh'] !== null && abs($fm['kmh'] - $pm['kmh']) < 5) return false;
    return true;
}

// Bão / siêu bão xuống áp thấp nhiệt đới hoặc vùng áp thấp giữa 2 mốc.
function vb_is_weaken($prev, $fc) {
    return in_array($prev['cls'], ['bão', 'siêu bão'], true) && in_array($fc['cls'], ['áp thấp nhiệt đới', 'vùng áp thấp'], true);
}

// Câu + hoạt ảnh suy yếu. $lead = "Đến 7 giờ ngày 12 tháng 10, " (nói rõ thời điểm) hoặc "Lúc đó, ".
function vb_weaken_cue($prev, $fc, $k, $name, $lead, $hasXY) {
    $to = $fc['cls'] === 'vùng áp thấp' ? 'một vùng áp thấp' : 'áp thấp nhiệt đới';
    $subj = vb_subject_var($prev['cls'], $name);
    $text = vb_ucfirst($lead . $subj . vb_pick('weak', [
        ' sẽ suy yếu thành ' . $to . '.',
        ' được dự báo suy yếu thành ' . $to . '.',
        ' sẽ yếu đi thành ' . $to . '.',
    ]));
    return vb_cue($text, $hasXY ? 'shift' : 'info', $k, ['shift' => ['kind' => 'weaken', 'title' => 'Suy yếu', 'lab' => vb_ucfirst($fc['cls']), 'col' => '#f59e0b']]);
}

// Đổi hướng / đổi tốc độ giữa 2 mốc. Đặt ngay sau câu di chuyển nên diễn đạt dạng so sánh, không lặp lại câu trước.
function vb_change_cues($prev, $fc, $k, $name, $hasXY) {
    $out = [];
    $act = $hasXY ? 'shift' : 'info';
    $pm = $prev['mp'] ?? null; $fm = $fc['mp'] ?? null;
    if (!$pm || !$fm) return $out;

    if (!$pm['stationary'] && !$fm['stationary']) {
        $a0 = vb_dir_deg($pm['dir']); $a1 = vb_dir_deg($fm['dir']);
        if ($a0 !== null && $a1 !== null) {
            $delta = fmod($a1 - $a0 + 540, 360) - 180;
            if (abs($delta) >= 45) {
                $subj = vb_subject_var($prev['cls'], $name);
                $text = vb_pick('turn', [
                    'So với trước đó, ' . $subj . ' sẽ đổi hướng, từ hướng ' . $pm['dir'] . ' chuyển sang hướng ' . $fm['dir'] . '.',
                    'Đáng chú ý, ' . $subj . ' sẽ chuyển hướng từ ' . $pm['dir'] . ' sang ' . $fm['dir'] . '.',
                    vb_ucfirst($subj) . ' có sự chuyển hướng rõ rệt, từ hướng ' . $pm['dir'] . ' sang hướng ' . $fm['dir'] . '.',
                ]);
                $out[] = vb_cue($text, $act, $k, ['shift' => ['kind' => 'turn', 'title' => 'Đổi hướng', 'lab' => $fm['dir'], 'col' => '#a78bfa',
                    'a0' => $a0, 'a1' => $a0 + $delta, 'l0' => 70, 'l1' => 70]]);
            }
        }
    }

    if (vb_speed_changed($pm, $fm)) {
        $r0 = vb_speed_rank($pm); $r1 = vb_speed_rank($fm);
        $up = $r1 > $r0;
        $subj = vb_subject_var($prev['cls'], $name);
        $lead = $out ? 'Đồng thời, ' : vb_pick('spd-lead', ['So với trước đó, ', 'Cũng trong thời gian này, ']);
        $word = $up ? vb_pick('spd-up', ['di chuyển nhanh dần', 'tăng tốc', 'di chuyển nhanh hơn'])
                    : vb_pick('spd-dn', ['di chuyển chậm lại', 'giảm tốc độ di chuyển', 'di chuyển chậm dần']);
        $kmh = ($pm['kmh'] !== null && $fm['kmh'] !== null && $pm['kmh'] !== $fm['kmh'])
            ? ', tốc độ ' . ($up ? 'tăng' : 'giảm') . ' từ khoảng ' . $pm['kmh'] . ' ' . ($up ? 'lên' : 'xuống') . ' khoảng ' . $fm['kmh'] . ' km/h' : '';
        $text = $lead . $subj . ' ' . $word . $kmh . '.';
        $dirDeg = vb_dir_deg($fm['dir']) ?? vb_dir_deg($pm['dir']) ?? 0;
        $col = $r1 >= 4 ? '#ef4444' : ($r1 === 3 ? '#22c55e' : '#38bdf8');
        $lab = $fm['kmh'] !== null ? $fm['kmh'] . ' km/h' : ($up ? 'Nhanh dần' : 'Chậm lại');
        $out[] = vb_cue($text, $act, $k, ['shift' => ['kind' => 'speed', 'title' => $up ? 'Tăng tốc' : 'Giảm tốc', 'lab' => $lab, 'col' => $col,
            'a0' => $dirDeg, 'a1' => $dirDeg, 'l0' => 30 + $r0 * 14, 'l1' => 30 + $r1 * 14]]);
    }
    return $out;
}

// Biển Đông (xấp xỉ theo khung kinh/vĩ độ, đủ dùng cho tiêu đề/tag).
function vb_in_bien_dong($lat, $lon) {
    return $lat !== null && $lon !== null && $lat >= 3 && $lat <= 23.5 && $lon >= 105 && $lon <= 120.5;
}

function vb_cue($text, $act, $pt, array $extra = []) {
    return array_merge(['text' => $text, 'act' => $act, 'pt' => $pt], $extra);
}

function vb_track_analysis($trackJson) {
    $t = json_decode((string)$trackJson, true);
    if (!is_array($t)) return null;
    foreach ($t as $d) {
        if (!is_array($d)) continue;
        $p = $d['part'] ?? '';
        if ($p === 'Analysis' || (is_array($p) && ($p['en'] ?? '') === 'Analysis')) {
            return is_array($d['track'] ?? null) ? $d : null;
        }
    }
    return null;
}

// track.wind của JMA là m/s đã làm tròn theo quy ước riêng (VD 90 kt -> 46 m/s, tụt từ cấp 15 xuống 14).
// JMA luôn phân tích gió theo bội số 5 kt -> khôi phục kt gốc rồi mới quy cấp, để cấp của các điểm quá khứ
// khớp tuyệt đối với cấp tính từ kt của bản tin hiện tại (tránh báo sai "lần đầu đạt cấp").
function vb_track_level($ms) {
    if (!is_numeric($ms) || (float)$ms <= 0) return null;
    $kt = round((float)$ms / 0.514444 / 5) * 5;
    return vb_level($kt * 0.514444);
}

// Đường đi quá khứ từ track_json (Analysis -> track.preTyphoon + track.typhoon + track.wind), giống ty.php.
function vb_past_track($trackJson) {
    $an = vb_track_analysis($trackJson);
    if (!$an) return [];
    $ok = fn($c) => is_array($c) && count($c) >= 2 && is_numeric($c[0]) && is_numeric($c[1]);
    $out = [];
    foreach (($an['track']['preTyphoon'] ?? []) as $c) {
        if ($ok($c)) $out[] = [(float)$c[0], (float)$c[1], null];
    }
    $winds = $an['track']['wind'] ?? [];
    foreach (($an['track']['typhoon'] ?? []) as $i => $c) {
        if (!$ok($c)) continue;
        $out[] = [(float)$c[0], (float)$c[1], vb_track_level($winds[$i] ?? null)];
    }
    return $out;
}

// ----------------------------------------------------------------------------- Mốc cường độ trong vòng đời
// Cấp gió của mọi điểm quá khứ TRƯỚC bản tin hiện tại (track.typhoon + track.wind).
// Điểm cuối của track trùng toạ độ phân tích hiện tại chính là bản tin này -> loại ra.
// null = không đủ dữ liệu để kết luận (không có track / có đường đi nhưng thiếu gió) -> không phát mốc nào.
function vb_prior_levels($trackJson, $lat, $lon) {
    $an = vb_track_analysis($trackJson);
    if (!$an) return null;
    $path = array_values(is_array($an['track']['typhoon'] ?? null) ? $an['track']['typhoon'] : []);
    $winds = array_values(is_array($an['track']['wind'] ?? null) ? $an['track']['wind'] : []);
    $n = count($path);
    if ($n && $lat !== null && $lon !== null) {
        $last = $path[$n - 1];
        if (is_array($last) && count($last) >= 2 && is_numeric($last[0]) && is_numeric($last[1])
            && abs($last[0] - $lat) < 0.05 && abs($last[1] - $lon) < 0.05) $n--;
    }
    $levels = [];
    for ($i = 0; $i < $n; $i++) {
        $lv = vb_track_level($winds[$i] ?? null);
        if ($lv !== null) $levels[] = $lv;
    }
    if ($n > 0 && !$levels) return null;
    return $levels;
}

// firstPeak : lần ĐẦU TIÊN đạt cấp gió cao nhất vòng đời đúng ở bản tin này (cấp hiện tại > mọi cấp trước đó;
//             nếu bản tin trước đã đạt cấp đó, hoặc suy yếu rồi mạnh lại đúng cấp cũ -> không tính).
// firstStorm: bản tin đầu tiên mạnh lên thành bão (cấp >= 8) kể từ đầu track.
function vb_milestones(array $storm, array $cur) {
    $out = ['firstPeak' => false, 'firstStorm' => false, 'prevMax' => null];
    if ($cur['lv'] === null || $cur['lv'] < 8) return $out;
    $prev = vb_prior_levels($storm['track'] ?? null, $cur['lat'], $cur['lon']);
    if ($prev === null) return $out;
    $prevMax = $prev ? max($prev) : -1;
    $out['prevMax'] = $prevMax >= 0 ? $prevMax : null;
    $out['firstPeak'] = $cur['lv'] > $prevMax;
    $out['firstStorm'] = $prevMax < 8;
    return $out;
}

function vb_cur_kt($item) {
    $kt = $item['maximumWind']['sustained']['kt'] ?? null;
    if (is_numeric($kt) && (float)$kt > 0) return (float)$kt;
    $ms = vb_wind_ms($item['maximumWind']['sustained'] ?? null);
    return $ms ? round($ms / 0.514444) : 0.0;
}

function vb_kt_row($kt) {
    $kt = (float)$kt;
    return ['kt' => (int)round($kt), 'kmh' => $kt > 0 ? (int)round($kt * 1.852) : null, 'lv' => $kt > 0 ? vb_level($kt * 0.514444) : null];
}

// Xếp hạng cường độ trong năm (cùng nguồn với ty.php: typhoons + typhoon_details, mã "20" + YYNN, gió theo kt).
// Khác ty.php: cơn đang xét dùng gió của bản tin hiện tại (bảng lịch sử có thể chưa cập nhật kịp),
// và đồng sức gió thì đồng hạng (1, 2, 2, 4...).
function vb_year_ranking($conn, $no, $curKt, $curName) {
    $digits = preg_replace('/\D/', '', (string)$no);
    if (!$conn || strlen($digits) !== 4 || $curKt <= 0) return null;
    $year = (int)('20' . substr($digits, 0, 2));
    $self = '20' . $digits;
    $rows = [];
    try {
        $stmt = $conn->prepare(
            "SELECT t.typhoon_number, t.name,
                    (SELECT MAX(d.wind_speed) FROM typhoon_details d WHERE d.typhoon_number = t.typhoon_number) AS max_wind_kt
             FROM typhoons t
             WHERE t.year = ?"
        );
        if (!$stmt) return null;
        $stmt->bind_param('i', $year);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $num = (string)$r['typhoon_number'];
            if ($num === $self) continue;
            $short = strlen($num) === 6 ? substr($num, 2) : $num;
            $rows[] = ['name' => vb_name($r['name'] ?? '') ?: 'Số hiệu ' . $short, 'no' => $short, 'cur' => false]
                    + vb_kt_row($r['max_wind_kt'] ?? 0);
        }
        $stmt->close();
    } catch (Throwable $e) {
        error_log('[video.php] Lỗi xếp hạng cường độ: ' . $e->getMessage());
        return null;
    }
    $rows[] = ['name' => $curName ?: 'Số hiệu ' . $digits, 'no' => $digits, 'cur' => true] + vb_kt_row($curKt);
    usort($rows, fn($a, $b) => ($b['kt'] <=> $a['kt']) ?: ((int)$b['cur'] <=> (int)$a['cur']));

    $rank = null; $tied = [];
    for ($i = 0, $n = count($rows); $i < $n; $i++) {
        $rows[$i]['rank'] = ($i > 0 && $rows[$i - 1]['kt'] === $rows[$i]['kt']) ? $rows[$i - 1]['rank'] : $i + 1;
        if ($rows[$i]['cur']) $rank = $rows[$i]['rank'];
    }
    foreach ($rows as $r) if (!$r['cur'] && $r['rank'] === $rank) $tied[] = $r['name'];
    return ['year' => $year, 'rank' => $rank, 'total' => count($rows), 'tied' => $tied, 'rows' => $rows];
}

// Tóm tắt 1 cơn bão lịch sử từ track_data của ncics_storm (giống processStormDataByAgency() trong ty.php:
// ưu tiên đài TOKYO/JMA, thiếu thì lấy giá trị mạnh nhất trong các đài).
function vb_hist_summary($trackData) {
    $data = is_string($trackData) ? json_decode($trackData, true) : $trackData;
    $out = ['kt' => 0, 'start' => null, 'end' => null];
    if (!is_array($data)) return $out;
    $ts = []; $winds = []; $curDate = '';
    foreach ($data as $row) {
        if (!is_array($row)) continue;
        $iso = trim((string)($row['ISOTIME'] ?? $row['ISO_TIME'] ?? ''));
        if ($iso !== '') {
            if (strpos($iso, '-') !== false) { $curDate = substr($iso, 0, 10); $full = $iso; }
            else $full = $curDate !== '' ? $curDate . ' ' . $iso : '';
            if ($full !== '') { $t = strtotime($full); if ($t !== false) $ts[] = $t; }
        }
        foreach ($row as $k => $v) {
            if ($v === null || $v === '' || !is_numeric($v) || (float)$v <= 0) continue;
            $k = strtoupper(trim((string)$k));
            if (substr($k, -4) !== 'WIND') continue;
            $agency = str_replace('_', '', trim(substr($k, 0, -4))) ?: 'WMO';
            $winds[$agency] = max($winds[$agency] ?? 0, (float)$v);
        }
    }
    $out['kt'] = $winds['TOKYO'] ?? ($winds ? max($winds) : 0);
    if ($ts) { $out['start'] = min($ts); $out['end'] = max($ts); }
    return $out;
}

// Các cơn bão cùng tên trong các năm TRƯỚC (ncics_storm, besttrack = 1), xếp theo năm tăng dần.
function vb_name_history($conn, $rawName, $year, $curKt) {
    $name = trim((string)$rawName);
    if (!$conn || vb_name($name) === '' || !$year) return null;
    $rows = [];
    try {
        $stmt = $conn->prepare(
            "SELECT id, storm_id, name, year, landfall, track_data
             FROM ncics_storm
             WHERE besttrack = 1 AND UPPER(name) = UPPER(?) AND year < ?
             ORDER BY year ASC, id ASC"
        );
        if (!$stmt) return null;
        $stmt->bind_param('si', $name, $year);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $sum = vb_hist_summary($r['track_data'] ?? null);
            $period = null;
            if ($sum['start']) {
                $a = vb_vn_date($sum['start']); $b = vb_vn_date($sum['end']);
                $period = $a->format('d/m') . ' - ' . $b->format('d/m/Y');
            }
            $rows[] = ['year' => (int)$r['year'], 'period' => $period, 'landfall' => (int)($r['landfall'] ?? 0) === 1, 'cur' => false]
                    + vb_kt_row($sum['kt']);
        }
        $stmt->close();
    } catch (Throwable $e) {
        error_log('[video.php] Lỗi tra lịch sử tên bão: ' . $e->getMessage());
        return null;
    }
    $strongest = null;
    foreach ($rows as $i => $r) if ($r['kt'] > 0 && ($strongest === null || $r['kt'] > $rows[$strongest]['kt'])) $strongest = $i;
    $count = count($rows);
    $rows[] = ['year' => (int)$year, 'period' => null, 'landfall' => false, 'cur' => true] + vb_kt_row($curKt);
    return [
        'name'      => vb_name($name),
        'count'     => $count,
        'ordinal'   => $count + 1,
        'years'     => array_values(array_unique(array_column(array_slice($rows, 0, $count), 'year'))),
        'strongest' => $strongest,
        'rows'      => $rows,
    ];
}

// Thứ tự cơn bão trong năm trên Tây Bắc Thái Bình Dương = 2 số cuối của số hiệu JMA (YYNN).
// JMA chỉ cấp số hiệu khi xoáy thuận đạt cường độ bão (TS, cấp 8) và cấp tuần tự trong năm,
// nên đây chính xác là "cơn bão thứ NN" - không phụ thuộc bảng lịch sử đã cập nhật hay chưa.
function vb_season_seq($no) {
    $digits = preg_replace('/\D/', '', (string)$no);
    if (strlen($digits) === 6) $digits = substr($digits, 2);
    if (strlen($digits) !== 4 || (int)substr($digits, 2) < 1) return null;
    return ['n' => (int)substr($digits, 2), 'year' => (int)('20' . substr($digits, 0, 2))];
}

function vb_ordinal($n) {
    return [1 => 'nhất', 2 => 'hai', 3 => 'ba', 4 => 'tư'][$n] ?? (string)$n;
}

function vb_join_vi(array $items) {
    $items = array_values($items);
    $n = count($items);
    if ($n <= 1) return $items[0] ?? '';
    return implode(', ', array_slice($items, 0, -1)) . ' và ' . $items[$n - 1];
}

// Cảnh "mốc đáng chú ý": lịch sử tên (lần đầu thành bão) rồi xếp hạng năm (lần đầu đạt đỉnh cường độ).
function vb_milestone_scenes(array $ms, array $cur, $name, $rank, $hist, $seq = null) {
    $scenes = [];
    $pre = $cur['cls'] === 'siêu bão' ? 'siêu bão' : 'bão';
    $who = $name ? $pre . ' ' . $name : 'cơn ' . $pre . ' này';
    $basin = 'khu vực Tây Bắc Thái Bình Dương';

    if ($ms['firstStorm']) {
        $sc = [vb_cue(vb_pick('ms-born', [
            'Đáng chú ý, đây là bản tin đầu tiên ghi nhận ' . ($name ? 'xoáy thuận này mạnh lên thành bão và được đặt tên ' . $name : 'xoáy thuận này mạnh lên thành bão') . '.',
            'Đây là thời điểm đầu tiên xoáy thuận đạt cường độ bão' . ($name ? ' và mang tên ' . $name : '') . ' kể từ khi hình thành.',
        ]), 'info', 0)];
        if ($seq) {
            $sc[] = vb_cue($seq['n'] === 1
                ? vb_ucfirst($who) . ' là cơn bão đầu tiên hình thành trên ' . $basin . ' trong năm ' . $seq['year'] . '.'
                : vb_ucfirst($who) . ' là cơn bão thứ ' . vb_ordinal($seq['n']) . ' hình thành trên ' . $basin . ' trong năm ' . $seq['year'] . '.', 'info', 0);
        }
        if ($hist && $hist['count'] === 0) {
            $sc[] = vb_cue('Theo dữ liệu lịch sử, đây là lần đầu tiên tên ' . $hist['name'] . ' được dùng để đặt cho một cơn bão trên ' . $basin . '.', 'info', 0);
        } elseif ($hist) {
            $after = $hist['count'] === 1
                ? 'sau cơn bão cùng tên vào năm ' . $hist['years'][0]
                : (count($hist['years']) > 8
                    ? 'sau ' . $hist['count'] . ' cơn bão cùng tên, gần nhất vào năm ' . end($hist['years'])
                    : 'sau các cơn bão cùng tên vào các năm ' . vb_join_vi($hist['years']));
            $sc[] = vb_cue(vb_ucfirst($who) . ' là cơn bão thứ ' . vb_ordinal($hist['ordinal']) . ' mang tên này trong lịch sử, ' . $after . '.', 'history', 0);
            if ($hist['strongest'] !== null) {
                $s = $hist['rows'][$hist['strongest']];
                $txt = $hist['count'] === 1
                    ? 'Cơn bão ' . $hist['name'] . ' năm ' . $s['year'] . ' khi đó từng đạt sức gió mạnh nhất cấp ' . $s['lv'] . '.'
                    : 'Trong số đó, mạnh nhất là cơn bão ' . $hist['name'] . ' năm ' . $s['year'] . ' với sức gió mạnh nhất cấp ' . $s['lv'] . '.';
                $sc[] = vb_cue($txt, 'history', 0, ['hl' => 'strongest']);
            }
        }
        $scenes[] = $sc;
    }

    if ($ms['firstPeak']) {
        $sc = [];
        // Lần đầu thành bão thì cũng là đỉnh mới -> không lặp lại ý "mạnh nhất kể từ khi hình thành".
        if (!$ms['firstStorm']) {
            $sc[] = vb_cue(vb_pick('ms-peak', [
                'Đây cũng là lần đầu tiên ' . $who . ' đạt sức gió cấp ' . $cur['lv'] . ', mức mạnh nhất kể từ khi hình thành.',
                'Với sức gió cấp ' . $cur['lv'] . ', ' . $who . ' đang đạt cường độ mạnh nhất kể từ khi hình thành.',
            ]), 'wind', 0);
        }
        if ($rank && $rank['total'] >= 2) {
            $since = 'trong tổng số ' . $rank['total'] . ' cơn bão hình thành trên ' . $basin . ' từ đầu năm ' . $rank['year'];
            $tie = $rank['tied'] ? ', ngang bằng với ' . vb_join_vi(array_map(fn($n) => 'bão ' . $n, $rank['tied'])) : '';
            $txt = $rank['rank'] === 1
                ? 'Với cường độ này, ' . $who . ' đang là cơn bão mạnh nhất ' . $since . $tie . '.'
                : 'Với cường độ này, ' . $who . ' đang là cơn bão mạnh thứ ' . vb_ordinal($rank['rank']) . ' ' . $since . $tie . '.';
            $sc[] = vb_cue($txt, 'rank', 0);
        } elseif ($rank) {
            $sc[] = vb_cue(vb_ucfirst($who) . ' hiện là cơn bão duy nhất hình thành trên ' . $basin . ' từ đầu năm ' . $rank['year'] . '.', 'info', 0);
        }
        if ($sc) $scenes[] = $sc;
    }
    return $scenes;
}

// ----------------------------------------------------------------------------- Khoảng cách tới đất liền Việt Nam
// Đọc polygon tỉnh từ vn.json (cùng file ty.php dùng). Chỉ giữ phần ĐẤT LIỀN: mỗi tỉnh lấy mảnh lớn nhất
// cùng các mảnh >= VB_MAINLAND_MIN_KM2; đảo và quần đảo (Phú Quốc, Côn Đảo, Cát Bà, Hoàng Sa, Trường Sa...) bị loại.
const VB_MAINLAND_MIN_KM2 = 1500;
const VB_LAND_MAX_KM = 2500;   // xa hơn ngưỡng này thì không nhắc khoảng cách (tránh rối, vô nghĩa)
const VB_COAST_NEAR_KM = 50;   // dưới ngưỡng này coi là áp sát bờ

function vb_ring_area_km2(array $ring) {
    $n = count($ring);
    if ($n < 3) return 0.0;
    $a = 0.0; $latSum = 0.0;
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $a += ($ring[$j][0] * $ring[$i][1]) - ($ring[$i][0] * $ring[$j][1]);
        $latSum += $ring[$i][1];
    }
    return abs($a) / 2 * 111.32 * 111.32 * cos(deg2rad($latSum / $n));
}

function vb_mainland() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    $raw = null;
    $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $candidates = [__DIR__ . '/../vn.json', __DIR__ . '/vn.json', dirname(__DIR__, 2) . '/vn.json'];
    if ($docRoot !== '') $candidates[] = $docRoot . '/vn.json';
    foreach ($candidates as $f) {
        if (@is_file($f)) { $raw = @file_get_contents($f); if ($raw) break; }
    }
    if ($raw) $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw); // bỏ BOM nếu có
    $gj = $raw ? json_decode($raw, true) : null;
    if (!is_array($gj)) error_log('[video.php] Không đọc được vn.json để tính khoảng cách tới đất liền.');
    if (!is_array($gj) || !is_array($gj['features'] ?? null)) return $cache;

    foreach ($gj['features'] as $ft) {
        $g = $ft['geometry'] ?? null;
        if (!is_array($g)) continue;
        $polys = $g['type'] === 'Polygon' ? [$g['coordinates']] : ($g['type'] === 'MultiPolygon' ? $g['coordinates'] : []);
        $name = trim((string)($ft['properties']['adm1_name1'] ?? $ft['properties']['adm1_name'] ?? ''));
        if ($name === '' || !$polys) continue;
        $type = trim((string)($ft['properties']['adm1_type_vi'] ?? ''));
        $parts = [];
        foreach ($polys as $poly) {
            $outer = $poly[0] ?? null;
            if (!is_array($outer) || count($outer) < 4) continue;
            $parts[] = ['ring' => $outer, 'km2' => vb_ring_area_km2($outer)];
        }
        if (!$parts) continue;
        usort($parts, fn($a, $b) => $b['km2'] <=> $a['km2']);
        if ($parts[0]['km2'] < VB_MAINLAND_MIN_KM2) continue; // cả "tỉnh" chỉ là đảo nhỏ
        foreach ($parts as $i => $p) {
            if ($i > 0 && $p['km2'] < VB_MAINLAND_MIN_KM2) continue;
            $minX = $minY = INF; $maxX = $maxY = -INF;
            foreach ($p['ring'] as $c) {
                $minX = min($minX, $c[0]); $maxX = max($maxX, $c[0]);
                $minY = min($minY, $c[1]); $maxY = max($maxY, $c[1]);
            }
            if ($minX > 109.6) continue; // không có đất liền nào của Việt Nam ở xa hơn kinh độ này
            $cache[] = ['name' => $name, 'type' => $type, 'ring' => $p['ring'], 'bbox' => [$minX, $minY, $maxX, $maxY]];
        }
    }
    return $cache;
}

function vb_haversine_km($lat1, $lon1, $lat2, $lon2) {
    $dLat = deg2rad($lat2 - $lat1); $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function vb_point_in_ring($x, $y, array $ring) {
    $in = false;
    for ($i = 0, $j = count($ring) - 1; $i < count($ring); $j = $i++) {
        [$xi, $yi] = $ring[$i]; [$xj, $yj] = $ring[$j];
        if ((($yi > $y) !== ($yj > $y)) && ($x < ($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) $in = !$in;
    }
    return $in;
}

// -> null | ['onLand'=>bool, 'prov'=>tên tỉnh gốc, 'km'=>int, 'lat','lon' = điểm đất liền gần nhất]
function vb_nearest_mainland($lat, $lon) {
    if ($lat === null || $lon === null) return null;
    static $memo = [];
    $key = $lat . '|' . $lon;
    if (!array_key_exists($key, $memo)) $memo[$key] = vb_nearest_mainland_calc($lat, $lon);
    return $memo[$key];
}

function vb_nearest_mainland_calc($lat, $lon) {
    $parts = vb_mainland();
    if (!$parts) return null;

    foreach ($parts as $p) {
        [$x0, $y0, $x1, $y1] = $p['bbox'];
        if ($lon >= $x0 && $lon <= $x1 && $lat >= $y0 && $lat <= $y1 && vb_point_in_ring($lon, $lat, $p['ring'])) {
            return ['onLand' => true, 'prov' => $p['name'], 'type' => $p['type'] ?? '', 'km' => 0, 'lat' => $lat, 'lon' => $lon];
        }
    }

    // Chiếu phẳng cục bộ quanh tâm bão (đủ chính xác ở quy mô vài nghìn km để tìm điểm gần nhất).
    $kx = 111.32 * cos(deg2rad($lat)); $ky = 110.57;
    $best = INF; $bestPt = null; $bestName = null; $bestType = '';
    foreach ($parts as $p) {
        [$x0, $y0, $x1, $y1] = $p['bbox'];
        $dx = max($x0 - $lon, 0, $lon - $x1) * $kx;
        $dy = max($y0 - $lat, 0, $lat - $y1) * $ky;
        if ($dx * $dx + $dy * $dy >= $best) continue;
        $r = $p['ring'];
        for ($i = 0, $n = count($r) - 1; $i < $n; $i++) {
            $ax = ($r[$i][0] - $lon) * $kx;     $ay = ($r[$i][1] - $lat) * $ky;
            $bx = ($r[$i + 1][0] - $lon) * $kx; $by = ($r[$i + 1][1] - $lat) * $ky;
            $vx = $bx - $ax; $vy = $by - $ay;
            $len2 = $vx * $vx + $vy * $vy;
            $t = $len2 > 0 ? max(0, min(1, -($ax * $vx + $ay * $vy) / $len2)) : 0;
            $px = $ax + $t * $vx; $py = $ay + $t * $vy;
            $d2 = $px * $px + $py * $py;
            if ($d2 < $best) { $best = $d2; $bestPt = [$lat + $py / $ky, $lon + $px / $kx]; $bestName = $p['name']; $bestType = $p['type'] ?? ''; }
        }
    }
    if (!$bestPt) return null;
    $km = vb_haversine_km($lat, $lon, $bestPt[0], $bestPt[1]);
    $km = $km < 100 ? (int)(round($km / 5) * 5) : (int)(round($km / 10) * 10);
    // Hướng của bão so với điểm đất liền gần nhất (từ đất liền nhìn ra tâm bão).
    $bearing = vb_bearing_deg($bestPt[0], $bestPt[1], $lat, $lon);
    return ['onLand' => false, 'prov' => $bestName, 'type' => $bestType, 'km' => max(5, $km), 'lat' => round($bestPt[0], 4), 'lon' => round($bestPt[1], 4),
            'dir' => vb_bearing_vi($bearing)];
}

// Phương vị (độ, 0 = Bắc, theo chiều kim đồng hồ) từ điểm 1 tới điểm 2.
function vb_bearing_deg($lat1, $lon1, $lat2, $lon2) {
    $p1 = deg2rad($lat1); $p2 = deg2rad($lat2); $dl = deg2rad($lon2 - $lon1);
    $y = sin($dl) * cos($p2);
    $x = cos($p1) * sin($p2) - sin($p1) * cos($p2) * cos($dl);
    return fmod(rad2deg(atan2($y, $x)) + 360, 360);
}

// 16 hướng la bàn theo cách gọi của Việt Nam.
function vb_bearing_vi($deg) {
    static $names = ['Bắc', 'Bắc Đông Bắc', 'Đông Bắc', 'Đông Đông Bắc', 'Đông', 'Đông Đông Nam', 'Đông Nam', 'Nam Đông Nam',
                     'Nam', 'Nam Tây Nam', 'Tây Nam', 'Tây Tây Nam', 'Tây', 'Tây Tây Bắc', 'Tây Bắc', 'Bắc Tây Bắc'];
    return $names[(int)floor(fmod($deg + 11.25, 360) / 22.5) % 16];
}

// "Tỉnh Quảng Ngãi" / "Quang Ngai" / "TP. Đà Nẵng" -> "tỉnh Quảng Ngãi" / "thành phố Đà Nẵng"
function vb_prov_short($raw) {
    return trim(preg_replace('/^(tỉnh|thành phố|tp\.?)\s+/iu', '', trim((string)$raw)));
}
// Ghép loại đơn vị (adm1_type_vi) + tên (adm1_name1) trong vn.json: "tỉnh Thái Nguyên", "thành phố Hải Phòng".
// Riêng Hà Nội gọi là "thủ đô Hà Nội".
function vb_prov_phrase($raw, $type = '') {
    $n = vb_prov_short($raw);
    if (preg_match('/^Hà Nội$/u', $n)) return 'thủ đô Hà Nội';
    $t = mb_strtolower(trim((string)$type), 'UTF-8');
    if ($t === '') {
        $isCity = preg_match('/^(thành phố|tp\.?)\s/iu', trim((string)$raw))
            || preg_match('/^(Hồ Chí Minh|Hải Phòng|Đà Nẵng|Cần Thơ|Huế|Thừa Thiên Huế)$/u', $n);
        $t = $isCity ? 'thành phố' : 'tỉnh';
    }
    return $t . ' ' . $n;
}

function vb_km_text($km) { return number_format($km, 0, ',', '.'); }

// Vĩ độ lớn hơn ngưỡng này (°N) thì không nhắc khoảng cách tới đất liền Việt Nam.
const VB_LAND_MAX_LAT = 40;

// Câu "cách đất liền" cho 1 vị trí. Trả về null khi:
//  - vĩ độ > 40°N, quá xa / không có dữ liệu;
//  - bão không nằm ở hướng có chữ "Đông" so với đất liền (chỉ bão phía đông mới ghi "còn cách").
// Bão trên đất liền Việt Nam thì chỉ ghi rõ đang ở địa phương nào, không ghi "còn cách".
// $afterThen = true khi câu vị trí ngay trước đã mở đầu bằng "Lúc đó/Khi ấy" -> đổi từ dẫn để không lặp.
function vb_land_cue($p, $k, $afterThen = false) {
    if ($p['lat'] === null || $p['cls'] === 'tan') return null;
    if ($p['lat'] > VB_LAND_MAX_LAT) return null;
    $d = vb_nearest_mainland($p['lat'], $p['lon']);
    if (!$d) return null;
    $noun = vb_center_noun($p['cls']);
    $prov = vb_prov_phrase($d['prov'], $d['type'] ?? '');
    $km = vb_km_text($d['km']) . ' km';
    $when = $afterThen ? 'Tại vị trí này'
        : ($k === 0 ? vb_pick('land-now', ['Lúc này', 'Hiện tại']) : vb_pick('land-then', ['Khi đó', 'Vào thời điểm này']));
    if ($d['onLand']) {
        $s = vb_pick('land-on', [
            $when . ', tâm ' . $noun . ' nằm trên đất liền ' . $prov . '.',
            $when . ', tâm ' . $noun . ' đã đi vào đất liền, thuộc địa phận ' . $prov . '.',
            'Đây là vị trí nằm trên đất liền ' . $prov . '.',
        ]);
    } else {
        $dir = $d['dir'] ?? '';
        if (mb_stripos($dir, 'Đông') === false) return null;
        $where = 'ở phía ' . $dir . ' của đất liền ' . $prov;
        if ($d['km'] <= VB_COAST_NEAR_KM) {
            $s = vb_pick('land-near', [
                $when . ', tâm ' . $noun . ' đã áp sát bờ biển ' . $prov . ', nằm ở hướng ' . $dir . ' và chỉ còn cách đất liền khoảng ' . $km . '.',
                'Tâm ' . $noun . ' khi đó đã rất gần bờ biển ' . $prov . ', ở hướng ' . $dir . ', cách đất liền chỉ khoảng ' . $km . '.',
            ]);
        } elseif ($d['km'] <= VB_LAND_MAX_KM) {
            $s = vb_pick('land-mid', [
                $when . ', tâm ' . $noun . ' ' . $where . ', còn cách đất liền khoảng ' . $km . '.',
                'Tâm ' . $noun . ' nằm ' . $where . ', còn cách khoảng ' . $km . ', đây là điểm gần nhất trên đất liền nước ta.',
                'Từ tâm ' . $noun . ' đến đất liền gần nhất của Việt Nam, thuộc ' . $prov . ', còn khoảng ' . $km . ', tâm ở hướng ' . $dir . ' so với đất liền.',
            ]);
        } else {
            $s = vb_pick('land-far', [
                $when . ', tâm ' . $noun . ' vẫn còn ở rất xa nước ta, ' . $where . ', cách khoảng ' . $km . '.',
                'Tâm ' . $noun . ' còn cách rất xa đất liền Việt Nam, ở hướng ' . $dir . ', khoảng ' . $km . ' nếu tính đến ' . $prov . '.',
            ]);
        }
    }
    return vb_cue($s, 'land', $k, ['land' => $d + ['provShort' => vb_prov_short($d['prov']), 'provFull' => vb_prov_phrase($d['prov'], $d['type'] ?? '')]]);
}

// Dựng bản tin thành các cảnh (scene), mỗi cảnh gồm nhiều cue (câu + hành động bản đồ).
function vb_build_bulletin(array $storm, $conn = null) {
    $spec = is_array($storm['spec']) ? $storm['spec'] : [];
    $analysis = null;
    $forecasts = [];
    foreach ($spec as $item) {
        if (!is_array($item) || vb_is_estimate($item)) continue;
        if (vb_is_analysis($item)) { if (!$analysis) $analysis = vb_point($item); continue; }
        if (!isset($item['position']) && !isset($item['validtime'])) continue;
        $forecasts[] = vb_point($item);
    }
    if (!$analysis) return null;
    usort($forecasts, fn($a, $b) => ($a['ts'] ?? PHP_INT_MAX) <=> ($b['ts'] ?? PHP_INT_MAX));

    $name = vb_name($storm['name']);
    $cur = $analysis;
    $pts = [$analysis];
    $hasXY0 = $cur['lat'] !== null;
    $gale = null;
    $stormArea = null;

    vb_vary_state(($storm['id'] ?? '') . '|' . ($analysis['ts'] ?? ''));
    // Tóm tắt địa lý (mọi hướng, kể cả khi không đọc câu "còn cách"): dùng cho lời khuyến cáo và SEO.
    $geo = ['min' => null, 'nowOnLand' => null, 'landfall' => null, 'nowSCS' => false, 'fcSCS' => false];
    $trackPt = function ($p, $k) use (&$geo) {
        if ($p['lat'] === null || $p['cls'] === 'tan') return;
        $scs = vb_in_bien_dong($p['lat'], $p['lon']);
        if ($p['lat'] <= VB_LAND_MAX_LAT && ($d = vb_nearest_mainland($p['lat'], $p['lon']))) {
            $info = ['km' => $d['onLand'] ? 0 : $d['km'], 'prov' => $d['prov'], 'type' => $d['type'] ?? '', 'k' => $k, 'ts' => $p['ts']];
            if ($geo['min'] === null || $info['km'] < $geo['min']['km']) $geo['min'] = $info;
            if ($d['onLand']) {
                $scs = false;
                if ($k === 0) $geo['nowOnLand'] = $info;
                elseif (!$geo['nowOnLand'] && !$geo['landfall']) $geo['landfall'] = $info;
            }
        }
        if ($scs) { if ($k === 0) $geo['nowSCS'] = true; else $geo['fcSCS'] = true; }
    };

    // Tên gọi dùng ở lời chào / lời kết.
    if ($name) {
        $isTD = $cur['cls'] === 'áp thấp nhiệt đới' || $cur['cls'] === 'vùng áp thấp';
        $title = ($cur['cls'] === 'siêu bão' ? 'siêu bão ' : 'cơn bão ') . $name;
        if ($isTD) $title = $cur['cls'] . ' (suy yếu từ bão ' . $name . ')';
    } else {
        $title = $cur['cls'] === 'tan' ? 'cơn bão' : $cur['cls'];
    }

    $greet = vb_pick('greet', [
        'Xin chào các bạn đang xem video. Sau đây là bản tin cập nhật về ' . $title . '.',
        'Xin chào các bạn đang theo dõi video. Mời các bạn cùng điểm lại những thông tin mới nhất về ' . $title . '.',
        'Chào các bạn đang xem video. Bản tin hôm nay sẽ cập nhật diễn biến mới nhất của ' . $title . '.',
        'Xin chào các bạn đang xem video, rất vui được gặp lại các bạn. Ngay sau đây là tình hình mới nhất của ' . $title . '.',
    ]);
    $source = vb_pick('source', [
        VB_SOURCE_LINE,
        'Thông tin dưới đây dựa trên số liệu mới nhất từ Cơ quan Khí tượng Nhật Bản.',
        'Bản tin được xây dựng theo số liệu mới nhất do Cơ quan Khí tượng Nhật Bản cung cấp.',
        'Dữ liệu trong bản tin được cập nhật mới nhất từ Cơ quan Khí tượng Nhật Bản.',
    ]);
    $scenes = [[vb_cue($greet, 'greet', 0), vb_cue($source, 'intro', 0)]];

    if ($cur['cls'] === 'bão' || $cur['cls'] === 'siêu bão') {
        $subject = $cur['cls'] . ($name ? ' ' . $name : '');
    } elseif ($cur['cls'] === 'áp thấp nhiệt đới' || $cur['cls'] === 'vùng áp thấp') {
        $subject = $cur['cls'] . ($name ? ' (suy yếu từ bão ' . $name . ')' : '');
    } else {
        $subject = 'bão' . ($name ? ' ' . $name : '');
    }

    $timeTxt = vb_time_text($cur['ts']);
    $at0 = $cur['coord'] ? ' ở vào khoảng ' . $cur['coord'] . '.' : '.';
    if (!$cur['coord']) {
        $s = ($timeTxt ? 'Vào hồi ' . $timeTxt . ', ' : 'Hiện tại, ') . 'vị trí tâm ' . $subject . ' chưa được xác định cụ thể.';
    } elseif ($timeTxt) {
        $s = vb_pick('pos-now', [
            'Vào hồi ' . $timeTxt . ', vị trí tâm ' . $subject . $at0,
            'Lúc ' . $timeTxt . ', tâm ' . $subject . ' nằm' . $at0,
            'Tính đến ' . $timeTxt . ', tâm ' . $subject . ' được xác định' . $at0,
            'Hồi ' . $timeTxt . ', tâm ' . $subject . ' đang' . $at0,
        ]);
    } else {
        $s = vb_pick('pos-now', ['Hiện tại, vị trí tâm ' . $subject . $at0, 'Hiện nay, tâm ' . $subject . ' đang' . $at0]);
    }
    $sc = [vb_cue(vb_ucfirst($s), $hasXY0 ? 'position' : 'info', 0)];
    if ($hasXY0) {
        $trackPt($cur, 0);
        if ($lc = vb_land_cue($cur, 0)) $sc[] = $lc;
    }



    if ($cur['cls'] === 'tan') {
        $sc[] = vb_cue(vb_pick('tan-now', ['Bão đã suy yếu và đang tan dần.', 'Hiện bão đã suy yếu và đang dần tan.', 'Cơn bão đã suy yếu, hoàn lưu đang tan dần.']), $hasXY0 ? 'dissipate' : 'info', 0);
    } else {
        $w = vb_wind_sentence($cur['cls'], $cur['lv'], $cur['gust']);
        if ($w) $sc[] = vb_cue($w, 'wind', 0);
        $ps = vb_pressure_sentence($cur['cls'], $cur['pres'], true);
        if ($ps) $sc[] = vb_cue($ps, ($hasXY0 && $cur['pres'] !== null) ? 'pressure' : 'info', 0);
        // Bán kính gió chỉ lấy ở thời điểm hiện tại (giống ty.php) và chỉ khi spec_json còn dữ liệu.
        $noun = vb_center_noun($cur['cls']);
        $trackR = vb_track_radii($storm['track'] ?? null);
        $gale = vb_merge_radius(vb_wind_area($analysis['item']['galeWarning'] ?? null), $trackR['gale']);
        $stormArea = vb_merge_radius(vb_wind_area($analysis['item']['stormWarning'] ?? null), $trackR['storm']);
        $r7 = vb_radius_sentence($gale, 7, $noun);
        $r10 = vb_radius_sentence($stormArea, 10, $noun);
        if ($r7) $sc[] = vb_cue($r7, $hasXY0 ? 'radius7' : 'info', 0);
        if ($r10) $sc[] = vb_cue($r10, $hasXY0 ? 'radius10' : 'info', 0);
    }
    $scenes[] = $sc;

    // Mốc đáng chú ý của bản tin hiện tại: chỉ truy vấn CSDL khi mốc thật sự xảy ra.
    $milestones = vb_milestones($storm, $cur);
    $rank = null; $hist = null; $seq = null;
    if ($milestones['firstPeak'] || $milestones['firstStorm']) {
        $curKt = vb_cur_kt($analysis['item']);
        $digits = preg_replace('/\D/', '', (string)($storm['no'] ?? ''));
        $year = strlen($digits) === 4 ? (int)('20' . substr($digits, 0, 2)) : (int)vb_vn_date($cur['ts'] ?? time())->format('Y');
        if ($milestones['firstPeak']) $rank = vb_year_ranking($conn, $storm['no'] ?? '', $curKt, $name);
        if ($milestones['firstStorm'] && $name) $hist = vb_name_history($conn, $storm['name'], $year, $curKt);
        if ($milestones['firstStorm']) $seq = vb_season_seq($storm['no'] ?? '');
        foreach (vb_milestone_scenes($milestones, $cur, $name, $rank, $hist, $seq) as $msScene) $scenes[] = $msScene;
    }

    if (!$forecasts) {
        if ($cur['move'] !== '' && $cur['cls'] !== 'tan') {
            $scenes[] = [vb_cue(vb_pick('now-move', ['Hiện tại, ', 'Lúc này, ']) . vb_subject_var($cur['cls'], $name) . ' đang ' . $cur['move'] . '.', 'info', 0)];
        }
    }

    $prev = $analysis;
    $lastPos = $hasXY0 ? 0 : null;
    foreach ($forecasts as $i => $fc) {
        if ($prev['cls'] === 'tan') break;
        $k = count($pts);
        $pts[] = $fc;
        $hasXY = $fc['lat'] !== null;

        if ($i === 0) {
            $h = ($fc['ts'] && $analysis['ts']) ? (int)round(($fc['ts'] - $analysis['ts']) / 3600) : null;
            $lead = $h
                ? vb_pick('lead1', ['Dự báo trong vòng ' . $h . ' giờ tới', 'Theo dự báo, trong ' . $h . ' giờ tới', 'Trong khoảng ' . $h . ' giờ tới'])
                : vb_pick('lead1', ['Dự báo trong thời gian tới', 'Theo dự báo, trong thời gian tới']);
        } else {
            $h = ($fc['ts'] && $prev['ts']) ? (int)round(($fc['ts'] - $prev['ts']) / 3600) : null;
            $lead = $h
                ? vb_pick('lead', ['Trong ' . $h . ' giờ tiếp theo', 'Tiếp đó, trong ' . $h . ' giờ kế tiếp', 'Trong ' . $h . ' giờ sau đó', 'Sang ' . $h . ' giờ tiếp theo'])
                : vb_pick('lead', ['Trong thời gian tiếp theo', 'Sau đó']);
        }

        $sc = [];
        $moved = false;
        $canMove = $hasXY && $lastPos !== null;
        // Suy yếu thành áp thấp: nói ở câu riêng kèm thời điểm, không lặp trong câu di chuyển.
        $weaken = vb_is_weaken($prev, $fc);
        $trend = $weaken ? '' : vb_trend($prev['lv'], $fc['lv']);
        $speedChange = $fc['cls'] !== 'tan' && !empty($prev['mp']) && !empty($fc['mp']) && !$fc['mp']['stationary'] && vb_speed_changed($prev['mp'], $fc['mp']);
        $moveTxt = $speedChange ? vb_move($fc['item'], false) : $fc['move'];
        $clauses = array_values(array_filter([$moveTxt, $trend], fn($x) => $x !== ''));
        if ($clauses) {
            $sc[] = vb_cue($lead . ', ' . vb_subject_var($prev['cls'], $name) . ' ' . implode(' và ', $clauses) . '.',
                $canMove ? 'move' : 'info', $k,
                $canMove ? ['from' => $lastPos, 'tag' => vb_move_tag($fc)] : []);
            $moved = $canMove;
            if ($fc['cls'] !== 'tan') foreach (vb_change_cues($prev, $fc, $k, $name, $hasXY) as $chc) $sc[] = $chc;
        }
        // Không có câu di chuyển thì tịnh tiến ngay khi đọc vị trí.
        $from = (!$moved && $canMove) ? ['from' => $lastPos] : [];

        $t = vb_time_text($fc['ts']);
        $at = $t ? vb_pick('at', ['Đến ', 'Tới ', 'Đến khoảng ']) . $t . ', ' : '';
        $then = vb_pick('at-then', ['Lúc đó, ', 'Khi ấy, ']);
        // Có câu di chuyển (bão đã tịnh tiến tới mốc) thì báo suy yếu trước, kèm thời điểm; câu vị trí dùng "Lúc đó".
        $weakFirst = $weaken && $clauses;
        $atPos = $at;
        if ($weakFirst) {
            $sc[] = vb_weaken_cue($prev, $fc, $k, $name, $at, $hasXY);
            if ($at !== '') $atPos = $then;
        }
        if ($hasXY) $trackPt($fc, $k);
        if ($fc['cls'] === 'tan') {
            $loc = $fc['coord'] ? ' ở khu vực khoảng ' . $fc['coord'] : '';
            $sc[] = vb_cue(vb_ucfirst($at . vb_pick('tan', [
                $prev['cls'] . ' suy yếu và tan dần' . $loc . '.',
                $prev['cls'] . ' được dự báo suy yếu rồi tan dần' . $loc . '.',
                $prev['cls'] . ' sẽ suy yếu và dần tan' . $loc . '.',
            ])), $hasXY ? 'dissipate' : 'info', $k, $from);
        } else {
            if ($fc['coord']) {
                $n = vb_center_noun($fc['cls']);
                $sc[] = vb_cue(vb_ucfirst($atPos . vb_pick('pos-fc', [
                    'vị trí tâm ' . $n . ' ở vào khoảng ' . $fc['coord'] . '.',
                    'tâm ' . $n . ' ở vào khoảng ' . $fc['coord'] . '.',
                    'tâm ' . $n . ' được dự báo ở vào khoảng ' . $fc['coord'] . '.',
                    'tâm ' . $n . ' sẽ nằm ở vào khoảng ' . $fc['coord'] . '.',
                ])), 'position', $k, $from);
                if ($hasXY && ($lc = vb_land_cue($fc, $k, $atPos !== $at))) $sc[] = $lc;
            }
            if ($weaken && !$weakFirst) $sc[] = vb_weaken_cue($prev, $fc, $k, $name, $fc['coord'] && $at !== '' ? $then : $at, $hasXY);
            $w = vb_wind_sentence($fc['cls'], $fc['lv'], $fc['gust']);
            if ($w) $sc[] = vb_cue($w, 'wind', $k);
            $ps = vb_pressure_sentence($fc['cls'], $fc['pres'], false);
            if ($ps) $sc[] = vb_cue($ps, ($hasXY && $fc['pres'] !== null) ? 'pressure' : 'info', $k);
        }
        if ($sc) $scenes[] = $sc;
        if ($hasXY) $lastPos = $k;
        $prev = $fc;
    }

    // Lời kết: nhắc nhở phù hợp khoảng cách thực tế, rồi cảm ơn và tạm biệt.
    $end = [];
    $near = $geo['min'];
    if ($near !== null && $near['km'] <= 500) {
        $where = vb_prov_phrase($near['prov'], $near['type']);
        if ($geo['nowOnLand'] || $geo['landfall']) {
            $end[] = vb_cue(vb_pick('advice-land', [
                'Người dân tại ' . $where . ' và các địa phương lân cận cần đề phòng gió mạnh, mưa lớn, ngập lụt và sạt lở đất, đồng thời thường xuyên theo dõi các bản tin tiếp theo.',
                'Các bạn ở ' . $where . ' và khu vực lân cận cần chủ động gia cố nhà cửa, đề phòng mưa lớn, lũ quét và sạt lở đất trong những ngày tới.',
            ]), 'outro', 0);
        } else {
            $end[] = vb_cue(vb_pick('advice-near', [
                'Người dân tại ' . $where . ' và các tỉnh ven biển lân cận cần thường xuyên theo dõi các bản tin tiếp theo để chủ động phòng tránh.',
                'Các bạn ở ' . $where . ', khu vực ven biển và tàu thuyền trên biển nên cập nhật thông tin thường xuyên để chủ động ứng phó.',
            ]), 'outro', 0);
        }
    } else {
        $end[] = vb_cue(vb_pick('advice-far', [
            'Chúng tôi sẽ tiếp tục theo dõi và cập nhật diễn biến của ' . $title . ' trong các bản tin sau.',
            'Diễn biến của ' . $title . ' sẽ tiếp tục được cập nhật trong những bản tin tiếp theo.',
        ]), 'outro', 0);
    }
    $end[] = vb_cue(vb_pick('bye', [
        'Cảm ơn các bạn đã dành thời gian theo dõi video. Xin chào tạm biệt và hẹn gặp lại các bạn trong những bản tin sau.',
        'Bản tin đến đây là kết thúc. Cảm ơn các bạn đã theo dõi, chào tạm biệt và hẹn gặp lại!',
        'Xin cảm ơn các bạn đã đồng hành cùng bản tin hôm nay. Tạm biệt và hẹn gặp lại các bạn ở những bản tin tiếp theo.',
        'Trên đây là toàn bộ bản tin. Cảm ơn các bạn đã xem video, xin chào tạm biệt và hẹn gặp lại.',
    ]), 'outro', 0);
    $scenes[] = $end;

    $paragraphs = array_map(fn($scene) => implode(' ', array_column($scene, 'text')), $scenes);
    return [
        'scenes'     => $scenes,
        'paragraphs' => $paragraphs,
        'points'     => $pts,
        'gale'       => $gale,
        'storm'      => $stormArea,
        'milestones' => $milestones,
        'rank'       => $rank,
        'history'    => $hist,
        'seq'        => $seq,
        'geo'        => $geo,
    ];
}

function vb_point_js($p) {
    return [
        'lat'   => $p['lat'],
        'lon'   => $p['lon'],
        'lv'    => $p['lv'],
        'cls'   => $p['cls'],
        'gust'  => vb_gust_text($p['gust']),
        'kmh'   => $p['sus'] ? (int)round($p['sus'] * 3.6) : null,
        'time'  => vb_time_short($p['ts']),
        'coord' => $p['lat'] !== null ? vb_num($p['lat']) . '°' . ($p['lat'] >= 0 ? 'N' : 'S') . ' · ' . vb_num($p['lon']) . '°' . ($p['lon'] >= 0 ? 'E' : 'W') : null,
    ];
}

// ----------------------------------------------------------------------------- Lấy danh sách bão
$storms = [];
$dbError = null;
if (!isset($conn) || $conn->connect_error) {
    $dbError = 'Không kết nối được cơ sở dữ liệu (kiểm tra ../config.php).';
} else {
    $res = $conn->query('SELECT * FROM jma_typhoons');
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $spec = json_decode((string)($row['spec_json'] ?? ''), true);
            if (!is_array($spec) || !$spec) continue;
            $storms[(string)$row['tc_id']] = [
                'id'      => (string)$row['tc_id'],
                'name'    => (string)($row['name_en'] ?? ''),
                'no'      => (string)($row['typhoon_number'] ?? ''),
                'spec'    => $spec,
                'track'   => $row['track_json'] ?? null,
                'updated' => $row['updated_at'] ?? null,
            ];
        }
    } else {
        $dbError = 'Lỗi truy vấn bảng jma_typhoons.';
    }
}

$selectedId = isset($_GET['storm']) ? (string)$_GET['storm'] : '';
if ($selectedId === '' && count($storms) === 1) $selectedId = array_key_first($storms);
$selected = $storms[$selectedId] ?? null;
$bulletin = $selected ? vb_build_bulletin($selected, (isset($conn) && !$conn->connect_error) ? $conn : null) : null;

$player = null;
if ($bulletin) {
    $cues = [];
    foreach ($bulletin['scenes'] as $si => $scene) {
        foreach ($scene as $cue) $cues[] = $cue + ['scene' => $si];
    }
    $player = [
        'name'   => vb_name($selected['name']),
        'no'     => $selected['no'],
        'points' => array_map('vb_point_js', $bulletin['points']),
        'past'   => vb_past_track($selected['track']),
        'gale'   => $bulletin['gale'],
        'storm'  => $bulletin['storm'],
        'rank'   => $bulletin['rank'],
        'history'=> $bulletin['history'],
        'seq'    => $bulletin['seq'],
        'cues'   => $cues,
    ];
}


// =============================================================================
//  DỰNG VIDEO TRÊN MÁY CHỦ (PHP GD + ffmpeg) - không cần trình duyệt, không cần chia sẻ màn hình
//  -----------------------------------------------------------------------------
//  Máy chủ tự dựng lại bản đồ (tile vệ tinh/nền + ranh giới tỉnh + đường đi + vùng gió + nhãn + phụ đề)
//  từng khung hình bằng PHP GD, đẩy qua ống (pipe) vào ffmpeg cùng giọng đọc Vbee đã cache (tts_cache/*.mp3)
//  để ra MP4 (H.264 + AAC). Trình duyệt chỉ gửi lệnh và hỏi tiến độ -> đóng tab giữa chừng vẫn dựng tiếp.
//
//    POST ff=start   -> tạo job, trả {job} rồi dựng n���n (ngắt kết nối với trình duyệt)
//    GET  ff=status  -> tiến độ job
//    GET  ff=latest  -> job gần nhất của cơn bão đang xem
//    GET  ff=get     -> tải out.mp4 / thumb.jpg
//    POST ff=cancel / ff=cleanup
//  ffmpeg: ../ff/ffmpeg (thư mục ff ở cùng cấp thư mục cha của video.php), hoặc ff/ffmpeg cạnh video.php.
//  Font TTF (có tiếng Việt): đặt vào ../ff/ hoặc ./fonts/ (vd BeVietnamPro-Regular.ttf + BeVietnamPro-Bold.ttf,
//  hoặc NotoSans / Roboto / DejaVuSans...). Hoặc define('VIDEO_FONT', '/duong/dan/font.ttf') trong config.php.
//  Bản đồ nền được tải qua mạng và lưu cache ở tile_cache/ (máy chủ cần ra được internet).
// =============================================================================
const VBF_DW = 1280;   // kích thước "thiết kế" của sân khấu (tương ứng khung bản đồ trong trình phát)
const VBF_DH = 720;
const VBF_BASES = ['sat', 'dark', 'light'];

function vbf_dir() {
    $d = __DIR__ . '/ff_jobs';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    if (is_dir($d) && !is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "Require all denied\n");
    return $d;
}

function vbf_ffmpeg() {
    if (getenv('GITHUB_ACTIONS') === 'true' && function_exists('shell_exec')) {
        $sys = trim((string)@shell_exec('command -v ffmpeg 2>/dev/null'));
        if ($sys !== '' && is_executable($sys)) return $sys;
    }
    $cands = [vb_tts_conf('FFMPEG_PATH'), dirname(__DIR__) . '/ff/ffmpeg', __DIR__ . '/ff/ffmpeg', dirname(__DIR__) . '/ff/ffmpeg.exe', __DIR__ . '/ff/ffmpeg.exe'];
    foreach ($cands as $p) {
        if ($p === '' || !is_file($p)) continue;
        if (!is_executable($p)) @chmod($p, 0755);
        if (is_executable($p)) return $p;
    }
    if (function_exists('shell_exec')) {
        $w = trim((string)@shell_exec('command -v ffmpeg 2>/dev/null'));
        if ($w !== '' && is_executable($w)) return $w;
    }
    return null;
}

function vbf_fonts() {
    static $f = null;
    if ($f !== null) return $f;
    $reg = vb_tts_conf('VIDEO_FONT');
    $bold = vb_tts_conf('VIDEO_FONT_BOLD');
    if ($reg !== '' && is_file($reg)) {
        $f = [$reg, ($bold !== '' && is_file($bold)) ? $bold : $reg];
        return $f;
    }
    $dirs = [dirname(__DIR__) . '/ff', __DIR__ . '/ff', __DIR__ . '/fonts', dirname(__DIR__) . '/fonts', __DIR__, dirname(__DIR__),
        '/usr/share/fonts/truetype/dejavu', '/usr/share/fonts/truetype/noto', '/usr/share/fonts/noto', '/usr/share/fonts/truetype/liberation',
        '/usr/share/fonts/truetype/liberation2', '/usr/share/fonts/dejavu', '/usr/share/fonts/TTF', '/usr/share/fonts/truetype/freefont',
        '/usr/share/fonts/opentype/noto', 'C:/Windows/Fonts'];
    $pairs = [
        ['BeVietnamPro-Regular.ttf', 'BeVietnamPro-Bold.ttf'], ['NotoSans-Regular.ttf', 'NotoSans-Bold.ttf'], ['Roboto-Regular.ttf', 'Roboto-Bold.ttf'],
        ['OpenSans-Regular.ttf', 'OpenSans-Bold.ttf'], ['DejaVuSans.ttf', 'DejaVuSans-Bold.ttf'], ['LiberationSans-Regular.ttf', 'LiberationSans-Bold.ttf'],
        ['arial.ttf', 'arialbd.ttf'], ['segoeui.ttf', 'segoeuib.ttf'],
    ];
    foreach ($pairs as [$r, $b]) {
        foreach ($dirs as $d) {
            if (is_file("$d/$r")) { $f = ["$d/$r", is_file("$d/$b") ? "$d/$b" : "$d/$r"]; return $f; }
        }
    }
    foreach ([dirname(__DIR__) . '/ff', __DIR__ . '/ff', __DIR__ . '/fonts'] as $d) {
        $g = glob($d . '/*.ttf');
        if ($g) { $f = [$g[0], $g[0]]; return $f; }
    }
    $f = [null, null];
    return $f;
}

function vbf_env() {
    $ff = vbf_ffmpeg();
    $fonts = vbf_fonts();
    $err = [];
    if (!$ff) $err[] = 'Không tìm thấy ffmpeg (cần ../ff/ffmpeg hoặc ff/ffmpeg và quyền thực thi).';
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagettftext')) $err[] = 'PHP chưa bật GD + FreeType (imagettftext).';
    if (!function_exists('curl_init')) $err[] = 'PHP chưa bật cURL (cần để tải tile bản đồ).';
    if (!function_exists('proc_open')) $err[] = 'proc_open bị tắt (disable_functions) nên không chạy được ffmpeg.';
    if (!$fonts[0]) $err[] = 'Không tìm thấy font TTF hỗ trợ tiếng Việt. Hãy đặt file .ttf (vd NotoSans-Regular.ttf, NotoSans-Bold.ttf) vào ../ff/ hoặc ./fonts/.';
    $dir = vbf_dir();
    if (!is_dir($dir) || !is_writable($dir)) $err[] = 'Thư mục ff_jobs/ không ghi được.';
    return ['ok' => !$err, 'errors' => $err, 'ffmpeg' => $ff, 'font' => $fonts[0]];
}

// ----------------------------------------------------------------------------- Toạ độ & máy quay
function vbf_norm($lat, $lon) {
    $lat = max(-85.0511, min(85.0511, $lat));
    $s = sin(deg2rad($lat));
    return [($lon + 180) / 360, 0.5 - log((1 + $s) / (1 - $s)) / (4 * M_PI)];
}

function vbf_dest($lat, $lon, $km, $brg) {
    $R = 6371; $d = $km / $R; $b = deg2rad($brg);
    $la1 = deg2rad($lat); $lo1 = deg2rad($lon);
    $la2 = asin(sin($la1) * cos($d) + cos($la1) * sin($d) * cos($b));
    $lo2 = $lo1 + atan2(sin($b) * sin($d) * cos($la1), cos($d) - sin($la1) * sin($la2));
    return [rad2deg($la2), rad2deg($lo2)];
}

function vbf_ease($k) { return $k < .5 ? 2 * $k * $k : 1 - pow(-2 * $k + 2, 2) / 2; }
function vbf_prog($t, $t0, $dur) { if ($dur <= 0) return $t >= $t0 ? 1.0 : 0.0; return max(0.0, min(1.0, ($t - $t0) / $dur)); }
function vbf_hasxy($p) { return is_array($p) && $p['lat'] !== null && $p['lon'] !== null; }

// Giống map.getBoundsZoom của Leaflet (zoomSnap 0.25, làm tròn xuống).
function vbf_zoom_for($swlat, $swlon, $nelat, $nelon, $padX, $padY) {
    [$x1, $y1] = vbf_norm($nelat, $swlon);
    [$x2, $y2] = vbf_norm($swlat, $nelon);
    $dx = max(1e-9, abs($x2 - $x1)); $dy = max(1e-9, abs($y2 - $y1));
    $aw = max(10, VBF_DW - 2 * $padX); $ah = max(10, VBF_DH - 2 * $padY);
    $z = min(log($aw / (256 * $dx), 2), log($ah / (256 * $dy), 2));
    return floor($z / 0.25) * 0.25;
}

function vbf_cam_fit($swlat, $swlon, $nelat, $nelon, $padX, $padY, $maxZ) {
    $z = min($maxZ, vbf_zoom_for($swlat, $swlon, $nelat, $nelon, $padX, $padY));
    [$x1, $y1] = vbf_norm($nelat, $swlon);
    [$x2, $y2] = vbf_norm($swlat, $nelon);
    return [($x1 + $x2) / 2, ($y1 + $y2) / 2, $z];
}

function vbf_tobounds($lat, $lon, $sizeM) {
    $la = 180 * $sizeM / 40075017;
    $lo = $la / max(0.05, cos(deg2rad($lat)));
    return [$lat - $la, $lon - $lo, $lat + $la, $lon + $lo];
}

function vbf_pad_bounds(array $b, $r) {
    $h = abs($b[2] - $b[0]) * $r; $w = abs($b[3] - $b[1]) * $r;
    return [$b[0] - $h, $b[1] - $w, $b[2] + $h, $b[3] + $w];
}

function vbf_cam_close($p, $galeMaxKm, $tighter) {
    $km = max($galeMaxKm, 160) * ($tighter ? 1.15 : 1.7);
    $b = vbf_tobounds($p['lat'], $p['lon'], $km * 2000);
    $z = vbf_zoom_for($b[0], $b[1], $b[2], $b[3], 0, 0);
    $z = max(5.5, min($tighter ? 8.25 : 7.75, $z));
    [$cx, $cy] = vbf_norm($p['lat'], $p['lon']);
    return [$cx, $cy, $z];
}

function vbf_cam_travel($a, $b) {
    $bb = vbf_pad_bounds([min($a['lat'], $b['lat']), min($a['lon'], $b['lon']), max($a['lat'], $b['lat']), max($a['lon'], $b['lon'])], 0.45);
    return vbf_cam_fit($bb[0], $bb[1], $bb[2], $bb[3], 60, 60, 6.5);
}

function vbf_cam_bounds(array $b, $maxZ) { return vbf_cam_fit($b[0], $b[1], $b[2], $b[3], 0, 0, $maxZ); }

function vbf_cam_lerp(array $c0, array $c1, $e) {
    $n0 = 256 * pow(2, $c0[2]);
    $dist = hypot(($c1[0] - $c0[0]) * $n0, ($c1[1] - $c0[1]) * $n0);
    $dip = $dist > 1 ? min(1.2, 0.5 * log(1 + $dist / VBF_DW, 2)) : 0;
    return [
        $c0[0] + ($c1[0] - $c0[0]) * $e,
        $c0[1] + ($c1[1] - $c0[1]) * $e,
        $c0[2] + ($c1[2] - $c0[2]) * $e - $dip * sin(M_PI * $e),
    ];
}

// Camera tại thời điểm $t của một cue: [cam, đang_chuyển_động]
function vbf_cam_at(array $cam0, array $segs, $t) {
    $cur = $cam0; $moving = false;
    foreach ($segs as $sg) {
        if ($t < $sg['t']) break;
        $p = vbf_prog($t, $sg['t'], $sg['dur']);
        if ($p < 1) { $cur = vbf_cam_lerp($cur, $sg['cam'], vbf_ease($p)); $moving = true; break; }
        $cur = $sg['cam'];
    }
    return [$cur, $moving];
}

function vbf_cam_end(array $cam0, array $segs) {
    return $segs ? $segs[count($segs) - 1]['cam'] : $cam0;
}

function vbf_area_circle($p, $area, $scale) {
    if (!empty($area['uniform'])) return ['lat' => $p['lat'], 'lon' => $p['lon'], 'r' => $area['km'] * 1000 * $scale];
    $off = ($area['longKm'] - $area['shortKm']) / 2 * $scale;
    $rad = ($area['longKm'] + $area['shortKm']) / 2 * $scale;
    [$la, $lo] = vbf_dest($p['lat'], $p['lon'], $off, $area['longBearing']);
    return ['lat' => $la, 'lon' => $lo, 'r' => $rad * 1000];
}

function vbf_lv_color($lv) {
    static $C = ['#FFFFFF', '#AEF1F9', '#96F7DC', '#96F7B4', '#6FF46F', '#73ED12', '#A4ED12', '#DAED12', '#EDC212', '#ED8F12', '#ED6312', '#ED2912', '#D5102D', '#AA1746', '#781F5F', '#4D2778', '#222F91', '#0D3688'];
    if ($lv === null) return '#94a3b8';
    return $C[max(0, min(17, (int)$lv))];
}

// ----------------------------------------------------------------------------- Kế hoạch thời gian từng cue
// Ngữ cảnh $P: pts, past, gale, storm, galeMax, wide (camera toàn cảnh)
function vbf_move_plan(array $P, $from, $to, $dur) {
    $a = $P['pts'][$from]; $b = $P['pts'][$to];
    if (!vbf_hasxy($a) || !vbf_hasxy($b)) return null;
    $camSec = min(1.1, max(0.7, $dur * 0.3));
    $movDur = max(0.8, $dur - $camSec);
    return [
        'from' => $from, 'to' => $to, 't0' => $camSec, 'dur' => $movDur, 'end' => $camSec + $movDur,
        'seg' => ['t' => 0.0, 'dur' => $camSec, 'cam' => vbf_cam_travel($a, $b)],
    ];
}

function vbf_plan(array $c, $d, array $P) {
    $act = $c['act'] ?? 'info';
    $pts = $P['pts'];
    $p = isset($c['pt']) ? ($pts[$c['pt']] ?? null) : null;
    $plan = ['segs' => [], 'total' => 0.0, 'move' => null, 't_after' => 0.0, 'anim' => null];
    switch ($act) {
        case 'intro':
            $plan['segs'][] = ['t' => 0.0, 'dur' => 1.2, 'cam' => $P['wide']];
            $animDur = min($d * 0.85, 5.0);
            $plan['anim'] = ['t0' => 1.2, 'dur' => $animDur];
            $plan['total'] = 1.2 + $animDur;
            $plan['t_after'] = $plan['total'];
            break;
        case 'position':
        case 'dissipate':
            if (!vbf_hasxy($p)) break;
            $t = 0.0;
            if (isset($c['from'])) {
                $mv = vbf_move_plan($P, $c['from'], $c['pt'], $act === 'position' ? min($d * 0.45, 3.5) : min($d * 0.5, 3.5));
                if ($mv) { $plan['move'] = $mv; $plan['segs'][] = $mv['seg']; $t = $mv['end']; }
            }
            $plan['t_after'] = $t;
            $plan['segs'][] = ['t' => $t, 'dur' => 1.6, 'cam' => vbf_cam_close($p, $P['galeMax'], false)];
            $plan['total'] = $t + 1.6;
            break;
        case 'wind':
            if (vbf_hasxy($p) && $p['lv'] !== null) {
                $plan['segs'][] = ['t' => 0.0, 'dur' => 1.2, 'cam' => vbf_cam_close($p, $P['galeMax'], true)];
                $plan['total'] = 1.6;
            }
            break;
        case 'shift':
            if (vbf_hasxy($p)) {
                $plan['segs'][] = ['t' => 0.0, 'dur' => 1.2, 'cam' => vbf_cam_close($p, $P['galeMax'], true)];
                $plan['total'] = 1.8;
            }
            break;
        case 'pressure':
            if (vbf_hasxy($p) && ($p['pres'] ?? null) !== null) {
                $plan['segs'][] = ['t' => 0.0, 'dur' => 1.2, 'cam' => vbf_cam_close($p, $P['galeMax'], true)];
                $plan['total'] = 1.8;
            }
            break;
        case 'radius7':
        case 'radius10':
            $level = $act === 'radius7' ? 7 : 10;
            $area = $level === 7 ? $P['gale'] : $P['storm'];
            $p0 = $pts[0] ?? null;
            if (!vbf_hasxy($p0) || !$area) break;
            $full = vbf_area_circle($p0, $area, 1);
            $b = vbf_pad_bounds(vbf_tobounds($full['lat'], $full['lon'], $full['r'] * 2), 0.25);
            $plan['segs'][] = ['t' => 0.0, 'dur' => 1.1, 'cam' => vbf_cam_bounds($b, $level === 10 ? 8 : 7.5)];
            $plan['anim'] = ['t0' => 1.1, 'dur' => 1.5];
            $plan['total'] = 2.6;
            $plan['t_after'] = 2.6;
            break;
        case 'move':
            $mv = (isset($c['from']) && isset($c['pt'])) ? vbf_move_plan($P, $c['from'], $c['pt'], max(1.5, $d * 0.85)) : null;
            if ($mv) { $plan['move'] = $mv; $plan['segs'][] = $mv['seg']; $plan['total'] = $mv['end']; }
            break;
        case 'land':
            $L = $c['land'] ?? null;
            if (!vbf_hasxy($p) || !$L) break;
            if (!empty($L['onLand'])) {
                $plan['segs'][] = ['t' => 0.0, 'dur' => 1.2, 'cam' => vbf_cam_close($p, $P['galeMax'], true)];
                $plan['t_after'] = 1.2; $plan['total'] = 1.2;
            } else {
                $b = vbf_pad_bounds([min($p['lat'], $L['lat']), min($p['lon'], $L['lon']), max($p['lat'], $L['lat']), max($p['lon'], $L['lon'])], $L['km'] < 150 ? 0.9 : 0.3);
                $cd = $L['km'] > 1500 ? 1.8 : 1.3;
                $plan['segs'][] = ['t' => 0.0, 'dur' => $cd, 'cam' => vbf_cam_bounds($b, $L['km'] < 150 ? 8 : 7.5)];
                $ad = min(1.4, max(0.7, $d * 0.3));
                $plan['anim'] = ['t0' => $cd, 'dur' => $ad];
                $plan['t_after'] = $cd; $plan['total'] = $cd + $ad;
            }
            break;
        case 'history':
        case 'rank':
            $plan['segs'][] = ['t' => 0.0, 'dur' => 1.4, 'cam' => $P['wide']];
            $plan['total'] = 1.4;
            break;
        case 'outro':
            $plan['segs'][] = ['t' => 0.0, 'dur' => 2.0, 'cam' => $P['wide']];
            $plan['total'] = 2.0;
            break;
    }
    return $plan;
}

function vbf_initial_state(array $P) {
    return [
        'cam' => $P['wide'], 'past' => 0.0, 'fcPath' => [], 'dots' => [], 'storm' => null,
        'radius' => [], 'info' => null, 'panel' => null,
    ];
}

function vbf_ens(&$S, $p) {
    $lv = ($S['storm']['lv'] ?? null);
    if ($p['lv'] !== null) $lv = $p['lv'];
    $S['storm'] = ['lat' => $p['lat'], 'lon' => $p['lon'], 'lv' => $lv, 'fade' => false];
}

// Áp trạng thái bền vững sau khi cue kết thúc (giống chạy "tức thì" khi tua).
function vbf_finish(array &$S, array $c, array $plan, array $P) {
    $act = $c['act'] ?? 'info';
    $pts = $P['pts'];
    $p = isset($c['pt']) ? ($pts[$c['pt']] ?? null) : null;
    $S['cam'] = vbf_cam_end($S['cam'], $plan['segs']);
    if (!in_array($act, ['history', 'rank'], true)) $S['panel'] = null;
    $applyMove = function ($mv) use (&$S, $pts) {
        $a = $pts[$mv['from']]; $b = $pts[$mv['to']];
        vbf_ens($S, $a);
        if (!$S['fcPath']) $S['fcPath'] = [[$a['lat'], $a['lon']]];
        $S['fcPath'][] = [$b['lat'], $b['lon']];
        vbf_ens($S, $b);
    };
    switch ($act) {
        case 'intro':
            $S['past'] = 1.0;
            if (vbf_hasxy($pts[0] ?? null)) { if (!in_array(0, $S['dots'], true)) $S['dots'][] = 0; vbf_ens($S, $pts[0]); $S['info'] = [0, false]; }
            break;
        case 'position':
        case 'dissipate':
            if (!vbf_hasxy($p)) break;
            if ($plan['move']) $applyMove($plan['move']);
            if (!in_array($c['pt'], $S['dots'], true)) $S['dots'][] = $c['pt'];
            vbf_ens($S, $p);
            if ($act === 'dissipate') $S['storm']['fade'] = true;
            $S['info'] = [$c['pt'], false];
            break;
        case 'wind':
        case 'pressure':
        case 'shift':
            if ($p) $S['info'] = [$c['pt'], true];
            break;
        case 'radius7':
        case 'radius10':
            if ($plan['anim']) $S['radius'][$act === 'radius7' ? 7 : 10] = true;
            break;
        case 'move':
            if ($plan['move']) $applyMove($plan['move']);
            break;
        case 'history':
        case 'rank':
            $S['panel'] = $c;
            break;
    }
}

// Ghép trạng thái bền vững + hiệu ứng tạm thời của cue tại thời điểm $t (giây từ lúc cue bắt đầu).
function vbf_assemble(array $S, array $c, array $plan, $t, array $P) {
    $act = $c['act'] ?? 'info';
    $pts = $P['pts'];
    $p = isset($c['pt']) ? ($pts[$c['pt']] ?? null) : null;
    [$cam, $moving] = vbf_cam_at($S['cam'], $plan['segs'], $t);
    $st = [
        'cam' => $cam, 'moving' => $moving, 'past' => $S['past'], 'fcPath' => $S['fcPath'], 'fcHead' => null,
        'dots' => $S['dots'], 'storm' => $S['storm'], 'radius' => [], 'info' => $S['info'], 'panel' => $S['panel'],
        'fx' => [], 'tag' => null, 'blink' => false, 'subtitle' => $c['text'] ?? '',
    ];
    foreach ($S['radius'] as $lv => $_) $st['radius'][$lv] = ['k' => 1.0, 'labels' => true];
    if (!in_array($act, ['history', 'rank'], true)) $st['panel'] = null;
    else $st['panel'] = $c;

    $doMove = function ($mv) use (&$st, $pts, $t, $c) {
        $a = $pts[$mv['from']]; $b = $pts[$mv['to']];
        vbf_ens_st($st, $a);
        if (!$st['fcPath']) $st['fcPath'] = [[$a['lat'], $a['lon']]];
        $k = vbf_ease(vbf_prog($t, $mv['t0'], $mv['dur']));
        $cur = [$a['lat'] + ($b['lat'] - $a['lat']) * $k, $a['lon'] + ($b['lon'] - $a['lon']) * $k];
        if ($t >= $mv['end']) {
            $st['fcPath'][] = [$b['lat'], $b['lon']];
            vbf_ens_st($st, $b);
        } else {
            $st['storm']['lat'] = $cur[0]; $st['storm']['lon'] = $cur[1];
            $st['fcHead'] = $cur;
        }
        if (($c['act'] ?? '') === 'move' && !empty($c['tag'])) {
            $pos = $t >= $mv['end'] ? [$b['lat'], $b['lon']] : $cur;
            $st['tag'] = ['lat' => $pos[0], 'lon' => $pos[1], 'text' => $c['tag']];
        }
        return $t >= $mv['end'];
    };

    switch ($act) {
        case 'intro':
            if ($plan['anim']) {
                $st['past'] = vbf_ease(vbf_prog($t, $plan['anim']['t0'], $plan['anim']['dur']));
                if ($t >= $plan['total']) {
                    $st['past'] = 1.0;
                    if (vbf_hasxy($pts[0] ?? null)) { if (!in_array(0, $st['dots'], true)) $st['dots'][] = 0; vbf_ens_st($st, $pts[0]); $st['info'] = [0, false]; }
                }
            }
            break;
        case 'position':
        case 'dissipate':
            if (!vbf_hasxy($p)) break;
            $after = true;
            if ($plan['move']) $after = $doMove($plan['move']);
            if ($after) {
                if (!in_array($c['pt'], $st['dots'], true)) $st['dots'][] = $c['pt'];
                vbf_ens_st($st, $p);
                $st['info'] = [$c['pt'], false];
                $ta = $plan['t_after'];
                if ($act === 'position') {
                    $st['blink'] = true;
                    $st['fx']['pulse'] = ['lat' => $p['lat'], 'lon' => $p['lon'], 't' => $t - $ta];
                } else {
                    $st['storm']['fade'] = true;
                    $st['fx']['done'] = ['lat' => $p['lat'], 'lon' => $p['lon'], 'text' => 'Tan dần'];
                }
            }
            break;
        case 'wind':
            if ($p) {
                $st['info'] = [$c['pt'], true];
                if (vbf_hasxy($p) && $p['lv'] !== null) $st['fx']['wind'] = ['pt' => $c['pt'], 't' => $t];
            }
            break;
        case 'shift':
            if ($p) {
                $st['info'] = [$c['pt'], true];
                if (vbf_hasxy($p) && !empty($c['shift'])) $st['fx']['shift'] = ['pt' => $c['pt'], 't' => $t, 'd' => $c['shift']];
            }
            break;
        case 'pressure':
            if ($p) {
                $st['info'] = [$c['pt'], true];
                if (vbf_hasxy($p) && ($p['pres'] ?? null) !== null) $st['fx']['pressure'] = ['pt' => $c['pt'], 't' => $t];
            }
            break;
        case 'radius7':
        case 'radius10':
            if (!$plan['anim']) break;
            $lvl = $act === 'radius7' ? 7 : 10;
            $k = vbf_ease(vbf_prog($t, $plan['anim']['t0'], $plan['anim']['dur']));
            if ($t >= $plan['anim']['t0']) $st['radius'][$lvl] = ['k' => max(0.01, $k), 'labels' => $t >= $plan['total']];
            break;
        case 'move':
            if ($plan['move']) $doMove($plan['move']);
            break;
        case 'land':
            $L = $c['land'] ?? null;
            if (!vbf_hasxy($p) || !$L || $t < $plan['t_after']) break;
            if (!empty($L['onLand'])) {
                $st['fx']['hlProv'] = $L['prov'];
                $st['fx']['label'] = ['lat' => $p['lat'], 'lon' => $p['lon'], 'text' => 'Trên đất liền ' . ($L['provFull'] ?? $L['provShort'] ?? $L['prov'])];
            } else {
                $st['fx']['hlProv'] = $L['prov'];
                $k = vbf_ease(vbf_prog($t, $plan['anim']['t0'], $plan['anim']['dur']));
                $st['fx']['line'] = [$p['lat'], $p['lon'], $p['lat'] + ($L['lat'] - $p['lat']) * $k, $p['lon'] + ($L['lon'] - $p['lon']) * $k];
                if ($t >= $plan['total']) {
                    $st['fx']['coast'] = ['lat' => $L['lat'], 'lon' => $L['lon'], 'text' => $L['provFull'] ?? $L['provShort'] ?? $L['prov']];
                    $st['fx']['dist'] = ['lat' => ($p['lat'] + $L['lat']) / 2, 'lon' => ($p['lon'] + $L['lon']) / 2, 'km' => $L['km']];
                }
            }
            break;
    }
    return $st;
}

function vbf_ens_st(array &$st, $p) {
    $lv = $st['storm']['lv'] ?? null;
    if ($p['lv'] !== null) $lv = $p['lv'];
    $st['storm'] = ['lat' => $p['lat'], 'lon' => $p['lon'], 'lv' => $lv, 'fade' => false];
}

function vbf_audio_dur($ffmpeg, $file) {
    $o = (string)@shell_exec(escapeshellarg($ffmpeg) . ' -hide_banner -i ' . escapeshellarg($file) . ' 2>&1');
    if (preg_match('/Duration:\s*(\d+):(\d+):(\d+(?:\.\d+)?)/', $o, $m)) return (int)$m[1] * 3600 + (int)$m[2] * 60 + (float)$m[3];
    return null;
}

// ----------------------------------------------------------------------------- Bộ vẽ khung hình bằng GD
class VbfRender {
    public $W; public $H; public $k; public $u; public $im;
    private $ze = 0.0; private $ox = 0.0; private $oy = 0.0; private $zDesign = 0.0;
    private $fontR; private $fontB;
    private $tiles = []; private $miss = [];
    private $base; private $baseKey = '';
    private $baseName; private $cacheDir; private $provPath;
    private $prov = null; private $provSimp = []; private $provByName = [];
    private $twMemo = []; private $wrapMemo = [];

    public function __construct($W, $H, $baseName, array $fonts, $provPath) {
        $this->W = $W; $this->H = $H; $this->k = $W / VBF_DW; $this->u = $W / 100;
        $this->fontR = $fonts[0]; $this->fontB = $fonts[1];
        $this->baseName = in_array($baseName, VBF_BASES, true) ? $baseName : 'sat';
        $this->cacheDir = __DIR__ . '/tile_cache';
        $this->provPath = $provPath;
        $this->im = imagecreatetruecolor($W, $H);
        imagealphablending($this->im, true);
        $this->base = imagecreatetruecolor($W, $H);
    }

    // ------------------------------------------------------------- Màu / chữ / hình cơ bản
    public function col($hex, $a = 1.0, $im = null) {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        return imagecolorallocatealpha($im ?: $this->im, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)), (int)round(127 * (1 - max(0, min(1, $a)))));
    }

    public function tw($px, $bold, $s) {
        $key = $px . '|' . ($bold ? 1 : 0) . '|' . $s;
        if (isset($this->twMemo[$key])) return $this->twMemo[$key];
        $bb = imagettfbbox($px * 0.75, 0, $bold ? $this->fontB : $this->fontR, $s);
        if (count($this->twMemo) > 4000) $this->twMemo = [];
        return $this->twMemo[$key] = $bb[2] - $bb[0];
    }

    public function tx($px, $x, $y, $col, $bold, $s) {
        imagettftext($this->im, $px * 0.75, 0, (int)round($x), (int)round($y + $px * 0.98), $col, $bold ? $this->fontB : $this->fontR, $s);
    }

    public function wrap($px, $bold, $s, $maxw) {
        $key = $px . '|' . ($bold ? 1 : 0) . '|' . $maxw . '|' . $s;
        if (isset($this->wrapMemo[$key])) return $this->wrapMemo[$key];
        $words = preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY);
        $lines = []; $cur = '';
        foreach ($words as $w) {
            $try = $cur === '' ? $w : $cur . ' ' . $w;
            if ($cur === '' || $this->tw($px, $bold, $try) <= $maxw) $cur = $try;
            else { $lines[] = $cur; $cur = $w; }
        }
        if ($cur !== '') $lines[] = $cur;
        if (count($this->wrapMemo) > 500) $this->wrapMemo = [];
        return $this->wrapMemo[$key] = $lines;
    }

    public function rect($x, $y, $w, $h, $col) {
        imagefilledrectangle($this->im, (int)round($x), (int)round($y), (int)round($x + $w - 1), (int)round($y + $h - 1), $col);
    }

    public function ln($x1, $y1, $x2, $y2, $w, $col) {
        $wi = max(1, (int)round($w));
        imagesetthickness($this->im, $wi);
        imageline($this->im, (int)round($x1), (int)round($y1), (int)round($x2), (int)round($y2), $col);
        imagesetthickness($this->im, 1);
    }

    public function disc($x, $y, $d, $col) {
        imagefilledellipse($this->im, (int)round($x), (int)round($y), max(1, (int)round($d)), max(1, (int)round($d)), $col);
    }

    public function polyline(array $pts, $w, $col, $round = true) {
        $n = count($pts);
        for ($i = 0; $i < $n - 1; $i++) {
            if (!$this->seg_visible($pts[$i], $pts[$i + 1])) continue;
            $this->ln($pts[$i][0], $pts[$i][1], $pts[$i + 1][0], $pts[$i + 1][1], $w, $col);
        }
        if ($round && $w >= 3) foreach ($pts as $p) if ($p[0] > -50 && $p[0] < $this->W + 50 && $p[1] > -50 && $p[1] < $this->H + 50) $this->disc($p[0], $p[1], $w, $col);
    }

    private function seg_visible($a, $b) {
        $m = 80;
        if ($a[0] < -$m && $b[0] < -$m) return false;
        if ($a[0] > $this->W + $m && $b[0] > $this->W + $m) return false;
        if ($a[1] < -$m && $b[1] < -$m) return false;
        if ($a[1] > $this->H + $m && $b[1] > $this->H + $m) return false;
        return true;
    }

    public function dashed(array $pts, $dash, $gap, $w, $col) {
        $draw = true; $rem = $dash;
        for ($i = 0, $n = count($pts); $i < $n - 1; $i++) {
            [$x1, $y1] = $pts[$i]; [$x2, $y2] = $pts[$i + 1];
            $L = hypot($x2 - $x1, $y2 - $y1);
            if ($L < 0.01 || !$this->seg_visible($pts[$i], $pts[$i + 1])) continue;
            $ux = ($x2 - $x1) / $L; $uy = ($y2 - $y1) / $L; $pos = 0.0;
            while ($pos < $L) {
                $step = min($rem, $L - $pos);
                if ($draw) $this->ln($x1 + $ux * $pos, $y1 + $uy * $pos, $x1 + $ux * ($pos + $step), $y1 + $uy * ($pos + $step), $w, $col);
                $pos += $step; $rem -= $step;
                if ($rem <= 1e-6) { $draw = !$draw; $rem = $draw ? $dash : $gap; }
            }
        }
    }

    private function pfill($im, array $flat, $col) {
        $n = intdiv(count($flat), 2);
        if ($n < 3) return;
        if (PHP_VERSION_ID >= 80100) imagefilledpolygon($im, $flat, $col);
        else imagefilledpolygon($im, $flat, $n, $col);
    }

    // Nhãn (hộp tối + chữ). anchor: 'c' = giữa theo x, đáy tại y; 'l' = bắt đầu tại x, giữa theo y.
    public function label($x, $y, $text, $px, $textHex, $anchor = 'c', $borderHex = null, $alpha = 1.0, $bold = true) {
        $k = $this->k;
        $padX = $px * 0.55; $padY = $px * 0.3;
        $w = $this->tw($px, $bold, $text) + $padX * 2; $h = $px * 1.25 + $padY * 2;
        $bx = $anchor === 'c' ? $x - $w / 2 : $x;
        $by = $anchor === 'c' ? $y - $h : $y - $h / 2;
        $this->rect($bx, $by, $w, $h, $this->col('#050a14', 0.86 * $alpha));
        if ($borderHex) $this->rect($bx, $by, max(2, 3 * $k), $h, $this->col($borderHex, $alpha));
        $this->tx($px, $bx + $padX, $by + $padY, $this->col($textHex, $alpha), $bold, $text);
        return [$bx, $by, $w, $h];
    }

    // ------------------------------------------------------------- Máy quay & chiếu
    public function setCam(array $cam) {
        $this->zDesign = $cam[2];
        $this->ze = $cam[2] + log($this->k, 2);
        $n = 256 * pow(2, $this->ze);
        $this->ox = $cam[0] * $n - $this->W / 2;
        $this->oy = $cam[1] * $n - $this->H / 2;
    }

    public function P($lat, $lon) {
        [$x, $y] = vbf_norm($lat, $lon);
        $n = 256 * pow(2, $this->ze);
        return [$x * $n - $this->ox, $y * $n - $this->oy];
    }

    // ------------------------------------------------------------- Tile bản đồ
    private function layerInfo($layer) {
        switch ($layer) {
            case 'satlbl': return ['https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/%1$d/%3$d/%2$d', 18];
            case 'dark':   return ['https://a.basemaps.cartocdn.com/dark_all/%1$d/%2$d/%3$d.png', 19];
            case 'light':  return ['https://a.basemaps.cartocdn.com/rastertiles/voyager/%1$d/%2$d/%3$d.png', 19];
            default:       return ['https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/%1$d/%3$d/%2$d', 18];
        }
    }

    private function tilePath($layer, $z, $x, $y) { return $this->cacheDir . "/$layer/$z/{$x}_{$y}.t"; }

    private function prefetch($layer, $z, array $coords) {
        [$tpl] = $this->layerInfo($layer);
        $need = [];
        foreach ($coords as [$x, $y]) {
            $key = "$layer/$z/$x/$y";
            if (isset($this->tiles[$key]) || isset($this->miss[$key])) continue;
            $path = $this->tilePath($layer, $z, $x, $y);
            if (is_file($path) && filesize($path) > 100) continue;
            $need[$key] = [sprintf($tpl, $z, $x, $y), $path];
        }
        foreach (array_chunk($need, 16, true) as $chunk) {
            $mh = curl_multi_init(); $hs = [];
            foreach ($chunk as $key => [$url, $path]) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 6,
                    CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; StormBulletinVideo/1.0)', CURLOPT_SSL_VERIFYPEER => true]);
                curl_multi_add_handle($mh, $ch);
                $hs[$key] = $ch;
            }
            do { curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1.0); } while ($running > 0);
            foreach ($hs as $key => $ch) {
                $body = curl_multi_getcontent($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                $path = $chunk[$key][1];
                if ($code === 200 && is_string($body) && strlen($body) > 100) {
                    if (!is_dir(dirname($path))) @mkdir(dirname($path), 0755, true);
                    @file_put_contents($path, $body, LOCK_EX);
                } else {
                    $this->miss[$key] = true;
                }
            }
            curl_multi_close($mh);
        }
    }

    private function tileImg($layer, $z, $x, $y) {
        $key = "$layer/$z/$x/$y";
        if (isset($this->tiles[$key])) return $this->tiles[$key];
        if (isset($this->miss[$key])) return null;
        $path = $this->tilePath($layer, $z, $x, $y);
        if (!is_file($path)) { $this->miss[$key] = true; return null; }
        $img = @imagecreatefromstring((string)file_get_contents($path));
        if (!$img) { @unlink($path); $this->miss[$key] = true; return null; }
        if (count($this->tiles) >= 160) { $k0 = array_key_first($this->tiles); imagedestroy($this->tiles[$k0]); unset($this->tiles[$k0]); }
        return $this->tiles[$key] = $img;
    }

    private function drawTiles($layer, $zt, $dst, $labels) {
        [, $maxz] = $this->layerInfo($layer);
        $zt = max(0, min($maxz, $zt));
        $ts = 256 * pow(2, $this->ze - $zt);
        $n = 1 << $zt;
        $tx0 = (int)floor($this->ox / $ts); $tx1 = (int)floor(($this->ox + $this->W - 1) / $ts);
        $ty0 = max(0, (int)floor($this->oy / $ts)); $ty1 = min($n - 1, (int)floor(($this->oy + $this->H - 1) / $ts));
        if (($tx1 - $tx0 + 1) * ($ty1 - $ty0 + 1) > 400) return;
        $coords = [];
        for ($ty = $ty0; $ty <= $ty1; $ty++) for ($tx = $tx0; $tx <= $tx1; $tx++) $coords[] = [(($tx % $n) + $n) % $n, $ty];
        $this->prefetch($layer, $zt, $coords);
        for ($ty = $ty0; $ty <= $ty1; $ty++) {
            for ($tx = $tx0; $tx <= $tx1; $tx++) {
                $wx = (($tx % $n) + $n) % $n;
                $x0 = (int)round($tx * $ts - $this->ox); $y0 = (int)round($ty * $ts - $this->oy);
                $w = (int)round(($tx + 1) * $ts - $this->ox) - $x0; $h = (int)round(($ty + 1) * $ts - $this->oy) - $y0;
                if ($w < 1 || $h < 1) continue;
                $img = $this->tileImg($layer, $zt, $wx, $ty);
                if ($img) {
                    if ($labels) {
                        $sc = ($w === imagesx($img) && $h === imagesy($img)) ? $img : imagescale($img, $w, $h);
                        imagecopy($dst, $sc, $x0, $y0, 0, 0, $w, $h);
                        if ($sc !== $img) imagedestroy($sc);
                    } else {
                        imagecopyresampled($dst, $img, $x0, $y0, 0, 0, $w, $h, imagesx($img), imagesy($img));
                    }
                } elseif (!$labels) {
                    for ($d = 1; $d <= 4 && $zt - $d >= 0; $d++) {
                        $px = $wx >> $d; $py = $ty >> $d;
                        $par = $this->tileImg($layer, $zt - $d, $px, $py);
                        if (!$par) continue;
                        $ss = imagesx($par) / (1 << $d);
                        imagecopyresampled($dst, $par, $x0, $y0, ($wx & ((1 << $d) - 1)) * $ss, ($ty & ((1 << $d) - 1)) * $ss, $w, $h, $ss, $ss);
                        break;
                    }
                }
            }
        }
    }

    // ------------------------------------------------------------- Ranh giới tỉnh (../vn.json)
    private function loadProv() {
        if ($this->prov !== null) return;
        $this->prov = [];
        $raw = null;
        foreach ([$this->provPath, dirname(__DIR__) . '/vn.json', __DIR__ . '/vn.json'] as $f) {
            if ($f && is_file($f)) { $raw = @file_get_contents($f); if ($raw) break; }
        }
        $data = $raw ? json_decode($raw, true) : null;
        if (!is_array($data) || empty($data['features'])) return;
        $total = count($data['features']);
        foreach ($data['features'] as $i => $f) {
            $props = $f['properties'] ?? [];
            $name = $props['adm1_name1'] ?? ($props['adm1_name'] ?? 'Tỉnh không xác định');
            $geom = $f['geometry'] ?? null;
            if (!$geom) continue;
            $polys = $geom['type'] === 'Polygon' ? [$geom['coordinates']] : ($geom['type'] === 'MultiPolygon' ? $geom['coordinates'] : []);
            $rings = []; $minLo = 999; $minLa = 999; $maxLo = -999; $maxLa = -999;
            foreach ($polys as $poly) {
                if (empty($poly[0])) continue;
                $ring = [];
                foreach ($poly[0] as $pt) {
                    $ring[] = [(float)$pt[0], (float)$pt[1]];
                    $minLo = min($minLo, $pt[0]); $maxLo = max($maxLo, $pt[0]); $minLa = min($minLa, $pt[1]); $maxLa = max($maxLa, $pt[1]);
                }
                if (count($ring) >= 3) $rings[] = $ring;
            }
            if (!$rings) continue;
            $h = (($i * (360 / $total)) % 360) / 360; $s = 0.7; $l = 0.55;
            $q = $l < .5 ? $l * (1 + $s) : $l + $s - $l * $s; $p = 2 * $l - $q;
            $hue = function ($t) use ($p, $q) { if ($t < 0) $t += 1; if ($t > 1) $t -= 1; if ($t < 1 / 6) return $p + ($q - $p) * 6 * $t; if ($t < 1 / 2) return $q; if ($t < 2 / 3) return $p + ($q - $p) * (2 / 3 - $t) * 6; return $p; };
            $idx = count($this->prov);
            $this->prov[] = [
                'name' => (string)$name, 'rings' => $rings, 'bbox' => [$minLo, $minLa, $maxLo, $maxLa],
                'rgb' => [(int)round($hue($h + 1 / 3) * 255), (int)round($hue($h) * 255), (int)round($hue($h - 1 / 3) * 255)],
                'center' => [($minLa + $maxLa) / 2, ($minLo + $maxLo) / 2],
            ];
            $this->provByName[(string)$name] = $idx;
        }
    }

    private function simp($fi, $ri, array $ring, $zi) {
        $key = "$fi/$ri/$zi";
        if (isset($this->provSimp[$key])) return $this->provSimp[$key];
        $tol = 360 / (256 * pow(2, $zi));
        $out = [$ring[0]]; $last = $ring[0];
        for ($i = 1, $n = count($ring); $i < $n; $i++) {
            if (abs($ring[$i][0] - $last[0]) + abs($ring[$i][1] - $last[1]) > $tol) { $out[] = $ring[$i]; $last = $ring[$i]; }
        }
        if (count($out) < 3) $out = $ring;
        return $this->provSimp[$key] = $out;
    }

    private function provScreen($fi, $ri, $zi) {
        $out = [];
        foreach ($this->simp($fi, $ri, $this->prov[$fi]['rings'][$ri], $zi) as $pt) {
            [$x, $y] = $this->P($pt[1], $pt[0]);
            $out[] = $x; $out[] = $y;
        }
        return $out;
    }

    private function ringVisible($bbox) {
        [$x1, $y1] = $this->P($bbox[3], $bbox[0]);
        [$x2, $y2] = $this->P($bbox[1], $bbox[2]);
        return !($x2 < -20 || $x1 > $this->W + 20 || $y1 < -20 || $y2 > $this->H + 20 || $y1 > $this->H + 20);
    }

    private function drawProv($im, $withLabels) {
        $this->loadProv();
        if (!$this->prov) return;
        $zi = (int)round($this->ze);
        $k = $this->k;
        $line = $this->col('#1e3a5f', 0.9, $im);
        $prev = $this->im; $this->im = $im;
        foreach ($this->prov as $fi => $f) {
            $bb = $f['bbox'];
            [$ax, $ay] = $this->P($bb[3], $bb[0]); [$bx, $by] = $this->P($bb[1], $bb[2]);
            if ($bx < -20 || $ax > $this->W + 20 || $by < -20 || $ay > $this->H + 20) continue;
            $fill = imagecolorallocatealpha($im, $f['rgb'][0], $f['rgb'][1], $f['rgb'][2], (int)round(127 * (1 - 0.35)));
            foreach ($f['rings'] as $ri => $_) {
                $flat = $this->provScreen($fi, $ri, $zi);
                $this->pfill($im, $flat, $fill);
                $pts = [];
                for ($i = 0, $n = count($flat); $i < $n; $i += 2) $pts[] = [$flat[$i], $flat[$i + 1]];
                if ($pts) { $pts[] = $pts[0]; $this->polyline($pts, 2 * $k, $line, false); }
            }
        }
        if ($withLabels && $this->zDesign >= 7.2) {
            $sh = $this->col('#000000', 0.8, $im); $wh = $this->col('#ffffff', 1, $im);
            $px = 11 * $k;
            foreach ($this->prov as $f) {
                [$x, $y] = $this->P($f['center'][0], $f['center'][1]);
                if ($x < 0 || $x > $this->W || $y < 0 || $y > $this->H) continue;
                $w = $this->tw($px, true, $f['name']);
                $this->tx($px, $x - $w / 2 + 1, $y - $px / 2 + 1, $sh, true, $f['name']);
                $this->tx($px, $x - $w / 2, $y - $px / 2, $wh, true, $f['name']);
            }
        }
        $this->im = $prev;
    }

    private function islandLabels($im) {
        $prev = $this->im; $this->im = $im;
        $px = 10.5 * $this->k;
        $sh = $this->col('#000000', 0.85, $im); $wh = $this->col('#fde68a', 1, $im);
        foreach ([[16.4, 112.0, 'Đặc khu Hoàng Sa', '(Việt Nam)'], [8.65, 111.92, 'Đặc khu Trường Sa', '(Việt Nam)']] as [$la, $lo, $a, $b]) {
            [$x, $y] = $this->P($la, $lo);
            if ($x < -100 || $x > $this->W + 100 || $y < -50 || $y > $this->H + 50) continue;
            foreach ([$a, $b] as $i => $s) {
                $w = $this->tw($px, true, $s);
                $this->tx($px, $x - $w / 2 + 1, $y - $px + $i * $px * 1.25 + 1, $sh, true, $s);
                $this->tx($px, $x - $w / 2, $y - $px + $i * $px * 1.25, $wh, true, $s);
            }
        }
        $this->im = $prev;
    }

    private function buildBase($moving) {
        $key = sprintf('%.2f|%.2f|%.3f|%d', $this->ox, $this->oy, $this->ze, $moving ? 1 : 0);
        if ($key === $this->baseKey) return;
        imagefilledrectangle($this->base, 0, 0, $this->W, $this->H, $this->baseName === 'light' ? 0xd4dadc : 0x0b1626);
        $zt = (int)round($this->ze) - ($moving ? 1 : 0);
        $this->drawTiles($this->baseName, $zt, $this->base, false);
        if ($this->baseName === 'sat') $this->drawTiles('satlbl', $zt, $this->base, true);
        $this->drawProv($this->base, true);
        $this->islandLabels($this->base);
        $this->baseKey = $key;
    }

    // ------------------------------------------------------------- Khung hình đầy đủ
    public function frame(array $st, array $P, $tAbs) {
        $k = $this->k; $u = $this->u;
        $this->setCam($st['cam']);
        $this->buildBase($st['moving']);
        imagealphablending($this->im, false);
        imagecopy($this->im, $this->base, 0, 0, 0, 0, $this->W, $this->H);
        imagealphablending($this->im, true);
        $pts = $P['pts'];

        // Tỉnh được tô sáng (cách đất liền)
        if (!empty($st['fx']['hlProv'])) $this->highlightProv($st['fx']['hlProv']);

        // Đường đi quá khứ
        if ($st['past'] > 0 && !empty($P['pastPts'])) {
            $pp = $P['pastPts']; $nseg = count($pp) - 1; $m = $st['past'] * $nseg;
            for ($i = 0; $i < $nseg; $i++) {
                if ($i >= $m) break;
                $a = $pp[$i]; $b = $pp[$i + 1];
                if ($i + 1 > $m) { $f = $m - $i; $b = [$a[0] + ($b[0] - $a[0]) * $f, $a[1] + ($b[1] - $a[1]) * $f, $b[2]]; }
                $this->polyline([$this->P($a[0], $a[1]), $this->P($b[0], $b[1])], 3.5 * $k, $this->col(vbf_lv_color($a[2]), 0.9));
            }
        }

        // Vùng gió mạnh
        $p0 = $pts[0] ?? null;
        foreach ([7 => $P['gale'], 10 => $P['storm']] as $lvl => $area) {
            if (!isset($st['radius'][$lvl]) || !$area || !vbf_hasxy($p0)) continue;
            $this->drawRadius($p0, $area, $lvl, $st['radius'][$lvl]);
        }

        // Đường dự báo
        $fc = $st['fcPath'];
        if ($st['fcHead']) $fc[] = $st['fcHead'];
        if (count($fc) >= 2) {
            $sp = [];
            foreach ($fc as $q) $sp[] = $this->P($q[0], $q[1]);
            $this->dashed($sp, 8 * $k, 7 * $k, 2.5 * $k, $this->col('#ffffff', 0.9));
        }

        // Đường nối tới đất liền
        if (!empty($st['fx']['line'])) {
            $l = $st['fx']['line'];
            $this->dashed([$this->P($l[0], $l[1]), $this->P($l[2], $l[3])], 10 * $k, 8 * $k, 3 * $k, $this->col('#38bdf8', 0.95));
        }

        // Điểm dự báo + thời gian
        foreach ($st['dots'] as $i) {
            $p = $pts[$i] ?? null;
            if (!vbf_hasxy($p)) continue;
            [$x, $y] = $this->P($p['lat'], $p['lon']);
            $r = ($i === 0 ? 7 : 5.5) * $k;
            $this->disc($x, $y, ($r + 2) * 2, $this->col('#ffffff'));
            $this->disc($x, $y, ($r - 0) * 2 - 2 * $k, $this->col(vbf_lv_color($p['lv'])));
            if (!empty($p['time'])) $this->label($x + 10 * $k, $y, $p['time'], 11 * $k, '#ffffff', 'l', null, 1.0, false);
        }

        // Bão
        if ($st['storm']) {
            [$x, $y] = $this->P($st['storm']['lat'], $st['storm']['lon']);
            $alpha = $st['storm']['fade'] ? 0.35 : 1.0;
            if ($st['blink']) $alpha *= 0.6 + 0.4 * (0.5 + 0.5 * sin($tAbs * 2 * M_PI * 1.2));
            $this->stormIcon($x, $y, $st['storm']['lv'], $alpha, $tAbs * deg2rad(110));
            if ($st['tag']) {
                [$tx, $ty] = $this->P($st['tag']['lat'], $st['tag']['lon']);
                $this->label($tx, $ty - 30 * $k, $st['tag']['text'], 13 * $k, '#ffffff', 'c', '#38bdf8');
            }
        }

        $fx = $st['fx'];
        if (!empty($fx['pulse']) && $fx['pulse']['t'] >= 0) {
            [$x, $y] = $this->P($fx['pulse']['lat'], $fx['pulse']['lon']);
            foreach ([0, 0.6] as $off) {
                $ph = fmod(max(0, $fx['pulse']['t'] - $off), 1.8) / 1.8;
                if ($fx['pulse']['t'] < $off) continue;
                $this->ring($x, $y, (8 + $ph * 60) * $k, 2.5 * $k, $this->col('#ffffff', (1 - $ph) * 0.8));
            }
        }
        if (!empty($fx['wind'])) $this->windLabel($fx['wind'], $pts);
        if (!empty($fx['pressure'])) $this->pressureLabel($fx['pressure'], $pts);
        if (!empty($fx['shift'])) $this->shiftLabel($fx['shift'], $pts);
        if (!empty($fx['done'])) {
            [$x, $y] = $this->P($fx['done']['lat'], $fx['done']['lon']);
            $this->label($x + 30 * $k, $y, $fx['done']['text'], 16 * $k, '#fca5a5', 'l', '#ef4444');
        }
        if (!empty($fx['label'])) {
            [$x, $y] = $this->P($fx['label']['lat'], $fx['label']['lon']);
            $this->label($x, $y - 34 * $k, $fx['label']['text'], 15 * $k, '#fde68a', 'c', '#f59e0b');
        }
        if (!empty($fx['coast'])) {
            [$x, $y] = $this->P($fx['coast']['lat'], $fx['coast']['lon']);
            $this->disc($x, $y, 16 * $k, $this->col('#ffffff')); $this->disc($x, $y, 11 * $k, $this->col('#f59e0b'));
            $this->label($x, $y - 12 * $k, $fx['coast']['text'], 15 * $k, '#fde68a', 'c', '#f59e0b');
        }
        if (!empty($fx['dist'])) {
            [$x, $y] = $this->P($fx['dist']['lat'], $fx['dist']['lon']);
            $b = $this->label($x, $y, '≈ ' . number_format($fx['dist']['km'], 0, ',', '.') . ' km', 18 * $k, '#ffffff', 'c', '#38bdf8');
            $this->label($x, $y + 20 * $k, 'tới đất liền gần nhất', 11 * $k, '#cbd5e1', 'c', null, 1.0, false);
        }

        $this->hud($st, $P);
        return $this->im;
    }

    private function ring($x, $y, $r, $w, $col) {
        $prev = null;
        for ($i = 0; $i <= 36; $i++) {
            $a = $i / 36 * 2 * M_PI;
            $p = [$x + cos($a) * $r, $y + sin($a) * $r];
            if ($prev) $this->ln($prev[0], $prev[1], $p[0], $p[1], $w, $col);
            $prev = $p;
        }
    }

    private function highlightProv($name) {
        $this->loadProv();
        if (!isset($this->provByName[$name])) return;
        $fi = $this->provByName[$name];
        $zi = (int)round($this->ze);
        $fill = $this->col('#f59e0b', 0.4); $line = $this->col('#f59e0b', 1);
        foreach ($this->prov[$fi]['rings'] as $ri => $_) {
            $flat = $this->provScreen($fi, $ri, $zi);
            $this->pfill($this->im, $flat, $fill);
            $pts = [];
            for ($i = 0, $n = count($flat); $i < $n; $i += 2) $pts[] = [$flat[$i], $flat[$i + 1]];
            if ($pts) { $pts[] = $pts[0]; $this->polyline($pts, 3.5 * $this->k, $line, false); }
        }
    }

    private function drawRadius($p0, $area, $lvl, $rs) {
        $k = $this->k;
        $c = vbf_area_circle($p0, $area, $rs['k']);
        $poly = []; $flat = [];
        for ($i = 0; $i < 120; $i++) {
            [$la, $lo] = vbf_dest($c['lat'], $c['lon'], $c['r'] / 1000, $i * 3);
            $q = $this->P($la, $lo);
            $poly[] = $q; $flat[] = $q[0]; $flat[] = $q[1];
        }
        $poly[] = $poly[0];
        if ($lvl === 7) {
            $this->pfill($this->im, $flat, $this->col('#fbbf24', 0.12));
            $this->dashed($poly, 8 * $k, 6 * $k, 2 * $k, $this->col('#f59e0b', 0.85));
            $hex = '#f59e0b';
        } else {
            $this->pfill($this->im, $flat, $this->col('#f87171', 0.2));
            $this->polyline($poly, 2.5 * $k, $this->col('#ef4444', 0.9), false);
            $hex = '#ef4444';
        }
        if (empty($rs['labels'])) return;
        $VI = [0 => 'Bắc', 45 => 'Đông Bắc', 90 => 'Đông', 135 => 'Đông Nam', 180 => 'Nam', 225 => 'Tây Nam', 270 => 'Tây', 315 => 'Tây Bắc'];
        $edges = !empty($area['uniform'])
            ? [['km' => $area['km'], 'brg' => $lvl === 7 ? 45 : 135, 'txt' => "Cấp $lvl+ · {$area['km']} km"]]
            : [
                ['km' => $area['longKm'], 'brg' => $area['longBearing'], 'txt' => "Cấp $lvl+ · " . ($VI[$area['longBearing']] ?? '') . " {$area['longKm']} km"],
                ['km' => $area['shortKm'], 'brg' => $area['shortBearing'], 'txt' => ($VI[$area['shortBearing']] ?? '') . " {$area['shortKm']} km"],
            ];
        $o = $this->P($p0['lat'], $p0['lon']);
        foreach ($edges as $e) {
            [$la, $lo] = vbf_dest($p0['lat'], $p0['lon'], $e['km'], $e['brg']);
            $tip = $this->P($la, $lo);
            $this->dashed([$o, $tip], 3 * $k, 5 * $k, 1.5 * $k, $this->col($hex, 0.9));
            $this->label($tip[0], $tip[1] - 4 * $k, $e['txt'], 12 * $k, '#ffffff', 'c', $hex);
        }
    }

    // Thay đổi hướng / tốc độ / suy yếu: mũi tên xoay hoặc co giãn + nhãn.
    private function shiftLabel(array $w, array $pts) {
        $p = $pts[$w['pt']]; $d = $w['d']; $k = $this->k;
        [$x, $y] = $this->P($p['lat'], $p['lon']);
        $t = max(0, $w['t']);
        $a = min(1, $t / 0.35);
        $e = min(1, $t / 1.2); $e = $e * $e * (3 - 2 * $e);
        $col = $d['col'] ?? '#a78bfa';
        if ($d['kind'] === 'weaken') {
            for ($i = 0; $i < 2; $i++) {
                $ph = fmod($t + $i * 0.6, 1.2) / 1.2;
                $this->ring($x, $y, (18 + $ph * 50) * $k, 2.5 * $k, $this->col($col, (1 - $ph) * 0.85));
            }
        } else {
            $ang = deg2rad(($d['a0'] ?? 0) + (($d['a1'] ?? 0) - ($d['a0'] ?? 0)) * $e);
            $len = (($d['l0'] ?? 60) + (($d['l1'] ?? 60) - ($d['l0'] ?? 60)) * $e) * $k;
            $dx = sin($ang); $dy = -cos($ang);
            $ex = $x + $dx * $len; $ey = $y + $dy * $len;
            $this->polyline([[$x, $y], [$ex, $ey]], 5 * $k, $this->col($col, 0.95 * $a));
            foreach ([150, -150] as $off) {
                $hx = $ang + deg2rad($off);
                $this->polyline([[$ex, $ey], [$ex + sin($hx) * 16 * $k, $ey - cos($hx) * 16 * $k]], 5 * $k, $this->col($col, 0.95 * $a));
            }
        }
        $this->disc($x, $y, 10 * $k, $this->col($col, 0.9 * $a));
        $bx = $x + 34 * $k;
        $lines = [[$d['title'] ?? '', 12 * $k, false, '#cbd5e1'], [$d['lab'] ?? '', 22 * $k, true, '#ffffff']];
        $wmax = 0; $h = 12 * $k;
        foreach ($lines as $l) { $wmax = max($wmax, $this->tw($l[1], $l[2], $l[0])); $h += $l[1] * 1.2; }
        $by = $y - $h / 2;
        $this->rect($bx, $by, $wmax + 26 * $k, $h, $this->col('#050a14', 0.88 * $a));
        $this->rect($bx, $by, 5 * $k, $h, $this->col($col, $a));
        $cy = $by + 6 * $k;
        foreach ($lines as $l) { $this->tx($l[1], $bx + 16 * $k, $cy, $this->col($l[3], $a), $l[2], $l[0]); $cy += $l[1] * 1.2; }
    }

    // Áp suất: các vòng đẳng áp co dần vào tâm bão + nhãn "Áp suất ... hPa".
    private function pressureLabel(array $w, array $pts) {
        $p = $pts[$w['pt']]; $k = $this->k;
        [$x, $y] = $this->P($p['lat'], $p['lon']);
        $t = max(0, $w['t']);
        $a = min(1, $t / 0.35);
        $col = '#38bdf8';
        for ($i = 0; $i < 3; $i++) {
            $off = $i * 0.5;
            if ($t < $off) continue;
            $ph = fmod($t - $off, 1.5) / 1.5;
            $this->ring($x, $y, (64 - $ph * 52) * $k, 2.5 * $k, $this->col($col, sin($ph * M_PI) * 0.85));
        }
        $this->disc($x, $y, 10 * $k, $this->col($col, 0.9 * $a));
        $bx = $x + 34 * $k;
        $lines = [['Áp suất thấp nhất', 12 * $k, false, '#cbd5e1'], [$p['pres'] . ' hPa', 24 * $k, true, '#ffffff']];
        $wmax = 0; $h = 12 * $k;
        foreach ($lines as $l) { $wmax = max($wmax, $this->tw($l[1], $l[2], $l[0])); $h += $l[1] * 1.2; }
        $wbox = $wmax + 26 * $k;
        $by = $y - $h / 2;
        $this->rect($bx, $by, $wbox, $h, $this->col('#050a14', 0.88 * $a));
        $this->rect($bx, $by, 5 * $k, $h, $this->col($col, $a));
        $cy = $by + 6 * $k;
        foreach ($lines as $l) { $this->tx($l[1], $bx + 16 * $k, $cy, $this->col($l[3], $a), $l[2], $l[0]); $cy += $l[1] * 1.2; }
    }

    private function windLabel(array $w, array $pts) {
        $p = $pts[$w['pt']]; $k = $this->k;
        [$x, $y] = $this->P($p['lat'], $p['lon']);
        $a = min(1, $w['t'] / 0.35);
        $hex = vbf_lv_color($p['lv']);
        $bx = $x + 34 * $k; $px1 = 24 * $k; $px2 = 13 * $k; $px3 = 11 * $k;
        $lines = [['Cấp ' . $p['lv'], $px1, true, '#ffffff']];
        if (!empty($p['gust'])) $lines[] = ['Giật ' . $p['gust'], $px2, true, '#fde68a'];
        if (!empty($p['kmh'])) $lines[] = ['~' . $p['kmh'] . ' km/h', $px3, false, '#cbd5e1'];
        $wmax = 0; $h = 12 * $k;
        foreach ($lines as $l) { $wmax = max($wmax, $this->tw($l[1], $l[2], $l[0])); $h += $l[1] * 1.2; }
        $wbox = $wmax + 26 * $k;
        $by = $y - $h / 2;
        $this->rect($bx, $by, $wbox, $h, $this->col('#050a14', 0.88 * $a));
        $this->rect($bx, $by, 5 * $k, $h, $this->col($hex, $a));
        $cy = $by + 6 * $k;
        foreach ($lines as $l) { $this->tx($l[1], $bx + 16 * $k, $cy, $this->col($l[3], $a), $l[2], $l[0]); $cy += $l[1] * 1.2; }
    }

    public function stormIcon($sx, $sy, $lv, $alpha, $ang) {
        $sc = 0.46 * $this->k; $hex = vbf_lv_color($lv);
        $bez = function ($p0, $c1, $c2, $p3) {
            $o = [];
            for ($i = 0; $i <= 12; $i++) {
                $t = $i / 12; $m = 1 - $t;
                $o[] = [$m ** 3 * $p0[0] + 3 * $m * $m * $t * $c1[0] + 3 * $m * $t * $t * $c2[0] + $t ** 3 * $p3[0],
                        $m ** 3 * $p0[1] + 3 * $m * $m * $t * $c1[1] + 3 * $m * $t * $t * $c2[1] + $t ** 3 * $p3[1]];
            }
            return $o;
        };
        $circle = [];
        for ($i = 0; $i <= 28; $i++) { $a = $i / 28 * 2 * M_PI; $circle[] = [cos($a) * 15, sin($a) * 15]; }
        $shapes = [$circle, $bez([0, -15], [-22, -15], [-36, -28], [-32, -46]), $bez([0, 15], [22, 15], [36, 28], [32, 46])];
        $ca = cos($ang); $sa = sin($ang);
        $tr = function ($pt) use ($ca, $sa, $sc, $sx, $sy) { return [$sx + ($pt[0] * $ca - $pt[1] * $sa) * $sc, $sy + ($pt[0] * $sa + $pt[1] * $ca) * $sc]; };
        $shadow = $this->col('#000000', 0.5 * $alpha); $main = $this->col($hex, $alpha);
        foreach ($shapes as $sh) $this->polyline(array_map($tr, $sh), 9 * $sc + 3 * $this->k, $shadow);
        foreach ($shapes as $sh) $this->polyline(array_map($tr, $sh), 9 * $sc, $main);
        $this->disc($sx, $sy, 12 * $sc + 3 * $this->k, $shadow);
        $this->disc($sx, $sy, 12 * $sc, $main);
    }

    // ------------------------------------------------------------- HUD (tên bản tin, thông tin, chú thích, phụ đề, bảng)
    private function hud(array $st, array $P) {
        $u = $this->u; $W = $this->W; $H = $this->H;
        $white = $this->col('#ffffff'); $muted = $this->col('#93a0b8');
        $dark = $this->col('#050a14', 0.82);

        // Thương hiệu
        $name = $P['title'];
        $x = 3 * $u; $y = 3 * $u;
        $wB = max($this->tw(2.4 * $u, true, $name), $this->tw(1.25 * $u, true, 'BẢN TIN BÃO')) + 3.2 * $u + 0.5 * $u;
        $hB = 1.25 * $u * 1.3 + 2.4 * $u * 1.25 + 2 * $u;
        $this->rect($x, $y, $wB, $hB, $dark);
        $this->rect($x, $y, 0.5 * $u, $hB, $this->col('#ef4444'));
        $this->tx(1.25 * $u, $x + 0.5 * $u + 1.6 * $u, $y + 1 * $u, $this->col('#fca5a5'), true, 'BẢN TIN BÃO');
        $this->tx(2.4 * $u, $x + 0.5 * $u + 1.6 * $u, $y + 1 * $u + 1.25 * $u * 1.3, $white, true, $name);

        // Thông tin điểm hiện tại
        if ($st['info']) {
            $p = $P['pts'][$st['info'][0]] ?? null;
            if ($p) {
                $rows = [['cls', ($p['cls'] === 'tan' ? 'Tan dần' : vb_ucfirst($p['cls'])), vbf_lv_color($p['lv'])]];
                if (!empty($p['time'])) $rows[] = ['kv', 'Thời gian:', $p['time']];
                if (!empty($p['coord'])) $rows[] = ['kv', 'Vị trí:', $p['coord']];
                if ($st['info'][1] && $p['lv'] !== null) $rows[] = ['kv', 'Gió:', 'cấp ' . $p['lv'] . (!empty($p['gust']) ? ', giật ' . $p['gust'] : '')];
                $f = 1.35 * $u; $wI = 20 * $u;
                foreach ($rows as $r) {
                    $wI = max($wI, $r[0] === 'cls' ? $this->tw(1.9 * $u, true, $r[1]) + 3.2 * $u : $this->tw($f, false, $r[1] . ' ' . $r[2]) + 3.2 * $u);
                }
                $hI = 2.2 * $u; foreach ($rows as $r) $hI += ($r[0] === 'cls' ? 1.9 * $u * 1.3 : $f * 1.5);
                $ix = $W - 3 * $u - $wI; $iy = 3 * $u;
                $this->rect($ix, $iy, $wI, $hI, $dark);
                $cy = $iy + 1.1 * $u;
                foreach ($rows as $r) {
                    if ($r[0] === 'cls') { $this->tx(1.9 * $u, $ix + 1.6 * $u, $cy, $this->col($r[2]), true, $r[1]); $cy += 1.9 * $u * 1.3; }
                    else {
                        $this->tx($f, $ix + 1.6 * $u, $cy, $muted, false, $r[1]);
                        $this->tx($f, $ix + 1.6 * $u + $this->tw($f, false, $r[1] . ' '), $cy, $white, false, $r[2]);
                        $cy += $f * 1.5;
                    }
                }
            }
        }

        // Chú thích
        $items = [];
        if (isset($st['radius'][7])) $items[] = ['#f59e0b', 'Gió mạnh cấp 7 trở lên', false];
        if (isset($st['radius'][10])) $items[] = ['#ef4444', 'Gió mạnh cấp 10 trở lên', false];
        if (count($st['fcPath']) || $st['fcHead']) $items[] = ['#ffffff', 'Đường đi dự báo', true];
        if ($items) {
            $f = 1.1 * $u; $wL = 0;
            foreach ($items as $it) $wL = max($wL, $this->tw($f, false, $it[1]));
            $wL += 1.8 * $u + 2 * $u; $hL = count($items) * ($f * 1.5 + 0.4 * $u) + 1.0 * $u;
            $lx = 3 * $u; $ly = 11 * $u;
            $this->rect($lx, $ly, $wL, $hL, $this->col('#050a14', 0.7));
            $cy = $ly + 0.7 * $u;
            foreach ($items as $it) {
                $this->disc($lx + 1 * $u + 0.6 * $u, $cy + $f * 0.7, 1.2 * $u, $this->col($it[0]));
                if ($it[2]) $this->disc($lx + 1 * $u + 0.6 * $u, $cy + $f * 0.7, 0.8 * $u, $this->col('#000000', 0.6));
                $this->tx($f, $lx + 1 * $u + 1.8 * $u, $cy, $white, false, $it[1]);
                $cy += $f * 1.5 + 0.4 * $u;
            }
        }

        // Nguồn
        $srcT = 'Nguồn: Cơ quan Khí tượng Nhật Bản (JMA)';
        $this->tx(1 * $u, $W - 3 * $u - $this->tw(1 * $u, false, $srcT), $H - 1 * $u - 1.3 * $u, $this->col('#cbd5e1', 0.8), false, $srcT);

        if ($st['panel']) {
            $this->dim(0.5);
            $this->panel($st['panel'], $P);
        }

        // Phụ đề
        if ($st['subtitle'] !== '') {
            $f = 2.25 * $u; $lines = $this->wrap($f, false, $st['subtitle'], $W * 0.88 - 2 * $u);
            $lh = $f * 1.7; $bottom = $H - 4.5 * $u; $top = $bottom - count($lines) * $lh;
            foreach ($lines as $i => $ln) {
                $lw = $this->tw($f, false, $ln) + 2 * $u;
                $lx = ($W - $lw) / 2; $ly = $top + $i * $lh;
                $this->rect($lx, $ly, $lw, $lh, $this->col('#03060e', 0.86));
                $this->tx($f, $lx + 1 * $u, $ly + ($lh - $f * 1.25) / 2, $white, false, $ln);
            }
        }
    }

    private function dim($a) { $this->rect(0, 0, $this->W, $this->H, $this->col('#000000', $a)); }

    private function chip($x, $y, $h, $lv) {
        $u = $this->u; $f = 1.15 * $u;
        if ($lv === null) { $t = '--'; $bg = '#475569'; $fg = '#ffffff'; }
        else { $t = 'Cấp ' . $lv; $bg = vbf_lv_color($lv); $fg = $lv >= 11 ? '#ffffff' : '#111111'; }
        $w = $this->tw($f, true, $t) + 1.4 * $u;
        $this->rect($x, $y + ($h - $f * 1.7) / 2, $w, $f * 1.7, $this->col($bg));
        $this->tx($f, $x + 0.7 * $u, $y + ($h - $f * 1.25) / 2, $this->col($fg), true, $t);
    }

    private function panel(array $c, array $P) {
        $u = $this->u; $W = $this->W; $H = $this->H;
        $white = $this->col('#ffffff'); $muted = $this->col('#93a0b8'); $accent = $this->col('#38bdf8');
        $isHist = $c['act'] === 'history';
        $head = []; $cols = []; $rows = []; $foot = '';
        if ($isHist) {
            $Hh = $P['history'] ?? null;
            if (!$Hh) return;
            $MAX = 9;
            $list = [];
            foreach ($Hh['rows'] as $i => $r) { $r['i'] = $i; $list[] = $r; }
            $hidden = 0;
            if (count($list) > $MAX) {
                $must = [];
                foreach ($list as $r) if (!empty($r['cur']) || $r['i'] === $Hh['strongest']) $must[$r['i']] = true;
                $rest = array_values(array_filter($list, fn($r) => !isset($must[$r['i']])));
                $rest = array_slice($rest, -max(0, $MAX - count($must)));
                $keep = $must; foreach ($rest as $r) $keep[$r['i']] = true;
                $hidden = count($list) - count($keep);
                $list = array_values(array_filter($list, fn($r) => isset($keep[$r['i']])));
            }
            $title = 'Các cơn bão mang tên ' . (function_exists('mb_strtoupper') ? mb_strtoupper($Hh['name']) : strtoupper($Hh['name']));
            $sub = $Hh['name'] . ' hiện tại là cơn thứ ' . $Hh['ordinal'] . ' mang tên này' . (!empty($P['seq']) ? ' · Cơn bão số ' . $P['seq']['n'] . ' năm ' . $P['seq']['year'] . ' trên Tây Bắc Thái Bình Dương' : '') . ' · Nguồn: JMA (Tokyo)';
            $kick = 'LỊCH SỬ TÊN BÃO';
            $cols = [['Năm', 0.12], ['Cường độ', 0.2], ['Gió mạnh nhất', 0.2], ['Thời gian hoạt động', 0.28], ['Trạng thái', 0.2]];
            foreach ($list as $r) {
                $rows[] = [
                    'cls' => !empty($r['cur']) ? 'cur' : (($c['hl'] ?? '') === 'strongest' && $r['i'] === $Hh['strongest'] ? 'hl' : ''),
                    'cells' => [(string)$r['year'], ['chip' => $r['lv'] ?? null], !empty($r['kmh']) ? $r['kmh'] . ' km/h' : '--',
                        !empty($r['cur']) ? ['now' => 'Đang hoạt động'] : ($r['period'] ?: '--'), !empty($r['cur']) ? '--' : (!empty($r['landfall']) ? 'Đổ bộ' : 'Ngoài biển')],
                ];
            }
            if ($hidden) $foot = "... và $hidden cơn bão cùng tên khác";
        } else {
            $R = $P['rank'] ?? null;
            if (!$R) return;
            $MAX = 10; $all = $R['rows']; $ci = -1;
            foreach ($all as $i => $r) if (!empty($r['cur'])) { $ci = $i; break; }
            $list = $all; $more = 0;
            if (count($all) > $MAX) {
                if ($ci < $MAX - 1) { $list = array_slice($all, 0, $MAX); $more = count($all) - $MAX; }
                else { $list = array_merge(array_slice($all, 0, 3), [null], array_slice($all, max(0, $ci - 2), min(count($all), $ci + 3) - max(0, $ci - 2))); $more = count($all) - min(count($all), $ci + 3); }
            }
            $title = 'Bão mạnh nhất Tây Bắc Thái Bình Dương';
            $sub = 'Theo sức gió mạnh nhất (JMA) · ' . ($all[$ci]['name'] ?? '') . ' tính theo bản tin hiện tại';
            $kick = 'XẾP HẠNG CƯỜNG ĐỘ NĂM ' . $R['year'];
            $cols = [['Hạng', 0.14], ['Cơn bão', 0.4], ['Cường độ', 0.22], ['Gió mạnh nhất', 0.24]];
            foreach ($list as $r) {
                if ($r === null) { $rows[] = ['cls' => 'gap', 'cells' => ['...', '', '', '']]; continue; }
                $rows[] = ['cls' => !empty($r['cur']) ? 'cur' : '', 'cells' => [(string)$r['rank'], $r['name'] . (!empty($r['cur']) ? '  (hiện tại)' : ''), ['chip' => $r['lv'] ?? null], !empty($r['kmh']) ? $r['kmh'] . ' km/h' : '--']];
            }
            if ($more > 0) $foot = "... và $more cơn bão yếu hơn";
        }

        $pw = min(66 * $u, $W * 0.92); $pad = 2 * $u; $fh = 1.15 * $u; $rowH = 3.1 * $u;
        $subLines = $this->wrap(1.2 * $u, false, $sub, $pw - 2 * $pad);
        $hH = 1.1 * $u * 1.4 + 2.2 * $u * 1.3 + count($subLines) * 1.2 * $u * 1.4 + 1.2 * $u;
        $ph = 1.6 * $u + $hH + $rowH * 0.8 + count($rows) * $rowH + ($foot ? 2.4 * $u : 0) + 1.3 * $u;
        $px = ($W - $pw) / 2; $py = $H * 0.45 - $ph / 2;
        $this->rect($px, $py, $pw, $ph, $this->col('#060b16', 0.95));
        $this->rect($px, $py, $pw, 0.45 * $u, $accent);
        $y = $py + 1.6 * $u;
        $x = $px + $pad;
        if (!$isHist && !empty($R['rank'])) {
            $badge = $R['rank'] . '';
            $this->tx(4.4 * $u, $x, $y - 0.2 * $u, $white, true, $badge);
            $bw = $this->tw(4.4 * $u, true, $badge);
            $this->tx(1.5 * $u, $x + $bw + 0.3 * $u, $y + 2.6 * $u, $muted, false, '/ ' . $R['total']);
            $x += $bw + $this->tw(1.5 * $u, false, '/ ' . $R['total']) + 2 * $u;
        }
        $this->tx(1.1 * $u, $x, $y, $accent, true, $kick); $y += 1.1 * $u * 1.4;
        $this->tx(2.2 * $u, $x, $y, $white, true, $title); $y += 2.2 * $u * 1.3;
        foreach ($subLines as $l) { $this->tx(1.2 * $u, $x, $y, $muted, false, $l); $y += 1.2 * $u * 1.4; }
        $y = $py + 1.6 * $u + $hH;
        $tw0 = $pw - 2 * $pad; $tx0 = $px + $pad;
        $cx = $tx0;
        foreach ($cols as $cl) { $this->tx($fh, $cx, $y, $muted, true, $cl[0]); $cx += $cl[1] * $tw0; }
        $y += $rowH * 0.8;
        foreach ($rows as $r) {
            if ($r['cls'] === 'cur') $this->rect($tx0 - 0.6 * $u, $y, $tw0 + 1.2 * $u, $rowH, $this->col('#38bdf8', 0.18));
            if ($r['cls'] === 'hl') $this->rect($tx0 - 0.6 * $u, $y, $tw0 + 1.2 * $u, $rowH, $this->col('#f59e0b', 0.22));
            $this->rect($tx0 - 0.6 * $u, $y + $rowH - 1, $tw0 + 1.2 * $u, 1, $this->col('#ffffff', 0.12));
            $cx = $tx0;
            foreach ($r['cells'] as $ci2 => $cell) {
                if (is_array($cell) && array_key_exists('chip', $cell)) $this->chip($cx, $y, $rowH, $cell['chip']);
                elseif (is_array($cell) && isset($cell['now'])) $this->tx($fh, $cx, $y + ($rowH - $fh * 1.25) / 2, $this->col('#38bdf8'), true, $cell['now']);
                else $this->tx($fh * 1.05, $cx, $y + ($rowH - $fh * 1.3) / 2, $r['cls'] === 'gap' ? $muted : $white, $ci2 === 0 || $r['cls'] === 'cur', (string)$cell);
                $cx += $cols[$ci2][1] * $tw0;
            }
            $y += $rowH;
        }
        if ($foot) $this->tx($fh, $tx0, $y + 0.6 * $u, $muted, false, $foot);
    }

    public function thumbJpeg() {
        $t = imagecreatetruecolor(1280, 720);
        imagecopyresampled($t, $this->im, 0, 0, 0, 0, 1280, 720, $this->W, $this->H);
        ob_start(); imagejpeg($t, null, 88); $b = ob_get_clean();
        imagedestroy($t);
        return $b;
    }

    public function jpeg($q = 93) {
        ob_start(); imagejpeg($this->im, null, $q);
        return ob_get_clean();
    }
}

// ----------------------------------------------------------------------------- Job nền
function vbf_job_read($dir) {
    $f = $dir . '/job.json';
    $j = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    return is_array($j) ? $j : [];
}

function vbf_job_write($dir, array $patch) {
    $j = array_merge(vbf_job_read($dir), $patch, ['updated' => time()]);
    $tmp = $dir . '/job.json.tmp';
    @file_put_contents($tmp, json_encode($j, JSON_UNESCAPED_UNICODE), LOCK_EX);
    @rename($tmp, $dir . '/job.json');
}

function vbf_rm_dir($dir) {
    foreach (glob($dir . '/*') ?: [] as $f) { if (is_file($f)) @unlink($f); }
    @rmdir($dir);
}

function vbf_job_dir($id) {
    $id = preg_replace('/[^A-Za-z0-9]/', '', (string)$id);
    if ($id === '') return null;
    $d = vbf_dir() . '/' . $id;
    return is_dir($d) ? $d : null;
}

function vbf_job_view($id, $dir) {
    $j = vbf_job_read($dir);
    if (($j['state'] ?? '') === 'running' && time() - (int)($j['updated'] ?? 0) > 150) $j['state'] = 'stale';
    $j['job'] = $id;
    $j['elapsed'] = time() - (int)($j['created'] ?? time());
    return $j;
}

function vbf_est_dur($text) {
    $w = count(preg_split('/\s+/u', trim((string)$text), -1, PREG_SPLIT_NO_EMPTY));
    return max(2.2, ($w * 0.33 + 0.7));
}

function vbf_fmt($x) { return number_format($x, 3, '.', ''); }

function vbf_run($dir, array $o, array $player, array $env) {
    $ffmpeg = $env['ffmpeg'];
    $W = $o['w']; $H = $o['h']; $fps = $o['fps'];
    $cues = $player['cues'];
    $pts = $player['points'];
    $log = $dir . '/ffmpeg.log';

    // Ngữ cảnh toàn cục
    $allLL = [];
    foreach ($player['past'] as $q) $allLL[] = [$q[0], $q[1]];
    foreach ($pts as $p) if (vbf_hasxy($p)) $allLL[] = [$p['lat'], $p['lon']];
    if (!$allLL) $allLL = [[5, 100], [30, 140]];
    $la = array_column($allLL, 0); $lo = array_column($allLL, 1);
    $sw = [min($la), min($lo)]; $ne = [max($la), max($lo)];
    if (($sw[1] + $ne[1]) / 2 < 130) { $sw = [min($sw[0], 8.2), min($sw[1], 102.1)]; $ne = [max($ne[0], 23.4), max($ne[1], 112.5)]; }
    $wide = vbf_cam_fit($sw[0], $sw[1], $ne[0], $ne[1], 70, 70, 6);
    $area = $player['gale'] ?: $player['storm'];
    $pastPts = [];
    foreach ($player['past'] as $q) $pastPts[] = [$q[0], $q[1], $q[2]];
    if (vbf_hasxy($pts[0] ?? null)) $pastPts[] = [$pts[0]['lat'], $pts[0]['lon'], $pts[0]['lv']];
    $P = [
        'pts' => $pts, 'pastPts' => $pastPts, 'gale' => $player['gale'], 'storm' => $player['storm'],
        'galeMax' => $area ? (!empty($area['uniform']) ? $area['km'] : $area['longKm']) : 0, 'wide' => $wide,
        'history' => $player['history'], 'rank' => $player['rank'], 'seq' => $player['seq'],
        'title' => $o['title'],
    ];

    // Độ dài từng câu (theo file mp3 thật) và kế hoạch thời gian
    vbf_job_write($dir, ['state' => 'running', 'stage' => 'Đo độ dài giọng đọc...', 'progress' => 0.0]);
    $plans = []; $lens = []; $durs = [];
    foreach ($cues as $i => $c) {
        $f = $o['files'][$i] ?? null;
        $d = $f ? vbf_audio_dur($ffmpeg, $f) : null;
        if (!$d) { $d = vbf_est_dur($c['text'] ?? ''); $o['files'][$i] = null; }
        $plan = vbf_plan($c, $d, $P);
        $nextScene = isset($cues[$i + 1]) && ($cues[$i + 1]['scene'] ?? 0) !== ($c['scene'] ?? 0);
        $gap = (($c['act'] ?? '') === 'intro' || $nextScene) ? 0.6 : 0.3;
        $durs[$i] = $d; $plans[$i] = $plan; $lens[$i] = max($d, $plan['total']) + $gap;
    }
    $lead = 1.0; $tail = 4.0;
    $T = $lead + array_sum($lens) + $tail;
    $nFrames = (int)round($T * $fps);

    // Ghép âm thanh: mỗi câu đệm im lặng cho vừa độ dài cue, nối liền theo đúng mốc thời gian của hình
    $audio = $dir . '/audio.m4a';
    $hasAudio = false;
    if (array_filter($o['files'])) {
        vbf_job_write($dir, ['stage' => 'Ghép âm thanh giọng đọc...']);
        $inputs = ''; $fl = []; $labs = []; $idx = 0;
        $fl[] = 'anullsrc=r=48000:cl=stereo,atrim=0:' . vbf_fmt($lead) . ',asetpts=PTS-STARTPTS[l0]';
        $labs[] = '[l0]';
        foreach ($cues as $i => $c) {
            if (!empty($o['files'][$i])) {
                $inputs .= ' -i ' . escapeshellarg($o['files'][$i]);
                $fl[] = "[$idx:a]aresample=48000,aformat=sample_fmts=fltp:channel_layouts=stereo,apad,atrim=0:" . vbf_fmt($lens[$i]) . ",asetpts=PTS-STARTPTS[a$i]";
                $idx++;
            } else {
                $fl[] = 'anullsrc=r=48000:cl=stereo,atrim=0:' . vbf_fmt($lens[$i]) . ",asetpts=PTS-STARTPTS[a$i]";
            }
            $labs[] = "[a$i]";
        }
        $fl[] = 'anullsrc=r=48000:cl=stereo,atrim=0:' . vbf_fmt($tail) . ',asetpts=PTS-STARTPTS[tl]';
        $labs[] = '[tl]';
        $fl[] = implode('', $labs) . 'concat=n=' . count($labs) . ':v=0:a=1[aout]';
        $cmd = escapeshellarg($ffmpeg) . ' -y -hide_banner' . $inputs . ' -filter_complex ' . escapeshellarg(implode(';', $fl))
            . ' -map "[aout]" -c:a aac -b:a 192k -ar 48000 ' . escapeshellarg($audio) . ' 2>&1';
        $out = (string)shell_exec($cmd);
        @file_put_contents($log, "---- audio ----\n" . substr($out, -2500) . "\n", FILE_APPEND);
        $hasAudio = is_file($audio) && filesize($audio) > 1024;
    }

    // Bộ mã hoá
    $enc = (string)@shell_exec(escapeshellarg($ffmpeg) . ' -hide_banner -encoders 2>&1');
    $vargs = strpos($enc, 'libx264') !== false ? ['-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-pix_fmt', 'yuv420p'] : ['-c:v', 'mpeg4', '-q:v', '3', '-pix_fmt', 'yuv420p'];

    $outFile = $dir . '/out.mp4';
    @unlink($outFile);
    $cmd = [$ffmpeg, '-y', '-hide_banner', '-f', 'image2pipe', '-framerate', (string)$fps, '-vcodec', 'mjpeg', '-i', '-'];
    if ($hasAudio) { $cmd[] = '-i'; $cmd[] = $audio; }
    $cmd = array_merge($cmd, ['-map', '0:v:0']);
    if ($hasAudio) $cmd = array_merge($cmd, ['-map', '1:a:0']);
    $cmd = array_merge($cmd, ['-vf', 'scale=in_range=full:out_range=tv,format=yuv420p'], $vargs, ['-r', (string)$fps]);
    if ($hasAudio) $cmd = array_merge($cmd, ['-c:a', 'copy']);
    $cmd = array_merge($cmd, ['-t', vbf_fmt($T), '-movflags', '+faststart', $outFile]);
    $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
    if (!is_resource($proc)) throw new RuntimeException('Không chạy được ffmpeg (proc_open).');

    $R = new VbfRender($W, $H, $o['base'], vbf_fonts(), dirname(__DIR__) . '/vn.json');

    // Dòng thời gian
    $entries = [['type' => 'open', 'start' => 0.0, 'len' => $lead]];
    $t = $lead;
    foreach ($cues as $i => $c) { $entries[] = ['type' => 'cue', 'i' => $i, 'start' => $t, 'len' => $lens[$i]]; $t += $lens[$i]; }
    $entries[] = ['type' => 'end', 'start' => $t, 'len' => $tail];

    $S = vbf_initial_state($P);
    $thumbDone = false; $cancelled = false; $failed = null;
    $t0 = microtime(true);
    $outroCue = ['act' => 'outro', 'text' => '', 'pt' => 0];
    $outroPlan = ['segs' => [['t' => 0.0, 'dur' => 2.0, 'cam' => $wide]], 'total' => 2.0, 'move' => null, 't_after' => 0.0, 'anim' => null];
    $wroteFrames = 0;
    vbf_job_write($dir, ['stage' => 'Đang vẽ khung hình...', 'frames' => $nFrames, 'frame' => 0, 'duration' => $T]);

    foreach ($entries as $ei => $en) {
        $fStart = (int)round($en['start'] * $fps);
        $fEnd = $ei === count($entries) - 1 ? $nFrames : (int)round(($en['start'] + $en['len']) * $fps);
        for ($f = $fStart; $f < $fEnd; $f++) {
            $tau = max(0.0, $f / $fps - $en['start']);
            if ($en['type'] === 'open') {
                $st = ['cam' => $wide, 'moving' => false, 'past' => 0.0, 'fcPath' => [], 'fcHead' => null, 'dots' => [], 'storm' => null, 'radius' => [],
                    'info' => null, 'panel' => null, 'fx' => [], 'tag' => null, 'blink' => false, 'subtitle' => ''];
                if (vbf_hasxy($pts[0] ?? null)) { vbf_ens_st($st, $pts[0]); }
            } elseif ($en['type'] === 'cue') {
                $st = vbf_assemble($S, $cues[$en['i']], $plans[$en['i']], $tau, $P);
            } else {
                $st = vbf_assemble($S, $outroCue, $outroPlan, $tau, $P);
                $st['panel'] = null;
            }
            $R->frame($st, $P, $f / $fps);
            if ($en['type'] === 'end' && !$thumbDone && $tau >= 2.8) {
                @file_put_contents($dir . '/thumb.jpg', $R->thumbJpeg());
                $thumbDone = true;
            }
            $jpg = $R->jpeg(93);
            if ($jpg === '' || $jpg === false) { $failed = 'PHP GD không xuất được ảnh JPEG ở khung hình ' . $f . ' (kiểm tra php-gd có hỗ trợ JPEG).'; break 2; }
            $w = @fwrite($pipes[0], $jpg);
            if ($w === false || $w === 0) { $failed = 'ffmpeg đã dừng đột ngột khi nhận khung hình ' . $f . '.'; break 2; }
            $wroteFrames++;
            if ($f % 10 === 0) {
                $el = microtime(true) - $t0;
                $done = $f + 1;
                vbf_job_write($dir, ['frame' => $done, 'progress' => round($done / $nFrames, 4), 'eta' => $done > 20 ? (int)round($el / $done * ($nFrames - $done)) : null,
                    'cue' => $en['type'] === 'cue' ? $en['i'] + 1 : null]);
                if (is_file($dir . '/cancel')) { $cancelled = true; break 2; }
            }
        }
        if ($en['type'] === 'cue') vbf_finish($S, $cues[$en['i']], $plans[$en['i']], $P);
    }
    if (!$thumbDone && $wroteFrames) @file_put_contents($dir . '/thumb.jpg', $R->thumbJpeg());
    @fclose($pipes[0]);
    $code = proc_close($proc);

    if ($cancelled) { @unlink($outFile); vbf_job_write($dir, ['state' => 'cancelled', 'stage' => 'Đã huỷ.']); return; }
    clearstatcache(true, $outFile);
    $info = (string)@shell_exec(escapeshellarg($ffmpeg) . ' -hide_banner -i ' . escapeshellarg($outFile) . ' 2>&1');
    if ($failed || !is_file($outFile) || filesize($outFile) < 4096 || strpos($info, 'Video:') === false) {
        $tail = is_file($log) ? substr((string)file_get_contents($log), -1800) : '';
        vbf_job_write($dir, ['state' => 'error', 'stage' => 'Lỗi', 'error' => ($failed ?: 'ffmpeg không tạo được video hợp lệ (mã ' . $code . ').'), 'log' => $tail]);
        return;
    }
    vbf_job_write($dir, ['state' => 'done', 'stage' => 'Hoàn tất', 'progress' => 1.0, 'frame' => $nFrames, 'size' => filesize($outFile), 'secs' => $T,
        'cueStarts' => array_map(fn($e) => round($e['start'], 2), array_values(array_filter($entries, fn($e) => $e['type'] === 'cue'))),
        'chapters' => vb_yt_chapters($cues, array_map(fn($e) => $e['start'], array_values(array_filter($entries, fn($e) => $e['type'] === 'cue'))), $pts),
        'noAudio' => !$hasAudio, 'hasThumb' => is_file($dir . '/thumb.jpg'), 'w' => $W, 'h' => $H, 'fps' => $fps]);
}

function vbf_json($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function vbf_handle($player, $selected) {
    $ff = (string)$_REQUEST['ff'];
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

    if ($ff === 'env') { $e = vbf_env(); vbf_json(['ok' => $e['ok'], 'errors' => $e['errors'], 'font' => $e['font'] ? basename($e['font']) : null]); }

    if ($ff === 'get') {
        $dir = vbf_job_dir($_REQUEST['job'] ?? '');
        $name = basename((string)($_REQUEST['file'] ?? 'out.mp4'));
        if (!$dir || !in_array($name, ['out.mp4', 'thumb.jpg'], true) || !is_file($dir . '/' . $name)) { http_response_code(404); exit('not found'); }
        $path = $dir . '/' . $name;
        header('Content-Type: ' . ($name === 'out.mp4' ? 'video/mp4' : 'image/jpeg'));
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-store');
        readfile($path);
        exit;
    }

    if ($ff === 'status') {
        $dir = vbf_job_dir($_REQUEST['job'] ?? '');
        if (!$dir) vbf_json(['ok' => false, 'error' => 'Không tìm thấy job'], 404);
        vbf_json(['ok' => true] + vbf_job_view(basename($dir), $dir));
    }

    if ($ff === 'latest') {
        $best = null; $bestT = 0;
        foreach (glob(vbf_dir() . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            $j = vbf_job_read($d);
            if (($j['storm'] ?? '') !== (string)($selected['id'] ?? '') || empty($j['created'])) continue;
            if (($j['state'] ?? '') === 'cancelled') continue;
            if ($j['created'] > $bestT) { $bestT = $j['created']; $best = $d; }
        }
        if (!$best) vbf_json(['ok' => true, 'none' => true]);
        vbf_json(['ok' => true] + vbf_job_view(basename($best), $best));
    }

    if (!$isPost) vbf_json(['ok' => false, 'error' => 'Phải dùng POST'], 405);

    if ($ff === 'cancel') {
        $dir = vbf_job_dir($_POST['job'] ?? '');
        if (!$dir) vbf_json(['ok' => false, 'error' => 'Không tìm thấy job'], 404);
        @touch($dir . '/cancel');
        $v = vbf_job_view(basename($dir), $dir);
        if (in_array($v['state'] ?? '', ['stale', 'queued'], true)) vbf_job_write($dir, ['state' => 'cancelled', 'stage' => 'Đã huỷ.']);
        vbf_json(['ok' => true]);
    }

    if ($ff === 'cleanup') {
        $dir = vbf_job_dir($_POST['job'] ?? '');
        if ($dir) vbf_rm_dir($dir);
        vbf_json(['ok' => true]);
    }

    if ($ff === 'start') {
        if (!$player || !$selected) vbf_json(['ok' => false, 'error' => 'Chưa chọn cơn bão có dữ liệu.'], 400);
        $env = vbf_env();
        if (!$env['ok']) vbf_json(['ok' => false, 'error' => implode(' ', $env['errors'])], 500);

        $res = [720 => [1280, 720], 1080 => [1920, 1080], 1440 => [2560, 1440], 2160 => [3840, 2160]];
        [$W, $H] = $res[(int)($_POST['res'] ?? 1080)] ?? $res[1080];
        $fps = max(12, min(60, (int)($_POST['fps'] ?? 30)));
        $base = in_array($_POST['base'] ?? 'sat', VBF_BASES, true) ? $_POST['base'] : 'sat';
        $tts = json_decode((string)($_POST['tts'] ?? '[]'), true);
        $files = [];
        foreach ($player['cues'] as $i => $_) {
            $u = is_array($tts) ? (string)($tts[$i] ?? '') : '';
            $files[$i] = (preg_match('~tts_cache/([a-f0-9]{40})\.mp3~', $u, $m) && is_file(vb_tts_file($m[1]))) ? vb_tts_file($m[1]) : null;
        }

        // Dọn job cũ (> 6 giờ)
        foreach (glob(vbf_dir() . '/*', GLOB_ONLYDIR) ?: [] as $d) if (@filemtime($d) < time() - 21600) vbf_rm_dir($d);

        $id = date('YmdHis') . bin2hex(random_bytes(3));
        $dir = vbf_dir() . '/' . $id;
        if (!@mkdir($dir, 0755, true)) vbf_json(['ok' => false, 'error' => 'Không tạo được thư mục job.'], 500);
        $title = $player['name'] ?: ($player['no'] ? 'Số hiệu ' . $player['no'] : 'Xoáy thuận nhiệt đới');
        vbf_job_write($dir, ['state' => 'queued', 'stage' => 'Đang khởi động...', 'storm' => (string)$selected['id'], 'created' => time(), 'w' => $W, 'h' => $H, 'fps' => $fps,
            'progress' => 0.0, 'voiced' => count(array_filter($files)), 'cues' => count($files)]);

        // Trả lời trình duyệt ngay, phần d���ng chạy tiếp ở nền.
        ignore_user_abort(true);
        @set_time_limit(getenv('GITHUB_ACTIONS') === 'true' ? 7200 : 0);
        @ini_set('memory_limit', '1536M');
        $body = json_encode(['ok' => true, 'job' => $id], JSON_UNESCAPED_UNICODE);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('Connection: close');
        header('Content-Length: ' . strlen($body));
        echo $body;
        while (ob_get_level() > 0) @ob_end_flush();
        flush();
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

        register_shutdown_function(function () use ($dir) {
            $j = vbf_job_read($dir);
            if (in_array($j['state'] ?? '', ['running', 'queued'], true)) {
                $e = error_get_last();
                vbf_job_write($dir, ['state' => 'error', 'stage' => 'Lỗi', 'error' => 'Tiến trình dựng bị dừng đột ngột' . ($e ? ': ' . $e['message'] : ' (hết bộ nhớ hoặc thời gian chạy).')]);
            }
        });
        try {
            vbf_run($dir, ['w' => $W, 'h' => $H, 'fps' => $fps, 'base' => $base, 'files' => $files, 'title' => $title], $player, $env);
        } catch (Throwable $e) {
            vbf_job_write($dir, ['state' => 'error', 'stage' => 'Lỗi', 'error' => $e->getMessage()]);
        }
        exit;
    }

    vbf_json(['ok' => false, 'error' => 'Hành động ff không hợp lệ'], 400);
}

if (isset($_REQUEST['ff'])) {
    vbf_handle($player, $selected);
    exit;
}

if (($_GET['api'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!$selected) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'Không tìm thấy cơn bão'], JSON_UNESCAPED_UNICODE); exit; }
    if (!$bulletin) { echo json_encode(['ok' => false, 'error' => 'Không có dữ liệu thời điểm hiện tại'], JSON_UNESCAPED_UNICODE); exit; }
    echo json_encode([
        'ok'         => true,
        'storm'      => ['id' => $selected['id'], 'name' => $player['name'], 'no' => $selected['no']],
        'paragraphs' => $bulletin['paragraphs'],
        'text'       => implode("\n\n", $bulletin['paragraphs']),
        'player'     => $player,
        'meta'       => vb_yt_meta($bulletin, $player),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function vb_storm_label($s) {
    $name = vb_name($s['name']);
    $an = null;
    foreach ($s['spec'] as $it) if (is_array($it) && vb_is_analysis($it)) { $an = vb_point($it); break; }
    $cls = $an ? $an['cls'] : 'bão';
    $lv = $an && $an['lv'] !== null ? ' - cấp ' . $an['lv'] : '';
    return vb_ucfirst(($cls === 'tan' ? 'bão' : $cls) . ($name ? ' ' . $name : '')) . ($s['no'] ? ' (' . $s['no'] . ')' : '') . $lv;
}

$fullText = $bulletin ? implode("\n\n", $bulletin['paragraphs']) : '';
$titleName = $player ? ($player['name'] ?: ($player['no'] ? 'Số hiệu ' . $player['no'] : '')) : '';

// ----------------------------------------------------------------------------- Tiêu đề / mô tả / tag YouTube (chuẩn SEO)
// YouTube: tiêu đề <= 100 ký tự, mô tả <= 5000 byte, tổng tag <= 500 ký tự, không được chứa dấu < >.
function vb_yt_clean($s) {
    return str_replace(['<', '>'], ['‹', '›'], (string)$s);
}

function vb_yt_meta(array $bulletin, array $player) {
    $name = $player['name'];
    $no = (string)$player['no'];
    $cur = $bulletin['points'][0] ?? null;
    $cls = $cur ? $cur['cls'] : 'bão';
    $clsWord = $cls === 'tan' ? 'bão' : $cls;
    $label = $name !== '' ? $name : ($no !== '' ? 'số hiệu ' . $no : '');
    $subject = vb_ucfirst(trim($clsWord . ' ' . $label));
    $lvTxt = ($cur && $cur['lv'] !== null && $cls !== 'tan') ? ' cấp ' . (int)$cur['lv'] : '';
    $d = $cur && $cur['ts'] ? vb_vn_date($cur['ts']) : new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh'));
    $when = (int)$d->format('G') . 'h ngày ' . $d->format('d/m');
    $year = $d->format('Y');

    // Điểm nhấn địa lý (đưa lên tiêu đề để tăng CTR và khớp truy vấn "bão đổ bộ ...", "bão vào Biển Đông").
    $geo = $bulletin['geo'] ?? [];
    $min = $geo['min'] ?? null;
    $hook = ''; $hookDesc = ''; $hookProv = '';
    if (!empty($geo['nowOnLand'])) {
        $hookProv = vb_prov_short($geo['nowOnLand']['prov']);
        $hook = ' đang trên đất liền ' . $hookProv;
        $hookDesc = 'tâm đang nằm trên đất liền ' . vb_prov_phrase($geo['nowOnLand']['prov'], $geo['nowOnLand']['type']);
    } elseif (!empty($geo['landfall'])) {
        $hookProv = vb_prov_short($geo['landfall']['prov']);
        $hook = ' dự báo đổ bộ ' . $hookProv;
        $hookDesc = 'dự báo đi vào đất liền ' . vb_prov_phrase($geo['landfall']['prov'], $geo['landfall']['type'])
            . (!empty($geo['landfall']['ts']) ? ' khoảng ' . vb_time_short($geo['landfall']['ts']) : '');
    } elseif (!empty($geo['fcSCS']) && empty($geo['nowSCS'])) {
        $hook = ' sắp vào Biển Đông';
        $hookDesc = 'dự báo sẽ đi vào Biển Đông';
    } elseif ($min && $min['k'] === 0 && $min['km'] <= 500) {
        $hookProv = vb_prov_short($min['prov']);
        $hook = ' cách ' . $hookProv . ' ' . vb_km_text($min['km']) . ' km';
        $hookDesc = 'cách đất liền ' . vb_prov_phrase($min['prov'], $min['type']) . ' khoảng ' . vb_km_text($min['km']) . ' km';
    } elseif (!empty($geo['nowSCS'])) {
        $hook = ' trên Biển Đông';
        $hookDesc = 'đang hoạt động trên Biển Đông';
    }
    $inSCS = !empty($geo['nowSCS']) || !empty($geo['fcSCS']);

    // Từ khóa chính ("Bão X") đứng đầu tiêu đề; thời điểm cập nhật ở cuối.
    $title = '';
    foreach (array_unique([
        "{$subject}{$lvTxt}{$hook} - Tin bão mới nhất, dự báo đường đi ({$when})",
        "{$subject}{$lvTxt}{$hook} - Tin bão mới nhất ({$when})",
        "{$subject}{$lvTxt}{$hook} - Dự báo đường đi mới nhất",
        "{$subject}{$lvTxt} - Tin bão mới nhất, dự báo đường đi ({$when})",
        "{$subject}{$lvTxt} - Dự báo đường đi mới nhất",
    ]) as $t) {
        if (mb_strlen($t) <= 100) { $title = $t; break; }
    }
    if ($title === '') $title = mb_substr("{$subject}{$lvTxt} - Dự báo đường đi mới nhất", 0, 100);

    $tagName = preg_replace('/[^\p{L}\p{N}]+/u', '', vb_ucfirst($name !== '' ? $name : $no));
    $paras = $bulletin['paragraphs'];
    if (count($paras) > 1) array_pop($paras); // bỏ đoạn chào tạm biệt
    $body = '';
    foreach ($paras as $p) {
        if (mb_strlen($body) + mb_strlen($p) > 2600) break;
        $body .= ($body === '' ? '' : "\n\n") . $p;
    }

    // Dòng đầu (~150 ký tự đầu hiện trên kết quả tìm kiếm): tóm tắt số liệu chính.
    $facts = [];
    if ($cur && $cls !== 'tan') {
        if ($cur['lv'] !== null) $facts[] = 'gió mạnh nhất cấp ' . (int)$cur['lv'];
        if ($g = vb_gust_text($cur['gust'])) $facts[] = 'giật ' . $g;
        if ($cur['pres'] !== null) $facts[] = 'áp suất ' . $cur['pres'] . ' hPa';
        $mp = $cur['mp'] ?? null;
        if ($mp && !$mp['stationary'] && ($mp['dir'] || $mp['kmh'])) {
            $facts[] = 'di chuyển' . ($mp['dir'] ? ' hướng ' . $mp['dir'] : '') . ($mp['kmh'] ? ' ' . $mp['kmh'] . ' km/h' : '');
        } elseif ($mp && $mp['stationary']) {
            $facts[] = 'gần như đứng yên';
        }
    }
    if ($hookDesc !== '') $facts[] = $hookDesc;
    $lead = $subject . ($no !== '' && $name !== '' ? ' (số hiệu ' . $no . ')' : '')
        . ($cur && $cur['ts'] ? ' lúc ' . vb_time_short($cur['ts']) : '')
        . ($facts ? ': ' . implode(', ', $facts) : '') . '.';

    $enKind = in_array($cls, ['bão', 'siêu bão'], true) ? 'Typhoon' : ($cls === 'áp thấp nhiệt đới' ? 'Tropical Depression' : 'Tropical Cyclone');
    $enName = $name !== '' ? mb_strtoupper($name) : $no;
    $en = trim("{$enKind} {$enName}") . ($no !== '' && $name !== '' ? " ({$no})" : '')
        . ' - latest track, intensity and forecast from JMA, updated ' . $d->format('Y-m-d H:i') . ' (UTC+7).';

    $hashtags = array_filter([
        $tagName !== '' ? '#Bão' . $tagName : null,
        '#TinBão',
        $inSCS ? '#BiểnĐông' : '#DựBáoThờiTiết',
        $name !== '' ? '#Typhoon' . $tagName : null,
    ]);
    $lines = [
        $lead,
        'Cập nhật vị trí tâm bão, sức gió, áp suất, vùng gió mạnh và dự báo đường đi mới nhất (giờ Việt Nam).',
        'Chi tiết về cơn bão ' . $label . ' có thể xem tại: https://nangmua.vn/ty',
        '',
        'NỘI DUNG BẢN TIN:',
        $body,
        '',
        'Nguồn dữ liệu: Cơ quan Khí tượng Nhật Bản (JMA). Thời gian trong video là giờ Việt Nam.',
        'Theo dõi kênh để cập nhật nhanh nhất các bản tin bão, áp thấp nhiệt đới và dự báo thời tiết.',
        $en,
        '',
        implode(' ', $hashtags),
    ];
    $description = trim(implode("\n", $lines));
    while (strlen($description) > 4900) $description = mb_substr($description, 0, mb_strlen($description) - 200);

    // Tag: cụ thể nhất trước (YouTube ưu tiên các tag đầu), chung chung sau.
    $raw = [];
    if ($name !== '') {
        array_push($raw, "bão {$name}", $name, "typhoon {$name}", "tin bão {$name}", "bão {$name} mới nhất", "đường đi bão {$name}", "dự báo bão {$name}", "bão {$name} {$year}", "typhoon {$name} {$year}", "{$name} typhoon track");
        if ($cls === 'siêu bão') $raw[] = "siêu bão {$name}";
        if ($no !== '') $raw[] = "bão số hiệu {$no}";
    } elseif ($no !== '') {
        array_push($raw, "bão {$no}", "tin bão {$no}", "bão số hiệu {$no}");
    }
    if ($hookProv !== '') {
        $raw[] = "bão {$hookProv}";
        if (!empty($geo['nowOnLand']) || !empty($geo['landfall'])) $raw[] = "bão đổ bộ {$hookProv}";
    }
    if (!empty($geo['nowOnLand']) || !empty($geo['landfall'])) $raw[] = 'bão đổ bộ';
    if ($inSCS) array_push($raw, 'bão biển Đông', 'biển Đông');
    if ($cls === 'siêu bão') array_push($raw, 'siêu bão', "siêu bão {$year}");
    if ($cls === 'áp thấp nhiệt đới') array_push($raw, 'áp thấp nhiệt đới', 'áp thấp nhiệt đới mới nhất');
    array_push($raw, 'tin bão', 'tin bão mới nhất', 'tin bão hôm nay', 'bản tin bão', 'dự báo bão', 'đường đi của bão', 'bão mới nhất hôm nay', "bão {$year}", 'dự báo thời tiết', 'thời tiết hôm nay', 'áp thấp nhiệt đới', 'biển Đông', 'JMA', 'nangmua');
    $tags = []; $total = 0; $seen = [];
    foreach ($raw as $t) {
        $t = trim(vb_yt_clean($t));
        $k = mb_strtolower($t);
        if ($t === '' || isset($seen[$k])) continue;
        $cost = mb_strlen($t) + (strpos($t, ' ') !== false ? 2 : 0) + 1;
        if ($total + $cost > 480) break;
        $seen[$k] = true; $tags[] = $t; $total += $cost;
    }

    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', 'ban-tin-bao-' . ($name !== '' ? $name : $no) . '-' . $d->format('Ymd-Hi')) ?: 'ban-tin-bao')), '-');
    return [
        'title'       => vb_yt_clean($title),
        'description' => vb_yt_clean($description),
        'tags'        => $tags,
        'file'        => $slug ?: 'ban-tin-bao',
    ];
}

// Mốc thời gian (chapters) cho mô tả YouTube. $starts[i] = giây bắt đầu của cue i trong video.
// YouTube: mốc đầu phải là 0:00, tối thiểu 3 mốc, mỗi mốc cách nhau >= 10 giây.
function vb_yt_chapters(array $cues, array $starts, array $pts) {
    if (!$cues || count($starts) !== count($cues)) return [];
    $groups = [];
    foreach ($cues as $i => $c) {
        $key = $c['scene'] ?? $i;
        $n = count($groups);
        if ($n && $groups[$n - 1]['scene'] === $key) $groups[$n - 1]['cues'][] = $c;
        else $groups[] = ['scene' => $key, 'cues' => [$c], 't' => (float)$starts[$i]];
    }
    $out = []; $isCur = false;
    foreach ($groups as $k => $g) {
        $acts = array_column($g['cues'], 'act');
        $pt = (int)($g['cues'][0]['pt'] ?? 0);
        $land = null;
        foreach ($g['cues'] as $c) if (($c['act'] ?? '') === 'land') { $land = $c; break; }
        if (in_array('greet', $acts, true)) $title = 'Mở đầu';
        elseif (in_array('outro', $acts, true)) $title = 'Khuyến cáo và lời kết';
        elseif (in_array('history', $acts, true)) $title = 'Lịch sử tên bão';
        elseif (in_array('rank', $acts, true)) $title = 'Xếp hạng cường độ trong năm';
        elseif ($pt > 0) {
            $title = 'Dự báo ' . (!empty($pts[$pt]['time']) ? $pts[$pt]['time'] : 'tiếp theo');
            if (!empty($land['land']['onLand']) && !empty($land['land']['provShort'])) $title .= ' - trên đất liền ' . $land['land']['provShort'];
        } elseif (in_array('position', $acts, true) || in_array('dissipate', $acts, true)) { $isCur = true; $title = 'Vị trí và cường độ hiện tại'; }
        else $title = $isCur ? 'Diễn biến bão và hướng di chuyển' : 'Thông tin nổi bật';
        $t = $k === 0 ? 0.0 : $g['t'];
        $prev = $out ? $out[count($out) - 1] : null;
        if ($prev && $t - $prev['t'] < 10) continue;
        $out[] = ['t' => round($t, 2), 'title' => $title];
    }
    return count($out) >= 3 ? $out : [];
}

function vb_yt_stamp($sec) {
    $sec = max(0, (int)floor($sec));
    $h = intdiv($sec, 3600); $m = intdiv($sec % 3600, 60); $s = $sec % 60;
    return $h ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
}

// Chèn khối MỐC THỜI GIAN trước mục NỘI DUNG BẢN TIN, rồi cắt bớt nội dung nếu mô tả vượt giới hạn YouTube.
function vb_yt_apply_chapters($description, array $chapters) {
    if (!$chapters) return $description;
    $head = 'MỐC THỜI GIAN:';
    $block = $head . "\n" . implode("\n", array_map(fn($c) => vb_yt_stamp($c['t']) . ' ' . $c['title'], $chapters));
    $description = preg_replace('/\n*' . preg_quote($head, '/') . '\n(?:\d+:\d\d(?::\d\d)? [^\n]*\n?)+/u', "\n", (string)$description);
    $marker = 'NỘI DUNG BẢN TIN:';
    $d = strpos($description, $marker) !== false
        ? str_replace($marker, $block . "\n\n" . $marker, $description)
        : rtrim($description) . "\n\n" . $block;
    while (strlen($d) > 4950) {
        $i = strpos($d, "\n\n" . $marker);
        $j = strpos($d, "\n\nNguồn dữ liệu:");
        if ($i === false || $j === false || $j <= $i) break;
        $body = substr($d, $i, $j - $i);
        $cut = strrpos($body, "\n\n");
        if ($cut === false || $cut <= strlen($marker) + 2) break;
        $d = substr($d, 0, $i) . substr($body, 0, $cut) . substr($d, $j);
    }
    return $d;
}

$ytMeta = $player ? vb_yt_meta($bulletin, $player) : null;
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0b1220">
<title>Bản tin bão cho video<?= $titleName ? ' - ' . vb_e($titleName) : '' ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>
    :root { --bg:#0b1220; --card:#121a2b; --line:#24304a; --text:#e6ebf5; --muted:#93a0b8; --accent:#38bdf8; --warn:#f59e0b; --danger:#ef4444; }
    * { box-sizing: border-box; }
    body { margin:0; font-family: "Be Vietnam Pro", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background:var(--bg); color:var(--text); line-height:1.6; }
    main { max-width: 1120px; margin: 0 auto; padding: 24px 16px 48px; }
    h1 { font-size: 1.4rem; margin: 0 0 4px; }
    h2 { font-size: 1.05rem; margin: 0 0 12px; }
    .sub { color: var(--muted); margin: 0 0 20px; font-size: .95rem; }
    .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 18px; margin-bottom: 16px; }
    label { display:block; font-weight:600; margin-bottom:8px; }
    .row { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
    select { padding:9px 12px; border-radius:8px; border:1px solid var(--line); background:#0e1626; color:var(--text); font: inherit; font-size:.95rem; }
    #storm { flex:1; min-width:240px; }
    button, .btn { padding:9px 14px; border-radius:8px; border:0; background:var(--accent); color:#04111d; font: inherit; font-weight:700; cursor:pointer; font-size:.92rem; text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
    .btn.secondary, button.secondary { background:transparent; color:var(--text); border:1px solid var(--line); }
    button:focus-visible, select:focus-visible, .btn:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
    textarea { width:100%; min-height:200px; padding:12px; border-radius:8px; border:1px solid var(--line); background:#0e1626; color:var(--text); font: inherit; font-size: .95rem; resize: vertical; }
    table { width:100%; border-collapse: collapse; font-size:.86rem; }
    th, td { border-bottom:1px solid var(--line); padding:7px 6px; text-align:left; vertical-align: top; }
    th { color: var(--muted); font-weight:600; }
    .alert { border-left:4px solid var(--warn); padding:10px 14px; background:#1e1a10; border-radius:6px; }
    .muted { color: var(--muted); font-size: .86rem; }
    .actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:12px; }

    /* ---------- Sân kh��u video 16:9 ---------- */
    .stage { position:relative; width:100%; aspect-ratio:16/9; border-radius:10px; overflow:hidden; background:#05080f; container-type: inline-size; }
    .stage:fullscreen { border-radius:0; aspect-ratio:auto; width:100vw; height:100vh; }
    #map { position:absolute; inset:0; background:#0a1324; }
    .ov { position:absolute; z-index:500; pointer-events:none; }
    .brand { top:3cqw; left:3cqw; background:rgba(5,10,20,.82); border-left:.5cqw solid var(--danger); padding:1cqw 1.6cqw; border-radius:.6cqw; }
    .brand small { display:block; font-size:1.25cqw; letter-spacing:.2em; color:#fca5a5; font-weight:700; }
    .brand strong { display:block; font-size:2.4cqw; line-height:1.2; font-weight:800; }
    .info { top:3cqw; right:3cqw; background:rgba(5,10,20,.82); padding:1.1cqw 1.6cqw; border-radius:.6cqw; min-width:20cqw; font-size:1.35cqw; line-height:1.5; transition: opacity .3s; }
    .info .lbl { color:var(--muted); }
    .info .cls { font-size:1.9cqw; font-weight:800; color:var(--c, #fff); }
    .info:empty { opacity:0; }
    .subtitle { left:6%; right:6%; bottom:4.5cqw; text-align:center; }
    .subtitle span { display:inline; background:rgba(3,6,14,.86); color:#fff; font-size:2.25cqw; font-weight:600; line-height:1.7; padding:.35cqw 1cqw; border-radius:.4cqw; box-decoration-break:clone; -webkit-box-decoration-break:clone; }
    .subtitle span:empty { display:none; }
    .legend { left:3cqw; top:11cqw; display:flex; flex-direction:column; gap:.4cqw; font-size:1.1cqw; background:rgba(5,10,20,.7); padding:.7cqw 1cqw; border-radius:.5cqw; }
    .legend i { display:inline-block; width:1.2cqw; height:1.2cqw; border-radius:50%; margin-right:.6cqw; vertical-align:middle; }
    .src { right:3cqw; bottom:1cqw; font-size:1cqw; color:#cbd5e1; opacity:.8; }
    .stage .leaflet-control-attribution { font-size:9px; background:rgba(0,0,0,.4); color:#94a3b8; }

    /* ---------- Màn hình thông tin (lịch sử tên bão / xếp hạng năm) ---------- */
    #map { transition: filter .5s ease; }
    .stage.dim #map { filter: brightness(.45) saturate(.8); }
    .panel { left:50%; top:45%; transform:translate(-50%,-50%); width:min(66cqw, 92%); z-index:600; background:rgba(6,11,22,.95); border:1px solid rgba(255,255,255,.12); border-top:.45cqw solid var(--accent); border-radius:1cqw; padding:1.6cqw 2cqw 1.3cqw; box-shadow:0 2cqw 5cqw rgba(0,0,0,.55); }
    .panel[hidden] { display:none; }
    .panel.in { animation: pnIn .55s cubic-bezier(.2,.8,.2,1); }
    @keyframes pnIn { from { opacity:0; transform:translate(-50%,-46%) scale(.96); } to { opacity:1; transform:translate(-50%,-50%) scale(1); } }
    .pn-h { display:flex; align-items:center; gap:1.4cqw; }
    .pn-h small { display:block; font-size:1.1cqw; letter-spacing:.2em; color:var(--accent); font-weight:700; }
    .pn-h strong { display:block; font-size:2.2cqw; line-height:1.25; font-weight:800; }
    .pn-h span { display:block; font-size:1.15cqw; color:var(--muted); margin-top:.2cqw; }
    .pn-badge { flex:none; width:7.2cqw; height:7.2cqw; border-radius:50%; background:var(--accent); color:#04111d; display:flex; flex-direction:column; align-items:center; justify-content:center; line-height:1; }
    .pn-badge b { font-size:3.1cqw; font-weight:800; }
    .pn-badge i { font-style:normal; font-size:1.15cqw; font-weight:700; margin-top:.2cqw; }
    .panel .pn-t { width:100%; border-collapse:collapse; margin-top:1cqw; font-size:1.35cqw; }
    .panel .pn-t th, .panel .pn-t td { padding:.42cqw .7cqw; border-bottom:1px solid rgba(255,255,255,.08); text-align:left; vertical-align:middle; }
    .panel .pn-t th { color:var(--muted); font-weight:600; font-size:1.1cqw; }
    .panel .pn-t tr.cur td { background:rgba(56,189,248,.16); font-weight:700; }
    .panel .pn-t tr.cur td:first-child { box-shadow: inset .35cqw 0 0 var(--accent); }
    .panel .pn-t tr.hl td { background:rgba(245,158,11,.2); font-weight:700; }
    .panel .pn-t tr.hl td:first-child { box-shadow: inset .35cqw 0 0 var(--warn); }
    .panel .pn-t tr.gap td { text-align:center; color:var(--muted); padding:.1cqw; }
    .pn-chip { display:inline-block; background:var(--c); color:var(--tc); font-weight:800; padding:.1cqw .7cqw; border-radius:.4cqw; font-size:1.2cqw; }
    .pn-now { color:var(--accent); font-weight:700; }
    .pn-foot { margin-top:.6cqw; font-size:1.05cqw; color:var(--muted); }
    @media (prefers-reduced-motion: reduce) { .panel.in { animation:none; } #map { transition:none; } }
    .stage .leaflet-control-attribution a { color:#cbd5e1; }

    /* ---------- Hiệu ứng bản đồ ---------- */
    .vb-storm { background:none; border:0; }
    .vb-storm-in { width:46px; height:46px; color:var(--c); filter: drop-shadow(0 0 1.5px #000) drop-shadow(0 0 8px rgba(0,0,0,.7)); animation: vbSpin 2.2s linear infinite; transition: opacity .8s; }
    .vb-storm.blink .vb-storm-in { animation: vbSpin 2.2s linear infinite, vbBlink .55s ease-in-out infinite; }
    .vb-storm.fade .vb-storm-in { opacity:.25; animation: vbSpin 5s linear infinite; }
    @keyframes vbSpin { to { transform: rotate(-360deg); } }
    @keyframes vbBlink { 0%,100% { opacity:1; } 50% { opacity:.15; } }
    .vb-pulse-wrap { background:none; border:0; }
    .vb-pulse { position:absolute; left:-46px; top:-46px; width:92px; height:92px; border-radius:50%; border:3px solid #fff; box-shadow:0 0 12px rgba(255,255,255,.7); animation: vbPulse 1.3s ease-out infinite; }
    .vb-pulse.d2 { animation-delay:.65s; }
    @keyframes vbPulse { 0% { transform:scale(.25); opacity:1; } 100% { transform:scale(1.7); opacity:0; } }
    .vb-wind { position:absolute; left:0; top:0; transform: translate(-50%, calc(-100% - 34px)); }
    .vb-wind-in { transform-origin: 50% 100%; animation: vbWindPop 1.5s cubic-bezier(.2,.8,.2,1) forwards; background:rgba(5,10,20,.9); border:2px solid var(--c); border-radius:10px; padding:6px 14px; text-align:center; white-space:nowrap; color:#fff; font-family:"Be Vietnam Pro",sans-serif; box-shadow:0 6px 24px rgba(0,0,0,.5); }
    .vb-pres { position:absolute; left:0; top:0; width:0; height:0; }
    .vb-pres-ring { position:absolute; left:-60px; top:-60px; width:120px; height:120px; border-radius:50%; border:2.5px solid #38bdf8; opacity:0; animation: vbPresRing 1.5s ease-in infinite; }
    .vb-pres-dot { position:absolute; left:-6px; top:-6px; width:12px; height:12px; border-radius:50%; background:#38bdf8; box-shadow:0 0 12px #38bdf8; }
    .vb-pres-box { position:absolute; left:34px; top:0; transform:translateY(-50%); background:rgba(5,10,20,.9); border-left:5px solid #38bdf8; padding:6px 14px; white-space:nowrap; color:#fff; animation: vbFadeIn .4s ease-out forwards; }
    .vb-pres-box small { display:block; font-size:12px; color:#cbd5e1; }
    .vb-pres-box b { display:block; font-size:22px; line-height:1.15; font-weight:800; }
    @keyframes vbPresRing { 0% { transform:scale(1); opacity:0; } 20% { opacity:.85; } 100% { transform:scale(.18); opacity:0; } }
    .vb-shift { position:absolute; left:0; top:0; width:0; height:0; --c:#a78bfa; }
    .vb-shift-arrow { position:absolute; left:-2.5px; top:0; width:5px; height:var(--l1); background:var(--c); border-radius:3px; transform-origin:50% 0; transform:rotate(calc(var(--a1) + 180deg)); animation: vbShiftTurn 1.4s ease-in-out both; }
    .vb-shift-arrow::after { content:''; position:absolute; left:-7px; bottom:-10px; border-left:9.5px solid transparent; border-right:9.5px solid transparent; border-top:16px solid var(--c); }
    .vb-shift-ring { position:absolute; left:-34px; top:-34px; width:68px; height:68px; border-radius:50%; border:2.5px solid var(--c); opacity:0; animation: vbShiftRing 1.2s ease-out infinite; }
    .vb-shift-dot { position:absolute; left:-6px; top:-6px; width:12px; height:12px; border-radius:50%; background:var(--c); box-shadow:0 0 12px var(--c); }
    .vb-shift-box { position:absolute; left:34px; top:0; transform:translateY(-50%); background:rgba(5,10,20,.9); border-left:5px solid var(--c); padding:6px 14px; white-space:nowrap; color:#fff; animation: vbFadeIn .4s ease-out forwards; }
    .vb-shift-box small { display:block; font-size:12px; color:#cbd5e1; }
    .vb-shift-box b { display:block; font-size:22px; line-height:1.15; font-weight:800; }
    @keyframes vbShiftTurn { from { height:var(--l0); transform:rotate(calc(var(--a0) + 180deg)); } to { height:var(--l1); transform:rotate(calc(var(--a1) + 180deg)); } }
    @keyframes vbShiftRing { 0% { transform:scale(.3); opacity:.9; } 100% { transform:scale(1.5); opacity:0; } }
    .vb-wind-in b { display:block; font-size:22px; line-height:1.15; color:var(--c); font-weight:800; }
    .vb-wind-in span { display:block; font-size:13px; font-weight:600; }
    .vb-wind-in small { display:block; font-size:11px; color:#cbd5e1; }
    @keyframes vbWindPop { 0% { transform:scale(.2); opacity:0; } 35% { transform:scale(1.6); opacity:1; } 65% { transform:scale(.88); } 100% { transform:scale(1); opacity:1; } }
    .vb-tag { position:absolute; left:30px; top:-12px; white-space:nowrap; background:rgba(5,10,20,.88); color:#fff; font:600 12px "Be Vietnam Pro",sans-serif; padding:3px 9px; border-radius:6px; border:1px solid rgba(255,255,255,.25); }
    .vb-dist { position:absolute; transform:translate(-50%,-50%); white-space:nowrap; background:rgba(5,10,20,.9); color:#fff; font:800 14px "Be Vietnam Pro",sans-serif; padding:5px 12px; border-radius:999px; border:2px solid #38bdf8; box-shadow:0 0 14px rgba(56,189,248,.55); animation: vbFadeIn .5s ease-out; }
    .vb-dist small { display:block; font-size:10px; font-weight:600; color:#bae6fd; text-align:center; }
    .vb-coast { position:absolute; transform:translate(-50%, calc(-100% - 12px)); white-space:nowrap; background:#f59e0b; color:#111; font:800 13px "Be Vietnam Pro",sans-serif; padding:4px 11px; border-radius:999px; box-shadow:0 3px 12px rgba(0,0,0,.45); animation: vbFadeIn .5s ease-out; }
    .vb-coast-dot { position:absolute; left:-8px; top:-8px; width:16px; height:16px; border-radius:50%; background:#f59e0b; border:2px solid #fff; box-shadow:0 0 0 0 rgba(245,158,11,.7); animation: vbCoastPulse 1.2s ease-out infinite; }
    @keyframes vbCoastPulse { 0% { box-shadow:0 0 0 0 rgba(245,158,11,.75); } 100% { box-shadow:0 0 0 18px rgba(245,158,11,0); } }
    .vb-prov-hl { animation: vbProvHl 1s ease-in-out infinite alternate; }
    @keyframes vbProvHl { from { fill-opacity:.25; stroke-opacity:.7; } to { fill-opacity:.55; stroke-opacity:1; } }
    .vb-rlabel { position:absolute; transform:translate(-50%,-50%); white-space:nowrap; background:var(--c); color:#111; font:700 12px "Be Vietnam Pro",sans-serif; padding:3px 9px; border-radius:6px; box-shadow:0 2px 10px rgba(0,0,0,.45); animation: vbFadeIn .5s ease-out; }
    .vb-done { position:absolute; transform:translate(-50%, calc(-100% - 30px)); white-space:nowrap; background:rgba(5,10,20,.88); color:#e2e8f0; font:700 14px "Be Vietnam Pro",sans-serif; padding:4px 12px; border-radius:6px; animation: vbFadeIn .6s ease-out; }
    @keyframes vbFadeIn { from { opacity:0; } to { opacity:1; } }
    .vb-tip { background:rgba(5,10,20,.85); color:#e2e8f0; border:0; font:600 11px "Be Vietnam Pro",sans-serif; box-shadow:none; padding:2px 7px; }
    .vb-tip::before { display:none; }

    /* ---------- Nhãn đặc khu Hoàng Sa / Trường Sa (giống ty.php) ---------- */
    .custom-div-icon { background: transparent; border: none; }
    .island-label-text {
        color: #d63031;
        font-weight: 800;
        font-size: 11px;
        text-align: center;
        text-shadow: 2px 0 #fff, -2px 0 #fff, 0 2px #fff, 0 -2px #fff, 1px 1px #fff, -1px -1px #fff, 1px -1px #fff, -1px 1px #fff;
        white-space: nowrap;
        font-family: 'Inter', "Be Vietnam Pro", sans-serif;
        text-transform: uppercase;
        line-height: 1.35;
        transition: transform 0.2s ease, font-size 0.2s ease;
        transform: scale(var(--island-scale, 1));
        transform-origin: center top;
    }

    /* ---------- Polygon tỉnh Việt Nam (giống ty.php) + hiệu ứng sáng nhấp nháy nhẹ ---------- */
    .leaflet-provPane-pane svg { filter: drop-shadow(0 0 3px rgba(147,197,253,.55)); }
    .vb-prov { animation: vbProvGlow 2.8s ease-in-out infinite; }
    @keyframes vbProvGlow {
        0%, 100% { fill-opacity: .30; stroke-opacity: .85; }
        50%      { fill-opacity: .50; stroke-opacity: 1; }
    }
    .province-label {
        background: rgba(255,255,255,0.92);
        color: #f59e0b;
        font-weight: 800;
        font-size: 0.82rem;
        padding: 4px 11px;
        border-radius: 50px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.18);
        white-space: nowrap;
        border: 1px solid #93c5fd;
        pointer-events: none;
        text-align: center;
        line-height: 1.15;
        font-family: 'Inter', "Be Vietnam Pro", sans-serif;
        transform: translate(-50%, -50%);
        display: inline-block;
    }
    @media (prefers-reduced-motion: reduce) { .vb-prov { animation: none; } }


    /* ---------- Điều khiển + k���ch bản ---------- */
    .controls { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-top:12px; }
    .controls .spacer { flex:1; }
    .controls label { display:inline-flex; align-items:center; gap:6px; margin:0; font-weight:500; font-size:.88rem; }
    .progress { height:4px; background:var(--line); border-radius:4px; margin-top:12px; overflow:hidden; }
    .progress div { height:100%; width:0; background:var(--accent); transition: width .3s; }
    .script p { margin:0 0 12px; font-size:1.02rem; }
    .script .cue { cursor:pointer; border-radius:4px; padding:1px 2px; transition: background .2s, color .2s; }
    .script .cue:hover { background:#1b2740; }
    .script .cue.active { background:var(--accent); color:#04111d; }
    .script .cue.done { color:var(--muted); }
    .script .idx { display:inline-block; min-width:26px; color:var(--muted); font-size:.8rem; }
    .script .tag { font-size:.7rem; color:var(--muted); border:1px solid var(--line); border-radius:4px; padding:0 4px; margin-left:3px; vertical-align:middle; }
    .note { font-size:.82rem; color:var(--warn); margin:8px 0 0; }

    /* ---------- Giọng đọc Vbee ---------- */
    .sr-only { position:absolute; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0,0,0,0); white-space:nowrap; border:0; }
    code { background:#0e1626; border:1px solid var(--line); border-radius:4px; padding:0 4px; font-size:.85em; }
    .tts-head { display:flex; align-items:baseline; justify-content:space-between; gap:12px; flex-wrap:wrap; }
    .tts-sum { color:var(--muted); font-size:.86rem; }
    .tts-grid { display:grid; grid-template-columns: 1fr; gap:16px; }
    @media (min-width: 820px) { .tts-grid { grid-template-columns: minmax(0, 1fr) minmax(0, 1.3fr); } }
    .tts .mt { margin-top:12px; }
    .tts input[type=text] { flex:1; min-width:180px; padding:9px 12px; border-radius:8px; border:1px solid var(--line); background:#0e1626; color:var(--text); font:inherit; font-size:.92rem; }
    .tts .tts-dict { min-height:130px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size:.86rem; }
    .tts-save { color:var(--muted); font-size:.82rem; align-self:center; }
    .tts-table-wrap { overflow-x:auto; margin-top:12px; max-height:620px; overflow-y:auto; border:1px solid var(--line); border-radius:8px; }
    .tts-table th { position:sticky; top:0; background:var(--card); z-index:1; }
    .tts-table td { vertical-align:top; }
    .tts-table td:first-child { color:var(--muted); width:32px; }
    .tts-table .orig { width:30%; min-width:200px; font-size:.86rem; }
    .tts-table .read { min-width:260px; }
    .tts-table .read textarea { min-height:58px; padding:7px 9px; font-size:.88rem; line-height:1.45; }
    .tts-table .read textarea.edited { border-color:var(--warn); }
    .tts-table .meta { font-size:.75rem; color:var(--muted); margin-top:3px; display:flex; gap:8px; align-items:center; }
    .tts-table .meta button { padding:0 6px; font-size:.72rem; font-weight:600; }
    .tts-table tr.playing td { background:rgba(56,189,248,.08); }
    .tts-table .ops { white-space:nowrap; }
    .tts-table .ops button { padding:5px 9px; font-size:.78rem; margin:0 0 4px 0; display:flex; width:100%; justify-content:center; }
    .badge { display:inline-block; font-size:.74rem; font-weight:700; border-radius:999px; padding:2px 9px; white-space:nowrap; border:1px solid var(--line); color:var(--muted); }
    .badge.ready { background:rgba(34,197,94,.15); border-color:rgba(34,197,94,.5); color:#86efac; }
    .badge.stale { background:rgba(245,158,11,.14); border-color:rgba(245,158,11,.55); color:#fcd34d; }
    .badge.busy  { background:rgba(56,189,248,.14); border-color:rgba(56,189,248,.5); color:#7dd3fc; }
    .badge.error { background:rgba(239,68,68,.14); border-color:rgba(239,68,68,.55); color:#fca5a5; }
    .tts-err { display:block; font-size:.72rem; color:#fca5a5; margin-top:4px; max-width:180px; white-space:normal; }

    /* ---------- Ghi video: sân khấu phủ kín cửa sổ, giữ tỉ lệ 16:9 ---------- */
    body.vb-rec { overflow:hidden; }
    body.vb-rec::before { content:''; position:fixed; inset:0; background:#000; z-index:9998; }
    .stage.rec { position:fixed; inset:0; margin:auto; width:min(100vw, calc(100vh * 16 / 9)); height:min(100vh, calc(100vw * 9 / 16)); aspect-ratio:auto; border-radius:0; z-index:9999; }

    /* ---------- Tạo video + YouTube ---------- */
    .vid-grid { display:grid; grid-template-columns:1fr; gap:16px; }
    @media (min-width: 820px) { .vid-grid { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); } }
    .vid-preview { width:100%; aspect-ratio:16/9; background:#05080f; border-radius:8px; border:1px solid var(--line); display:block; object-fit:contain; }
    .vid-meta { font-size:.82rem; color:var(--muted); margin-top:6px; }
    .yt input[type=text], .yt textarea { width:100%; padding:9px 12px; border-radius:8px; border:1px solid var(--line); background:#0e1626; color:var(--text); font:inherit; font-size:.92rem; }
    .yt textarea { min-height:220px; }
    .yt .fld { margin-bottom:12px; }
    .yt .fld label { display:flex; justify-content:space-between; gap:8px; font-size:.88rem; margin-bottom:6px; }
    .yt .cnt { color:var(--muted); font-weight:500; font-size:.78rem; }
    .yt .cnt.over { color:var(--danger); }
    .yt .two { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
    .yt .two select { width:100%; }
    .yt-auth { display:flex; gap:8px; align-items:center; flex-wrap:wrap; font-size:.88rem; margin-bottom:12px; }
    .yt-auth .ok { color:#86efac; font-weight:600; }
    .btn-yt { background:#ef4444; color:#fff; }
    button:disabled { opacity:.55; cursor:not-allowed; }
    .vid-status { font-size:.86rem; color:var(--muted); margin-top:8px; min-height:1.4em; }
    .vid-status a { color:var(--accent); }
</style>
<script src="https://accounts.google.com/gsi/client" async defer></script>
</head>
<body>
<main>
    <header>
        <h1>Bản tin bão cho video</h1>
        <p class="sub">Chọn cơn bão đang hoạt động. Hệ thống dựng bản tin từ dữ liệu JMA (giờ Việt Nam) và phát đồng bộ với bản đồ.</p>
    </header>

    <?php if ($dbError): ?>
        <div class="card alert" role="alert"><?= vb_e($dbError) ?></div>
    <?php endif; ?>

    <form class="card" method="get" action="">
        <label for="storm">Chọn bão để lấy dữ liệu</label>
        <div class="row">
            <select id="storm" name="storm" onchange="this.form.submit()">
                <option value="">-- Chọn cơn bão --</option>
                <?php foreach ($storms as $s): ?>
                    <option value="<?= vb_e($s['id']) ?>" <?= $s['id'] === $selectedId ? 'selected' : '' ?>><?= vb_e(vb_storm_label($s)) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Tạo bản tin</button>
        </div>
        <?php if (!$storms && !$dbError): ?>
            <p class="muted">Hiện không có cơn bão nào đang hoạt động.</p>
        <?php endif; ?>
    </form>

    <?php if ($selected && !$bulletin): ?>
        <div class="card alert" role="alert">Cơn bão này chưa có dữ liệu thời điểm hiện tại (Analysis) trong spec_json.</div>
    <?php endif; ?>

    <?php if ($bulletin): ?>
        <section class="card" aria-labelledby="player-title">
            <h2 id="player-title">Trình phát bản tin trên bản đồ</h2>
            <div class="stage" id="stage">
                <div id="map" role="img" aria-label="Bản đồ đường đi và dự báo của cơn bão"></div>
                <div class="ov brand"><small>BẢN TIN BÃO</small><strong><?= vb_e($titleName ?: 'Xoáy thuận nhiệt đới') ?></strong></div>
                <div class="ov info" id="info" aria-live="polite"></div>
                <div class="ov legend" id="legend" hidden></div>
                <div class="ov panel" id="panel" role="region" aria-live="polite" aria-label="Thông tin bổ sung" hidden></div>
                <div class="ov subtitle"><span id="subtitle"></span></div>
                <div class="ov src">Nguồn: Cơ quan Khí tượng Nhật Bản (JMA)</div>
            </div>
            <div class="progress" aria-hidden="true"><div id="progress"></div></div>
            <div class="controls">
                <button type="button" id="btnPlay">Phát</button>
                <button type="button" class="secondary" id="btnPrev" aria-label="Câu trước">Câu trước</button>
                <button type="button" class="secondary" id="btnNext" aria-label="Câu sau">Câu sau</button>
                <button type="button" class="secondary" id="btnReset">Về đầu</button>
                <span class="spacer"></span>
                <label>Giọng đọc
                    <select id="optVoice">
                        <option value="vbee" selected>Vbee</option>
                        <option value="browser">Trình duyệt</option>
                        <option value="off">Tắt (chạy theo ước tính)</option>
                    </select>
                </label>
                <label>Tốc độ
                    <select id="optRate">
                        <option value="0.85">0.85x</option>
                        <option value="1" selected>1x</option>
                        <option value="1.15">1.15x</option>
                        <option value="1.3">1.3x</option>
                    </select>
                </label>
                <label>Nền
                    <select id="optBase">
            <option value="sat" selected>Vệ tinh</option>
            <option value="dark">Tối</option>
                        <option value="light">Sáng</option>
                    </select>
                </label>
                <button type="button" class="secondary" id="btnFull">Toàn màn hình</button>
            </div>
            <p class="note" id="voiceNote" hidden></p>
        </section>

        <section class="card" aria-labelledby="vid-title">
            <h2 id="vid-title">Tạo video &amp; upload YouTube</h2>
            <div class="row">
                <label>Cách dựng
                    <select id="recMode">
                        <option value="server" selected>Máy chủ (PHP GD + ffmpeg)</option>
                        <option value="browser">Trình duyệt (quay lại tab)</option>
                    </select>
                </label>
                <label>Độ phân giải
                    <select id="recRes">
                        <option value="720">HD (1280×720)</option>
                        <option value="1080" selected>Full HD (1920×1080)</option>
                        <option value="1440">2K (2560×1440)</option>
                        <option value="2160">4K (3840×2160)</option>
                    </select>
                </label>
                <label>Khung hình
                    <select id="recFps">
                        <option value="24">24 fps</option>
                        <option value="30" selected>30 fps</option>
                        <option value="60">60 fps</option>
                    </select>
                </label>
                <button type="button" id="btnRec">Tạo video</button>
                <button type="button" class="secondary" id="btnRecCancel" hidden>Huỷ dựng</button>
                <button type="button" class="secondary" id="btnRecLoad" hidden>Mở video đã dựng</button>
            </div>
            <p class="muted" id="recHelpServer"><strong>Dựng trên máy chủ:</strong> máy chủ tự vẽ lại bản đồ, đường đi, vùng gió và phụ đề từng khung hình rồi ghép với giọng Vbee bằng ffmpeg. Không cần chia sẻ màn hình, không phụ thuộc kích thước cửa sổ hay card đồ hoạ; đóng tab giữa chừng vẫn dựng tiếp (mở lại trang sẽ tự nối lại tiến độ). Cần ffmpeg ở <code>../ff/ffmpeg</code>, PHP GD + FreeType, một font <code>.ttf</code> có tiếng Việt trong <code>../ff/</code> hoặc <code>./fonts/</code>, và máy chủ ra được internet để tải bản đồ nền (lưu cache ở <code>tile_cache/</code>; lần đầu sẽ lâu hơn).</p>
            <p class="muted" id="recHelpBrowser" hidden>Bấm "Tạo video" rồi chọn <strong>chia sẻ tab này</strong>. Bản đồ sẽ phủ kín cửa sổ và tự phát toàn bộ bản tin kèm giọng đọc Vbee; giữ nguyên tab trong lúc ghi, nhấn <kbd>Esc</kbd> để huỷ. Trình duyệt khuyên dùng: Chrome / Edge mới nhất. Độ nét thực tế phụ thuộc độ phân giải màn hình (2K/4K nét nhất trên màn hình độ phân giải cao).</p>
            <div class="progress" aria-hidden="true"><div id="recProgress"></div></div>
            <div class="vid-status" id="recStatus" aria-live="polite"></div>

            <div class="vid-grid" id="vidResult" hidden>
                <div>
                    <video id="vidPreview" class="vid-preview" controls playsinline></video>
                    <p class="vid-meta" id="vidInfo"></p>
                    <label class="mt" style="margin-top:12px">Ảnh thumbnail (cảnh cuối, không có phụ đề)</label>
                    <img id="vidThumb" class="vid-preview" alt="Ảnh thumbnail của video: cảnh cuối cùng trên bản đồ">
                    <div class="actions">
                        <a class="btn secondary" id="vidDownload" download>Tải video</a>
                        <a class="btn secondary" id="thumbDownload" download>Tải thumbnail</a>
                    </div>
                </div>

                <div class="yt">
                    <div class="yt-auth" id="ytAuth" aria-live="polite"><span class="muted">Đang kiểm tra đăng nhập YouTube...</span></div>
                    <div class="fld">
                        <label for="ytTitle">Tiêu đề <span class="cnt" id="ytTitleCnt"></span></label>
                        <input type="text" id="ytTitle" maxlength="100">
                    </div>
                    <div class="fld">
                        <label for="ytDesc">Mô tả <span class="cnt" id="ytDescCnt"></span></label>
                        <textarea id="ytDesc"></textarea>
                    </div>
                    <div class="fld">
                        <label for="ytTags">Tag (cách nhau bằng dấu phẩy) <span class="cnt" id="ytTagsCnt"></span></label>
                        <input type="text" id="ytTags">
                    </div>
                    <div class="fld two">
                        <div>
                            <label for="ytPrivacy">Quyền riêng tư</label>
                            <select id="ytPrivacy">
                                <option value="private">Riêng tư</option>
                                <option value="unlisted">Không công khai</option>
                                <option value="public" selected>Công khai</option>
                            </select>
                        </div>
                        <div>
                            <label for="ytCategory">Danh mục</label>
                            <select id="ytCategory">
                                <option value="25" selected>Tin tức &amp; Chính trị</option>
                                <option value="28">Khoa học &amp; Công nghệ</option>
                                <option value="27">Giáo dục</option>
                                <option value="22">Mọi người &amp; Blog</option>
                            </select>
                        </div>
                    </div>
                    <div class="fld">
                        <label for="ytChannel">Kênh</label>
                        <select id="ytChannel" style="width:100%"><option value="">-- Chưa đăng nhập --</option></select>
                    </div>
                    <div class="actions">
                        <button type="button" class="btn-yt" id="btnYtUpload">Upload YouTube</button>
                        <button type="button" class="secondary" id="btnYtReset">Khôi phục tiêu đề / mô tả / tag tự động</button>
                    </div>
                    <div class="progress" aria-hidden="true"><div id="ytProgress"></div></div>
                    <div class="vid-status" id="ytStatus" aria-live="polite"></div>
                </div>
            </div>
        </section>


        <section class="card tts" aria-labelledby="tts-title">
            <div class="tts-head">
                <h2 id="tts-title">Giọng đọc Vbee</h2>
                <span class="tts-sum" id="ttsSummary" aria-live="polite"></span>
            </div>
            <?php if (!vb_tts_configured()): ?>
                <p class="alert">Chưa cấu hình Vbee. Thêm <code>define('VBEE_APP_ID', '...')</code> và <code>define('VBEE_TOKEN', '...')</code> vào <code>config.php</code> để tạo giọng đọc (các câu đã có trong cache vẫn nghe được).</p>
            <?php endif; ?>
            <div class="tts-grid">
                <div>
                    <label for="ttsVoice">Giọng đọc</label>
                    <div class="row">
                        <select id="ttsVoice">
                            <?php foreach (VB_TTS_VOICES as $code => $label): ?>
                                <option value="<?= vb_e($code) ?>"><?= vb_e($label) ?></option>
                            <?php endforeach; ?>
                            <option value="__custom">Mã giọng khác...</option>
                        </select>
                        <input type="text" id="ttsVoiceCustom" placeholder="vd: hn_female_..." aria-label="Mã giọng Vbee" hidden>
                    </div>
                    <label for="ttsSpeed" class="mt">Tốc độ đọc (Vbee)</label>
                    <select id="ttsSpeed">
                        <?php foreach (['0.80', '0.90', '1.00', '1.10', '1.20', '1.30'] as $sp): ?>
                            <option value="<?= $sp ?>"><?= rtrim(rtrim($sp, '0'), '.') ?>x</option>
                        <?php endforeach; ?>
                    </select>
                    <p class="muted">Đổi giọng hoặc tốc độ thì mọi câu cần tạo lại (bản cũ vẫn giữ trong cache, chọn lại là dùng được ngay).</p>
                </div>
                <div>
                    <label for="ttsDict">Từ điển phiên âm (mỗi dòng: <code>từ gốc = cách đọc</code>)</label>
                    <textarea id="ttsDict" class="tts-dict" spellcheck="false" placeholder="JMA = giây em ây&#10;km/h = ki lô mét trên giờ&#10;Bualoi = Bu a loi"></textarea>
                    <p class="muted">Chỉ đổi văn bản gửi cho Vbee, phụ đề vẫn giữ chữ gốc. Khớp nguyên từ, không phân biệt hoa thường, cụm dài được ưu tiên.</p>
                </div>
            </div>
            <div class="actions">
                <button type="button" id="ttsGen">Tạo giọng đọc (câu mới / đã sửa)</button>
                <button type="button" class="secondary" id="ttsGenAll">Tạo lại tất cả</button>
                <button type="button" class="secondary" id="ttsStop" hidden>Dừng tạo</button>
                <span class="tts-save" id="ttsSaveState" aria-live="polite"></span>
            </div>
            <div class="progress" aria-hidden="true"><div id="ttsProgress"></div></div>
            <div class="tts-table-wrap">
                <table class="tts-table">
                    <thead>
                        <tr><th scope="col">#</th><th scope="col">Phụ đề (chữ gốc)</th><th scope="col">Văn bản đọc (gửi Vbee)</th><th scope="col">Trạng thái</th><th scope="col"><span class="sr-only">Thao tác</span></th></tr>
                    </thead>
                    <tbody id="ttsRows"></tbody>
                </table>
            </div>
        </section>

        <section class="card script" aria-labelledby="bt-title">
            <h2 id="bt-title">Kịch bản (mỗi đoạn = một cảnh, bấm vào câu để phát từ câu đó)</h2>
            <?php
            $actLabel = ['intro' => 'đường đi', 'position' => 'vị trí', 'wind' => 'sức gió', 'pressure' => 'áp suất', 'shift' => 'thay đổi', 'radius7' => 'gió cấp 7', 'radius10' => 'gió cấp 10', 'move' => 'di chuyển', 'dissipate' => 'tan dần', 'land' => 'cách đất liền', 'greet' => 'lời chào', 'outro' => 'lời kết', 'history' => 'lịch sử tên', 'rank' => 'xếp hạng năm', 'info' => 'chữ'];
            $ci = 0;
            foreach ($bulletin['scenes'] as $si => $scene): ?>
                <p><span class="idx"><?= $si + 1 ?>.</span><?php foreach ($scene as $cue): ?><span class="cue" data-i="<?= $ci ?>" tabindex="0" role="button"><?= vb_e($cue['text']) ?><span class="tag"><?= vb_e($actLabel[$cue['act']] ?? $cue['act']) ?></span></span> <?php $ci++; endforeach; ?></p>
            <?php endforeach; ?>
        </section>

        <section class="card" aria-labelledby="raw-title">
            <h2 id="raw-title">Toàn văn (chỉnh sửa / sao chép)</h2>
            <textarea id="fullText" aria-label="Toàn văn bản tin"><?= vb_e($fullText) ?></textarea>
            <div class="actions">
                <button type="button" id="copyBtn">Sao chép</button>
                <a class="btn secondary" href="?storm=<?= rawurlencode($selected['id']) ?>&amp;api=json" target="_blank" rel="noopener">Xem JSON</a>
            </div>
        </section>

        <section class="card" aria-labelledby="data-title">
            <h2 id="data-title">Số liệu gốc dùng để dựng bản tin</h2>
            <div style="overflow-x:auto">
            <table>
                <thead>
                    <tr><th>Mốc</th><th>Thời gian (VN)</th><th>Vị trí</th><th>Gió (kt)</th><th>Cấp</th><th>Giật</th><th>Phân loại</th><th>Di chuyển</th></tr>
                </thead>
                <tbody>
                <?php foreach ($bulletin['points'] as $k => $pt): ?>
                    <tr>
                        <td><?= $k === 0 ? 'Hiện tại' : 'Dự báo ' . $k ?></td>
                        <td><?= vb_e(vb_time_text($pt['ts']) ?? '--') ?></td>
                        <td><?= vb_e($pt['coord'] ?? '--') ?></td>
                        <td><?= vb_e($pt['item']['maximumWind']['sustained']['kt'] ?? '--') ?></td>
                        <td><?= $pt['lv'] !== null ? (int)$pt['lv'] : '--' ?></td>
                        <td><?= vb_e(vb_gust_text($pt['gust']) ?? '--') ?></td>
                        <td><?= vb_e($pt['cls'] === 'tan' ? 'tan dần' : $pt['cls']) ?></td>
                        <td><?= vb_e($pt['move'] !== '' ? $pt['move'] : '--') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <p class="muted">
                Gió cấp 7 trở lên: <?= vb_e($bulletin['gale'] ? ($bulletin['gale']['uniform'] ? $bulletin['gale']['km'] . ' km' : $bulletin['gale']['longKm'] . ' km (' . VB_BEARING_VI[$bulletin['gale']['longBearing']] . ') / ' . $bulletin['gale']['shortKm'] . ' km (' . VB_BEARING_VI[$bulletin['gale']['shortBearing']] . ')') : 'không có') ?>
                · Gió cấp 10 trở lên: <?= vb_e($bulletin['storm'] ? ($bulletin['storm']['uniform'] ? $bulletin['storm']['km'] . ' km' : $bulletin['storm']['longKm'] . ' km (' . VB_BEARING_VI[$bulletin['storm']['longBearing']] . ') / ' . $bulletin['storm']['shortKm'] . ' km (' . VB_BEARING_VI[$bulletin['storm']['shortBearing']] . ')') : 'không có') ?>
                · Điểm quá khứ: <?= count($player['past']) ?>
            </p>
            <?php $ms = $bulletin['milestones']; ?>
            <p class="muted">
                Cấp cao nhất trước bản tin này: <?= $ms['prevMax'] !== null ? 'cấp ' . (int)$ms['prevMax'] : 'chưa có' ?>
                · Lần đầu đạt đỉnh cường độ: <?= $ms['firstPeak'] ? 'có' . ($bulletin['rank'] ? ' (hạng ' . (int)$bulletin['rank']['rank'] . '/' . (int)$bulletin['rank']['total'] . ' năm ' . (int)$bulletin['rank']['year'] . ')' : '') : 'không' ?>
                · Lần đầu thành bão: <?= $ms['firstStorm'] ? 'có' . ($bulletin['seq'] ? ' (cơn thứ ' . (int)$bulletin['seq']['n'] . ' năm ' . (int)$bulletin['seq']['year'] . ')' : '') . ($bulletin['history'] ? ' (cơn thứ ' . (int)$bulletin['history']['ordinal'] . ' mang tên này)' : '') : 'không' ?>
            </p>
            <?php if (!empty($selected['updated'])): ?>
                <p class="muted">Dữ liệu cập nhật lúc: <?= vb_e($selected['updated']) ?></p>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>

<script>
    document.getElementById('copyBtn')?.addEventListener('click', async function () {
        const ta = document.getElementById('fullText');
        try { await navigator.clipboard.writeText(ta.value); } catch (e) { ta.select(); document.execCommand('copy'); }
        this.textContent = 'Đã sao chép';
        setTimeout(() => { this.textContent = 'Sao chép'; }, 1500);
    });
</script>

<?php if ($player): ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(() => {
    const D = <?= json_encode($player, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const PTS = D.points, CUES = D.cues;
    const TTS_INIT = <?= json_encode(vb_tts_load() + ['configured' => vb_tts_configured()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;


    const COLORS = [
        '#FFFFFF','#AEF1F9','#96F7DC','#96F7B4','#6FF46F','#73ED12',
        '#A4ED12','#DAED12','#EDC212','#ED8F12','#ED6312','#ED2912',
        '#D5102D','#AA1746','#781F5F','#4D2778','#222F91','#0D3688'
    ];
    const C_GALE = '#f59e0b', C_STORM = '#ef4444';
    const VI_BEARING = { 0: 'Bắc', 45: 'Đông Bắc', 90: 'Đông', 135: 'Đông Nam', 180: 'Nam', 225: 'Tây Nam', 270: 'Tây', 315: 'Tây Bắc' };
    const lvColor = lv => (lv === null || lv === undefined) ? '#94a3b8' : COLORS[Math.max(0, Math.min(17, lv))];
    const hasXY = p => p && p.lat !== null && p.lon !== null;
    const LL = p => [p.lat, p.lon];
    const clsLabel = c => c === 'tan' ? 'Tan dần' : c.charAt(0).toUpperCase() + c.slice(1);
    const esc = s => String(s ?? '').replace(/[&<>"']/g, ch => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[ch]));

    // ---------------------------------------------------------------- Bản đồ
    // padding lớn: vùng SVG vẽ rộng hơn khung nhìn để hình không bị cắt (gãy) khi bản đồ đang lia.
    const map = L.map('map', { zoomControl: false, zoomSnap: 0.25, worldCopyJump: true, renderer: L.svg({ padding: 1.5 }) });
    const bases = {
        dark:  L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', { attribution: '&copy; CARTO &copy; OpenStreetMap', maxZoom: 19 }),
        sat:   L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { attribution: '&copy; Esri', maxZoom: 18 }),
        light: L.tileLayer('https://{s}.basemaps.cartocdn.com/voyager/{z}/{x}/{y}{r}.png', { attribution: '&copy; CARTO &copy; OpenStreetMap', maxZoom: 19 }),
    };
    const satLabels = L.layerGroup([
        L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}', { maxZoom: 18, opacity: 0.85 }),
    ]);
    let base = bases.sat.addTo(map);
    satLabels.addTo(map);
    document.getElementById('optBase').addEventListener('change', e => {
        map.removeLayer(base); base = bases[e.target.value].addTo(map); base.bringToBack();
        if (e.target.value === 'sat') satLabels.addTo(map); else map.removeLayer(satLabels);
    });

    // ---------------------------------------------------------------- Tỉnh Việt Nam + đặc khu (giống ty.php)
    map.createPane('provPane').style.zIndex = 350;
    map.createPane('provLabelPane').style.zIndex = 450;
    map.getPane('provLabelPane').style.pointerEvents = 'none';
    const PROV_LABEL_ZOOM = 7.2;
    const provLabels = L.layerGroup();
    const provFeatures = {};
    const syncProvLabels = () => {
        const show = map.getZoom() >= PROV_LABEL_ZOOM;
        if (show && !map.hasLayer(provLabels)) map.addLayer(provLabels);
        else if (!show && map.hasLayer(provLabels)) map.removeLayer(provLabels);
    };
    map.on('zoomend', syncProvLabels);

    // video.php nằm trong thư mục con -> vn.json thường ở thư mục cha (cạnh ty.php).
    (async () => {
        for (const url of ['../vn.json', 'vn.json']) {
            try {
                const r = await fetch(url);
                if (!r.ok) continue;
                const data = await r.json();
                if (!data || !Array.isArray(data.features)) continue;
                const provName = f => f.properties.adm1_name1 || f.properties.adm1_name || 'Tỉnh không xác định';
                const nameToColor = {};
                data.features.forEach((f, i) => {
                    nameToColor[provName(f)] = `hsl(${Math.round((i * (360 / data.features.length)) % 360)},70%,55%)`;
                });
                L.geoJSON(data, {
                    pane: 'provPane',
                    interactive: false,
                    style: f => ({ fillColor: nameToColor[provName(f)] || '#60a5fa', fillOpacity: 0.35, color: '#1e3a5f', weight: 2, opacity: 0.9, className: 'vb-prov' }),
                    onEachFeature: (f, l) => {
                        provFeatures[provName(f)] = f;
                        provLabels.addLayer(L.marker(l.getBounds().getCenter(), {
                            pane: 'provLabelPane', interactive: false,
                            icon: L.divIcon({ html: `<div class="province-label">${esc(provName(f))}</div>`, className: '', iconSize: [0, 0], iconAnchor: [0, 0] }),
                        }));
                    },
                }).addTo(map);
                syncProvLabels();
                return;
            } catch (e) { /* thử đường dẫn tiếp theo */ }
        }
        console.warn('Không tải được vn.json (polygon tỉnh Việt Nam).');
    })();

    const islandIcon = name => L.divIcon({ className: 'custom-div-icon', html: `<div class='island-label-text'>${name}</div>`, iconSize: [200, 40], iconAnchor: [100, 20] });
    L.marker([16.4, 112.0], { icon: islandIcon('Đặc khu Hoàng Sa<br>(Việt Nam)'), interactive: false, pane: 'provLabelPane' }).addTo(map);
    L.marker([8.65, 111.92], { icon: islandIcon('Đặc khu Trường Sa<br>(Việt Nam)'), interactive: false, pane: 'provLabelPane' }).addTo(map);

    const pastLayer = L.layerGroup().addTo(map);
    const radiusLayer = L.layerGroup().addTo(map);
    const fcLayer = L.layerGroup().addTo(map);
    const markLayer = L.layerGroup().addTo(map);
    const fxLayer = L.layerGroup().addTo(map);

    const allLL = [...D.past.map(p => [p[0], p[1]]), ...PTS.filter(hasXY).map(LL)];
    const allBounds = allLL.length ? L.latLngBounds(allLL) : L.latLngBounds([[5, 100], [30, 140]]);
    // Toàn cảnh: toàn bộ đường đi + đất liền Việt Nam & hai đặc khu (nếu bão ở khu vực lân cận).
    const VN_BOUNDS = L.latLngBounds([[8.2, 102.1], [23.4, 112.5]]);
    const overviewBounds = L.latLngBounds(allBounds.getSouthWest(), allBounds.getNorthEast());
    if (allBounds.getCenter().lng < 130) overviewBounds.extend(VN_BOUNDS);
    const fitAll = (animate) => map.fitBounds(overviewBounds, { padding: [70, 70], maxZoom: 6, animate });
    fitAll(false);

    // ---------------------------------------------------------------- Máy quay (zoom xa / zoom gần)
    //  wide   : toàn cảnh đường đi + Việt Nam (đầu bản tin, kết thúc bản tin)
    //  travel : khung trung bình chứa đoạn bão đang tịnh tiến (vẫn thấy bối cảnh xung quanh)
    //  close  : cận cảnh tâm bão, độ gần tự tính theo bán kính gió để vừa khung hình
    //  closer : cận cảnh hơn nữa khi đọc sức gió
    const CAM_DUR = 1.6;
    const clampZ = (z, lo, hi) => Math.max(lo, Math.min(hi, z));
    const galeMaxKm = () => {
        const a = D.gale || D.storm;
        if (!a) return 0;
        return a.uniform ? a.km : a.longKm;
    };
    // Leaflet chỉ chiếu lại lớp SVG khi bản đồ dừng (moveend). Vẽ/cập nhật hình trong lúc flyTo
    // sẽ bị lệch, gãy rồi mới tự khớp lại -> mọi hoạt ảnh vẽ phải chờ máy quay dừng hẳn.
    let camIdle = Promise.resolve();
    function camRun(fly, dur) {
        camIdle = new Promise(res => {
            let done = false;
            const finish = () => { if (done) return; done = true; map.off('moveend', finish); clearTimeout(t); res(); };
            const t = setTimeout(finish, dur * 1000 + 600);
            map.on('moveend', finish);
            fly();
        });
        return camIdle;
    }
    const waitCam = () => camIdle;
    function camWide(dur = CAM_DUR) {
        return camRun(() => map.flyToBounds(overviewBounds, { padding: [70, 70], maxZoom: 6, duration: dur }), dur);
    }
    function camClose(p, tighter = false, dur = CAM_DUR) {
        if (!hasXY(p)) return camIdle;
        const km = Math.max(galeMaxKm(), 160) * (tighter ? 1.15 : 1.7);
        const z = map.getBoundsZoom(L.latLng(LL(p)).toBounds(km * 2000));
        return camRun(() => map.flyTo(LL(p), clampZ(z, 5.5, tighter ? 8.25 : 7.75), { duration: dur }), dur);
    }
    function camTravel(lls, dur = CAM_DUR) {
        const b = L.latLngBounds(lls).pad(0.45);
        return camRun(() => map.flyToBounds(b, { padding: [60, 60], maxZoom: 6.5, duration: dur }), dur);
    }
    function camBounds(b, maxZoom, dur) {
        return camRun(() => map.flyToBounds(b, { duration: dur, maxZoom }), dur);
    }

    // Điểm đích từ tâm theo khoảng cách (km) & phương vị (độ).
    function destPoint(lat, lon, km, brgDeg) {
        const R = 6371, d = km / R, b = brgDeg * Math.PI / 180;
        const la1 = lat * Math.PI / 180, lo1 = lon * Math.PI / 180;
        const la2 = Math.asin(Math.sin(la1) * Math.cos(d) + Math.cos(la1) * Math.sin(d) * Math.cos(b));
        const lo2 = lo1 + Math.atan2(Math.sin(b) * Math.sin(d) * Math.cos(la1), Math.cos(d) - Math.sin(la1) * Math.sin(la2));
        return [la2 * 180 / Math.PI, lo2 * 180 / Math.PI];
    }
    // Vùng gió giống computeWindAreaCircle() của ty.php (cách JMA vẽ):
    //  - đều: tròn tâm = tâm bão;
    //  - không đều: vẫn là hình tròn, đường kính = longKm + shortKm, tâm lệch (longKm - shortKm)/2 về hướng dài.
    // scale (0..1) dùng cho hiệu ứng lan ra từ tâm bão, vẫn giữ đúng tỉ lệ hai mép.
    function areaCircle(p, area, scale) {
        if (area.uniform) return { center: [p.lat, p.lon], radiusM: area.km * 1000 * scale };
        const offsetKm = (area.longKm - area.shortKm) / 2 * scale;
        const radiusKm = (area.longKm + area.shortKm) / 2 * scale;
        return { center: destPoint(p.lat, p.lon, offsetKm, area.longBearing), radiusM: radiusKm * 1000 };
    }
    const AREA_STYLE = {
        7:  { color: C_GALE,  weight: 2,   opacity: 0.85, fillColor: '#fbbf24', fillOpacity: 0.12, dashArray: '8,6' },
        10: { color: C_STORM, weight: 2.5, opacity: 0.9,  fillColor: '#f87171', fillOpacity: 0.2 },
    };

    const STORM_SVG = '<svg viewBox="-50 -50 100 100" width="46" height="46" aria-hidden="true"><g fill="none" stroke="currentColor" stroke-width="9" stroke-linecap="round"><circle r="15"/><path d="M0 -15 C -22 -15 -36 -28 -32 -46"/><path d="M0 15 C 22 15 36 28 32 46"/></g><circle r="6" fill="currentColor"/></svg>';
    const stormIcon = lv => L.divIcon({ className: 'vb-storm', html: `<div class="vb-storm-in" style="--c:${lvColor(lv)}">${STORM_SVG}</div>`, iconSize: [46, 46], iconAnchor: [23, 23] });

    // ---------------------------------------------------------------- Trạng thái cảnh
    let token = 0;
    let storm = null, stormLv = undefined, tagMarker = null;
    let fcLine = null, fcPath = [];

    function ensureStorm(p) {
        if (!storm) { storm = L.marker(LL(p), { icon: stormIcon(p.lv), interactive: false, zIndexOffset: 1000 }).addTo(markLayer); stormLv = p.lv; }
        else storm.setLatLng(LL(p));
        if (p.lv !== stormLv && p.lv !== null) { storm.setIcon(stormIcon(p.lv)); stormLv = p.lv; }
        storm.getElement()?.classList.remove('fade');
    }
    const stormClass = (c, on) => storm?.getElement()?.classList.toggle(c, on);

    function addPointDot(i) {
        const p = PTS[i];
        if (fcLayer.getLayers().some(l => l.options.pt === i)) return;
        const dot = L.circleMarker(LL(p), { pt: i, radius: i === 0 ? 7 : 5.5, color: '#fff', weight: 2, fillColor: lvColor(p.lv), fillOpacity: 1, interactive: false }).addTo(fcLayer);
        if (p.time) dot.bindTooltip(p.time, { permanent: true, direction: 'right', offset: [8, 0], className: 'vb-tip' });
    }

    function setInfo(p, withWind) {
        const el = document.getElementById('info');
        const lines = [];
        lines.push(`<div class="cls" style="--c:${lvColor(p.lv)}">${esc(clsLabel(p.cls))}</div>`);
        if (p.time) lines.push(`<div><span class="lbl">Thời gian:</span> ${esc(p.time)}</div>`);
        if (p.coord) lines.push(`<div><span class="lbl">Vị trí:</span> ${esc(p.coord)}</div>`);
        if (withWind && p.lv !== null) lines.push(`<div><span class="lbl">Gió:</span> cấp ${p.lv}${p.gust ? ', giật ' + esc(p.gust) : ''}</div>`);
        el.innerHTML = lines.join('');
    }

    function updateLegend() {
        const items = [];
        if (radiusLayer.getLayers().some(l => l.options.kind === 7)) items.push(`<div><i style="background:${C_GALE}"></i>Gió mạnh cấp 7 trở lên</div>`);
        if (radiusLayer.getLayers().some(l => l.options.kind === 10)) items.push(`<div><i style="background:${C_STORM}"></i>Gió mạnh cấp 10 trở lên</div>`);
        if (fcPath.length) items.push(`<div><i style="background:transparent;border:2px dashed #fff"></i>Đường đi dự báo</div>`);
        const lg = document.getElementById('legend');
        lg.innerHTML = items.join('');
        lg.hidden = !items.length;
    }

    function resetScene() {
        token++;
        [pastLayer, radiusLayer, fcLayer, markLayer, fxLayer].forEach(l => l.clearLayers());
        storm = null; stormLv = undefined; tagMarker = null; fcLine = null; fcPath = [];
        document.getElementById('info').innerHTML = '';
        document.getElementById('subtitle').textContent = '';
        hidePanel();
        updateLegend();
        map.stop();
        camIdle = Promise.resolve();
        fitAll(false);
    }

    // Chạy hoạt ảnh; ở chế độ tức thì (khi tua) chỉ áp trạng thái cuối.
    const ease = k => k < .5 ? 2 * k * k : 1 - Math.pow(-2 * k + 2, 2) / 2;
    async function animate(dur, step, inst) {
        if (inst) { step(1); return true; }
        const tok = token;
        await waitCam();
        if (tok !== token) return false;
        return new Promise(res => {
            const t0 = performance.now();
            const f = now => {
                if (tok !== token) return res(false);
                const k = Math.min(1, (now - t0) / dur);
                step(ease(k));
                if (k < 1) requestAnimationFrame(f); else res(true);
            };
            requestAnimationFrame(f);
        });
    }
    const sleep = ms => new Promise(r => setTimeout(r, ms));

    function ensureVisible(lls, inst) {
        if (inst) return;
        const b = L.latLngBounds(lls);
        if (!map.getBounds().pad(-0.12).contains(b)) map.flyToBounds(b.pad(0.6), { maxZoom: Math.max(map.getZoom(), 4), duration: 0.9 });
    }

    // ---------------------------------------------------------------- Hành động theo cue
    async function actIntro(dur, inst) {
        const p0 = PTS[0];
        const pts = D.past.map(p => ({ ll: [p[0], p[1]], lv: p[2] }));
        if (hasXY(p0)) pts.push({ ll: LL(p0), lv: p0.lv });
        if (!inst) camWide(1.2);
        const segs = [];
        for (let i = 0; i < pts.length - 1; i++) {
            segs.push(L.polyline([], { color: lvColor(pts[i].lv), weight: 3.5, opacity: 0.9, interactive: false, noClip: true, smoothFactor: 0 }).addTo(pastLayer));
        }
        if (segs.length) {
            await animate(Math.min(dur * 0.85, 5000), k => {
                const m = k * segs.length;
                segs.forEach((s, i) => {
                    if (i < Math.floor(m)) s.setLatLngs([pts[i].ll, pts[i + 1].ll]);
                    else if (i === Math.floor(m)) {
                        const f = m - i, a = pts[i].ll, b = pts[i + 1].ll;
                        s.setLatLngs([a, [a[0] + (b[0] - a[0]) * f, a[1] + (b[1] - a[1]) * f]]);
                    } else s.setLatLngs([]);
                });
            }, inst);
        }
        if (hasXY(p0)) { addPointDot(0); ensureStorm(p0); setInfo(p0, false); }
    }

    async function moveTo(from, to, dur, inst, tag) {
        const a = PTS[from], b = PTS[to];
        if (!hasXY(a) || !hasXY(b)) return;
        ensureStorm(a);
        if (!fcPath.length) fcPath = [LL(a)];
        if (!fcLine) fcLine = L.polyline([], { color: '#ffffff', weight: 2.5, opacity: 0.9, dashArray: '8 7', interactive: false, noClip: true, smoothFactor: 0 }).addTo(fcLayer);
        updateLegend();
        if (!inst) {
            const camSec = Math.min(1.1, Math.max(0.7, dur / 1000 * 0.3));
            camTravel([LL(a), LL(b)], camSec);
            dur = Math.max(800, dur - camSec * 1000);
        }
        if (tag && !inst) {
            tagMarker = L.marker(LL(a), { interactive: false, icon: L.divIcon({ className: '', iconSize: [0, 0], html: `<div class="vb-tag">${esc(tag)}</div>` }) }).addTo(fxLayer);
        }
        await animate(dur, k => {
            const cur = [a.lat + (b.lat - a.lat) * k, a.lon + (b.lon - a.lon) * k];
            storm.setLatLng(cur);
            tagMarker?.setLatLng(cur);
            fcLine.setLatLngs([...fcPath, cur]);
        }, inst);
        fcPath.push(LL(b));
        fcLine.setLatLngs(fcPath);
        ensureStorm(b);
    }

    async function actPosition(c, dur, inst) {
        const p = PTS[c.pt];
        if (!hasXY(p)) return;
        if (c.from !== undefined) await moveTo(c.from, c.pt, Math.min(dur * 0.45, 3500), inst, null);
        addPointDot(c.pt);
        ensureStorm(p);
        setInfo(p, false);
        if (inst) return;
        camClose(p);
        L.marker(LL(p), { interactive: false, icon: L.divIcon({ className: 'vb-pulse-wrap', iconSize: [0, 0], html: '<span class="vb-pulse"></span><span class="vb-pulse d2"></span>' }) }).addTo(fxLayer);
        stormClass('blink', true);
    }

    function actWind(c, inst) {
        const p = PTS[c.pt];
        setInfo(p, true);
        if (inst || !hasXY(p) || p.lv === null) return;
        camClose(p, true, 1.2);
        const html = `<div class="vb-wind"><div class="vb-wind-in" style="--c:${lvColor(p.lv)}"><b>Cấp ${p.lv}</b>${p.gust ? `<span>Giật ${esc(p.gust)}</span>` : ''}${p.kmh ? `<small>~${p.kmh} km/h</small>` : ''}</div></div>`;
        L.marker(LL(p), { interactive: false, zIndexOffset: 2000, icon: L.divIcon({ className: '', iconSize: [0, 0], html }) }).addTo(fxLayer);
    }

    function actShift(c, inst) {
        const p = PTS[c.pt];
        setInfo(p, true);
        const d = c.shift;
        if (inst || !d || !hasXY(p)) return;
        camClose(p, true, 1.2);
        const body = d.kind === 'weaken'
            ? '<i class="vb-shift-ring"></i><i class="vb-shift-ring" style="animation-delay:.6s"></i>'
            : `<i class="vb-shift-arrow" style="--a0:${d.a0}deg;--a1:${d.a1}deg;--l0:${d.l0}px;--l1:${d.l1}px"></i>`;
        const html = `<div class="vb-shift" style="--c:${d.col}">${body}<i class="vb-shift-dot"></i><div class="vb-shift-box"><small>${d.title}</small><b>${d.lab}</b></div></div>`;
        L.marker(LL(p), { interactive: false, zIndexOffset: 2000, icon: L.divIcon({ className: '', iconSize: [0, 0], html }) }).addTo(fxLayer);
    }

    function actPressure(c, inst) {
        const p = PTS[c.pt];
        setInfo(p, true);
        if (inst || !hasXY(p) || p.pres === null || p.pres === undefined) return;
        camClose(p, true, 1.2);
        const rings = [0, 0.5, 1].map(d => `<i class="vb-pres-ring" style="animation-delay:${d}s"></i>`).join('');
        const html = `<div class="vb-pres">${rings}<i class="vb-pres-dot"></i><div class="vb-pres-box"><small>Áp suất thấp nhất</small><b>${p.pres} hPa</b></div></div>`;
        L.marker(LL(p), { interactive: false, zIndexOffset: 2000, icon: L.divIcon({ className: '', iconSize: [0, 0], html }) }).addTo(fxLayer);
    }

    async function actRadius(c, level, inst) {
        const p = PTS[0], area = level === 7 ? D.gale : D.storm;
        if (!hasXY(p) || !area) return;
        const color = level === 7 ? C_GALE : C_STORM;
        const full = areaCircle(p, area, 1);
        if (!inst) {
            const tok = token;
            await camBounds(L.latLng(full.center).toBounds(full.radiusM * 2).pad(0.25), level === 10 ? 8 : 7.5, 1.1);
            if (tok !== token) return;
        }
        const start = areaCircle(p, area, inst ? 1 : 0.01);
        const circle = L.circle(start.center, Object.assign({ kind: level, radius: start.radiusM, interactive: false }, AREA_STYLE[level])).addTo(radiusLayer);
        updateLegend();
        await animate(1500, k => {
            const c = areaCircle(p, area, Math.max(0.01, k));
            circle.setLatLng(c.center).setRadius(c.radiusM);
        }, inst);

        // Nhãn bán kính đặt đúng mép vùng gió tính từ tâm bão, kèm đường đo từ tâm ra mép.
        const edges = area.uniform
            ? [{ km: area.km, brg: level === 7 ? 45 : 135, txt: `Cấp ${level}+ · ${area.km} km` }]
            : [
                { km: area.longKm, brg: area.longBearing, txt: `Cấp ${level}+ · ${VI_BEARING[area.longBearing]} ${area.longKm} km` },
                { km: area.shortKm, brg: area.shortBearing, txt: `${VI_BEARING[area.shortBearing]} ${area.shortKm} km` },
            ];
        edges.forEach(e => {
            const tip = destPoint(p.lat, p.lon, e.km, e.brg);
            L.polyline([[p.lat, p.lon], tip], { color, weight: 1.5, opacity: 0.9, dashArray: '3,5', interactive: false, noClip: true }).addTo(radiusLayer);
            L.marker(tip, { interactive: false, icon: L.divIcon({ className: '', iconSize: [0, 0], html: `<div class="vb-rlabel" style="--c:${color}">${e.txt}</div>` }) }).addTo(radiusLayer);
        });
    }

    async function actDissipate(c, dur, inst) {
        const p = PTS[c.pt];
        if (!hasXY(p)) return;
        if (c.from !== undefined) await moveTo(c.from, c.pt, Math.min(dur * 0.5, 3500), inst, null);
        addPointDot(c.pt);
        ensureStorm(p);
        stormClass('fade', true);
        setInfo(p, false);
        if (!inst) {
            camClose(p);
            L.marker(LL(p), { interactive: false, icon: L.divIcon({ className: '', iconSize: [0, 0], html: '<div class="vb-done">Tan dần</div>' }) }).addTo(fxLayer);
        }
    }

    // Khoảng cách tới đất liền: mọi thứ vẽ vào fxLayer -> tự xoá khi sang câu tiếp theo (đỡ rối mắt).
    function highlightProv(raw) {
        const f = provFeatures[raw];
        if (!f) return;
        L.geoJSON(f, { pane: 'provPane', interactive: false, style: { color: '#f59e0b', weight: 3.5, fillColor: '#f59e0b', fillOpacity: 0.4, className: 'vb-prov-hl' } }).addTo(fxLayer);
    }
    async function actLand(c, dur, inst) {
        const p = PTS[c.pt], d = c.land;
        if (inst || !hasXY(p) || !d) return;
        const tok = token;
        const name = esc(d.provShort || d.prov);
        if (d.onLand) {
            await camClose(p, true, 1.2);
            if (tok !== token) return;
            highlightProv(d.prov);
            L.marker(LL(p), { interactive: false, zIndexOffset: 1500, icon: L.divIcon({ className: '', iconSize: [0, 0], html: `<div class="vb-coast">Trên đất liền ${name}</div>` }) }).addTo(fxLayer);
            return;
        }
        const coast = [d.lat, d.lon];
        // Khung vừa đủ ch���a tâm bão + điểm bờ gần nhất; gần bờ thì zoom sát hơn, xa thì lùi ra.
        const b = L.latLngBounds([LL(p), coast]).pad(d.km < 150 ? 0.9 : 0.3);
        await camBounds(b, d.km < 150 ? 8 : 7.5, d.km > 1500 ? 1.8 : 1.3);
        if (tok !== token) return;
        highlightProv(d.prov);
        const line = L.polyline([LL(p)], { color: '#38bdf8', weight: 3, opacity: 0.95, dashArray: '10 8', interactive: false, noClip: true, smoothFactor: 0 }).addTo(fxLayer);
        const ok = await animate(Math.min(1400, Math.max(700, dur * 0.3)), k => {
            line.setLatLngs([LL(p), [p.lat + (coast[0] - p.lat) * k, p.lon + (coast[1] - p.lon) * k]]);
        }, false);
        if (!ok || tok !== token) return;
        L.marker(coast, { interactive: false, icon: L.divIcon({ className: '', iconSize: [0, 0], html: '<span class="vb-coast-dot"></span>' }) }).addTo(fxLayer);
        L.marker(coast, { interactive: false, zIndexOffset: 1500, icon: L.divIcon({ className: '', iconSize: [0, 0], html: `<div class="vb-coast">${name}</div>` }) }).addTo(fxLayer);
        const mid = [(p.lat + coast[0]) / 2, (p.lon + coast[1]) / 2];
        L.marker(mid, { interactive: false, zIndexOffset: 1600, icon: L.divIcon({ className: '', iconSize: [0, 0], html: `<div class="vb-dist">≈ ${d.km.toLocaleString('vi-VN')} km<small>tới đất liền gần nhất</small></div>` }) }).addTo(fxLayer);
    }

    // ---------------------------------------------------------------- Màn hình thông tin (lịch sử tên / xếp hạng năm)
    const stageEl = document.getElementById('stage');
    const panelEl = document.getElementById('panel');
    const PANEL_ACTS = new Set(['history', 'rank']);
    const lvChip = lv => (lv === null || lv === undefined)
        ? '<span class="pn-chip" style="--c:#475569;--tc:#fff">--</span>'
        : `<span class="pn-chip" style="--c:${lvColor(lv)};--tc:${lv >= 11 ? '#fff' : '#111'}">Cấp ${lv}</span>`;
    const kmhTxt = k => k ? `${k} km/h` : '--';

    function historyHtml(hl) {
        const H = D.history;
        if (!H) return '';
        const MAX = 9;
        let list = H.rows.map((r, i) => ({ ...r, i }));
        let hidden = 0;
        if (list.length > MAX) {
            // Luôn giữ cơn mạnh nhất + cơn hiện tại, phần còn lại ưu tiên các năm gần nhất.
            const must = new Set(list.filter(r => r.cur || r.i === H.strongest).map(r => r.i));
            const rest = list.filter(r => !must.has(r.i)).slice(-(MAX - must.size));
            const keep = new Set([...must, ...rest.map(r => r.i)]);
            hidden = list.length - keep.size;
            list = list.filter(r => keep.has(r.i));
        }
        const rows = list.map(r => {
            const cls = [r.cur ? 'cur' : '', hl === 'strongest' && r.i === H.strongest ? 'hl' : ''].join(' ').trim();
            return `<tr class="${cls}"><td>${r.year}</td><td>${lvChip(r.lv)}</td><td>${kmhTxt(r.kmh)}</td>`
                + `<td>${r.cur ? '<span class="pn-now">Đang hoạt động</span>' : esc(r.period || '--')}</td>`
                + `<td>${r.cur ? '--' : (r.landfall ? 'Đổ bộ' : 'Ngoài biển')}</td></tr>`;
        }).join('');
        return `<div class="pn-h"><div><small>LỊCH SỬ TÊN BÃO</small><strong>Các cơn bão mang tên ${esc(H.name.toUpperCase())}</strong>`
            + `<span>${esc(H.name)} hiện tại là cơn thứ ${H.ordinal} mang tên này`
            + (D.seq ? ` · Cơn bão số ${D.seq.n} năm ${D.seq.year} trên Tây Bắc Thái Bình Dương` : '')
            + ` · Nguồn: JMA (Tokyo)</span></div></div>`
            + `<table class="pn-t"><thead><tr><th>Năm</th><th>Cường độ</th><th>Gió mạnh nhất</th><th>Thời gian hoạt động</th><th>Trạng thái</th></tr></thead><tbody>${rows}</tbody></table>`
            + (hidden ? `<div class="pn-foot">... và ${hidden} cơn bão cùng tên khác</div>` : '');
    }

    function rankHtml() {
        const R = D.rank;
        if (!R) return '';
        const MAX = 10;
        const all = R.rows;
        const ci = all.findIndex(r => r.cur);
        let list = all, more = 0;
        if (all.length > MAX) {
            if (ci < MAX - 1) { list = all.slice(0, MAX); more = all.length - MAX; }
            else { list = [...all.slice(0, 3), null, ...all.slice(ci - 2, Math.min(all.length, ci + 3))]; more = all.length - Math.min(all.length, ci + 3); }
        }
        const rows = list.map(r => r === null
            ? '<tr class="gap"><td colspan="4">...</td></tr>'
            : `<tr class="${r.cur ? 'cur' : ''}"><td>${r.rank}</td><td>${esc(r.name)}${r.cur ? ' <span class="pn-now">(hiện tại)</span>' : ''}</td><td>${lvChip(r.lv)}</td><td>${kmhTxt(r.kmh)}</td></tr>`
        ).join('');
        return `<div class="pn-h"><div class="pn-badge"><b>${R.rank}</b><i>/ ${R.total}</i></div>`
            + `<div><small>XẾP HẠNG CƯỜNG ĐỘ NĂM ${R.year}</small><strong>Bão mạnh nhất Tây Bắc Thái Bình Dương</strong>`
            + `<span>Theo sức gió mạnh nhất (JMA) · ${esc(all[ci]?.name || '')} tính theo bản tin hiện tại</span></div></div>`
            + `<table class="pn-t"><thead><tr><th>Hạng</th><th>Cơn bão</th><th>Cường độ</th><th>Gió mạnh nhất</th></tr></thead><tbody>${rows}</tbody></table>`
            + (more > 0 ? `<div class="pn-foot">... và ${more} cơn bão yếu hơn</div>` : '');
    }

    function showPanel(c) {
        const html = c.act === 'history' ? historyHtml(c.hl) : rankHtml();
        if (!html) return;
        panelEl.innerHTML = html;
        panelEl.hidden = false;
        panelEl.classList.remove('in');
        void panelEl.offsetWidth;
        panelEl.classList.add('in');
        stageEl.classList.add('dim');
    }
    function hidePanel() {
        panelEl.hidden = true;
        panelEl.classList.remove('in');
        stageEl.classList.remove('dim');
    }

    function runAction(c, dur, inst) {
        switch (c.act) {
            case 'history':
            case 'rank':      if (!inst) { camWide(1.4); showPanel(c); } return Promise.resolve();
            case 'land':      return actLand(c, dur, inst);
            case 'outro':     if (!inst) camWide(2); return Promise.resolve();
            case 'intro':     return actIntro(dur, inst);
            case 'position':  return actPosition(c, dur, inst);
            case 'wind':      return actWind(c, inst);
            case 'pressure':  return actPressure(c, inst);
            case 'shift':     return actShift(c, inst);
            case 'radius7':   return actRadius(c, 7, inst);
            case 'radius10':  return actRadius(c, 10, inst);
            case 'move':      return moveTo(c.from, c.pt, Math.max(1500, dur * 0.85), inst, c.tag);
            case 'dissipate': return actDissipate(c, dur, inst);
            default:          return Promise.resolve();
        }
    }

    // ---------------------------------------------------------------- Vbee: văn bản đọc + tạo giọng theo từng câu
    // Mỗi cue có 1 mp3 riêng. Một câu chỉ được coi là "sẵn sàng" khi mp3 được tạo đúng từ
    // giọng + tốc độ + văn bản đọc HIỆN TẠI (chữ ký sig) -> sửa từ xong là biết ngay câu nào cần tạo lại.
    const Vbee = (() => {
        const S = { voice: TTS_INIT.voice, speed: TTS_INIT.speed, dict: TTS_INIT.dict || [], overrides: Object.assign({}, TTS_INIT.overrides) };
        const VOICE_RE = /^[A-Za-z0-9_\-.]{3,80}$/;
        const norm = t => String(t ?? '').replace(/\s+/g, ' ').trim();
        const items = CUES.map(() => ({ sig: null, url: null, state: 'none', err: '', errSig: null, dur: null }));

        // Từ điển phiên âm: gộp thành 1 biểu thức (cụm dài ưu tiên) -> mỗi đoạn chữ chỉ bị thay đúng 1 lần,
        // kết quả phiên âm không bị quy tắc khác thay tiếp. Chỉ ràng biên từ ở phía là chữ/số.
        let dictRe = null, dictMap = new Map();
        const reEsc = s => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        function compileDict() {
            dictMap = new Map();
            const terms = [];
            S.dict.forEach(([f, t]) => {
                const k = norm(f).toLowerCase();
                if (!k || dictMap.has(k)) return;
                dictMap.set(k, norm(t));
                terms.push(norm(f));
            });
            terms.sort((a, b) => b.length - a.length);
            dictRe = terms.length ? new RegExp(terms.map(t =>
                (/^[\p{L}\p{N}]/u.test(t) ? '(?<![\\p{L}\\p{N}])' : '') + reEsc(t) + (/[\p{L}\p{N}]$/u.test(t) ? '(?![\\p{L}\\p{N}])' : '')
            ).join('|'), 'giu') : null;
        }
        const applyDict = t => dictRe ? t.replace(dictRe, m => dictMap.get(m.toLowerCase()) ?? m) : t;
        const origKey = i => norm(CUES[i].text);
        const autoText = i => norm(applyDict(CUES[i].text));
        const readText = i => norm(S.overrides[origKey(i)] ?? autoText(i));
        const sigOf = i => `${S.voice}|${S.speed}|${readText(i)}`;
        const isReady = i => !!items[i].url && items[i].sig === sigOf(i);
        const urlFor = i => isReady(i) ? items[i].url : null;
        function status(i) {
            const it = items[i];
            if (it.state === 'busy') return 'busy';
            if (it.state === 'error' && it.errSig === sigOf(i)) return 'error';
            if (isReady(i)) return 'ready';
            return it.url ? 'stale' : 'none';
        }

        async function post(data) {
            const fd = new FormData();
            Object.entries(data).forEach(([k, v]) => fd.append(k, v));
            const r = await fetch(location.pathname + location.search, { method: 'POST', body: fd });
            let j = null;
            try { j = await r.json(); } catch (e) { /* phản hồi không phải JSON */ }
            if (!j) throw new Error('Máy chủ trả về dữ liệu không hợp lệ (HTTP ' + r.status + ')');
            if (!j.ok) throw new Error(j.error || 'Lỗi không xác định');
            return j;
        }

        // ---------- Lưu cài đặt (giọng, tốc độ, từ điển, bản sửa tay) lên máy chủ
        const saveEl = document.getElementById('ttsSaveState');
        let saveTimer = null;
        function scheduleSave() {
            clearTimeout(saveTimer);
            saveEl.textContent = 'Chưa lưu...';
            saveTimer = setTimeout(async () => {
                saveEl.textContent = 'Đang lưu...';
                try {
                    await post({ action: 'tts_save', voice: S.voice, speed: S.speed, dict: JSON.stringify(S.dict), overrides: JSON.stringify(S.overrides) });
                    saveEl.textContent = 'Đã lưu cài đặt';
                } catch (e) { saveEl.textContent = 'Lỗi lưu: ' + e.message; }
            }, 800);
        }

        // ---------- Dò cache: câu nào đã có mp3 cho đúng giọng + tốc độ + văn bản đọc
        let lookupTimer = null, lookupSeq = 0;
        function scheduleLookup(delay = 500) { clearTimeout(lookupTimer); lookupTimer = setTimeout(lookup, delay); }
        async function lookup() {
            const seq = ++lookupSeq;
            const sigs = CUES.map((_, i) => sigOf(i));
            try {
                const j = await post({ action: 'tts_lookup', voice: S.voice, speed: S.speed, texts: JSON.stringify(CUES.map((_, i) => readText(i))) });
                if (seq !== lookupSeq) return;
                j.urls.forEach((url, i) => {
                    const it = items[i];
                    if (it.state === 'busy' || sigs[i] !== sigOf(i)) return;
                    if (url) { if (it.url !== url) items[i] = { ...it, sig: sigs[i], url, state: 'ready', dur: null }; else it.sig = sigs[i]; }
                    else if (it.sig === sigs[i] && it.url) items[i] = { ...it, url: null, state: 'none', dur: null };
                });
                renderAll();
                CUES.forEach((_, i) => { if (isReady(i) && !items[i].dur) probe(i); });
            } catch (e) { saveEl.textContent = 'Không dò được cache: ' + e.message; }
        }
        async function probe(i) {
            const url = items[i].url;
            const a = url ? await loadAudio(url) : null;
            if (a && items[i].url === url) { items[i].dur = a.duration; renderRow(i); }
        }

        // ---------- Tạo giọng: start -> hỏi trạng thái (poll) -> máy chủ tải mp3 về cache
        let genRun = 0;
        async function genOne(i, force, run) {
            const sig = sigOf(i), text = readText(i);
            if (!text) return;
            const prev = items[i];
            items[i] = { ...prev, state: 'busy', err: '' };
            renderRow(i); summary();
            try {
                const st = await post({ action: 'tts_start', voice: S.voice, speed: S.speed, text, force: force ? 1 : 0 });
                let res = st, tries = 0, netErr = 0;
                while (res.status !== 'ready') {
                    if (run !== genRun) throw new Error('__stopped');
                    if (++tries > 120) throw new Error('Quá thời gian chờ Vbee');
                    await sleep(tries <= 4 ? 2500 : 5000);
                    if (run !== genRun) throw new Error('__stopped');
                    try { res = await post({ action: 'tts_poll', key: st.key, request_id: st.request_id }); netErr = 0; }
                    catch (e) { if (++netErr >= 5) throw e; res = { status: 'pending' }; }
                }
                items[i] = { sig, url: res.url, state: 'ready', err: '', errSig: null, dur: null };
                probe(i);
            } catch (e) {
                items[i] = e.message === '__stopped'
                    ? { ...prev, state: prev.url ? 'ready' : 'none' }
                    : { ...prev, state: 'error', err: e.message, errSig: sig };
            }
            renderRow(i); summary();
        }

        const btnGen = document.getElementById('ttsGen'), btnGenAll = document.getElementById('ttsGenAll'), btnStop = document.getElementById('ttsStop');
        function setBusy(on) { btnGen.disabled = on; btnGenAll.disabled = on; btnStop.hidden = !on; }
        async function genMany(list, force) {
            if (!list.length) { saveEl.textContent = 'Tất cả câu đã sẵn sàng'; return; }
            const run = ++genRun;
            setBusy(true);
            let k = 0;
            const worker = async () => { while (k < list.length && run === genRun) await genOne(list[k++], force, run); };
            await Promise.all([worker(), worker(), worker()]);
            if (run === genRun) setBusy(false);
            const errs = list.filter(i => status(i) === 'error').length;
            if (run === genRun) saveEl.textContent = errs ? `Xong, ${errs} câu lỗi - xem cột trạng thái` : 'Đã tạo xong giọng đọc';
        }
        btnGen.addEventListener('click', () => genMany(CUES.map((_, i) => i).filter(i => !['ready', 'busy'].includes(status(i)) && readText(i)), false));
        btnGenAll.addEventListener('click', () => { if (confirm('Tạo lại giọng đọc cho toàn bộ ' + CUES.length + ' câu?')) genMany(CUES.map((_, i) => i), true); });
        btnStop.addEventListener('click', () => { genRun++; setBusy(false); saveEl.textContent = 'Đã dừng tạo giọng'; });

        // ---------- Nghe thử từng câu (độc lập với trình phát bản đồ)
        const preview = new Audio();
        let previewIdx = -1;
        function stopPreview() {
            preview.pause();
            if (previewIdx >= 0) { const b = rows[previewIdx]?.querySelector('.listen'); if (b) b.textContent = 'Nghe'; }
            previewIdx = -1;
        }
        preview.addEventListener('ended', stopPreview);
        function togglePreview(i) {
            const was = previewIdx;
            stopPreview();
            if (was === i || !items[i].url) return;
            stopVoice();
            preview.src = items[i].url;
            preview.playbackRate = 1;
            preview.play().then(() => { previewIdx = i; rows[i].querySelector('.listen').textContent = 'Dừng'; }).catch(() => {});
        }

        // ---------- Bảng câu
        const tbody = document.getElementById('ttsRows');
        let rows = [];
        const LABEL = { none: 'Chưa tạo', busy: 'Đang tạo...', ready: 'Sẵn sàng', stale: 'Cần tạo lại', error: 'Lỗi' };
        function setOverride(i, v) {
            const k = origKey(i), nv = norm(v);
            if (!nv || nv === autoText(i)) delete S.overrides[k]; else S.overrides[k] = nv;
        }
        function renderRow(i, keepText) {
            const tr = rows[i];
            if (!tr) return;
            const ta = tr.querySelector('textarea');
            if (!keepText && document.activeElement !== ta) ta.value = readText(i);
            const edited = S.overrides[origKey(i)] !== undefined;
            ta.classList.toggle('edited', edited);
            tr.querySelector('.src').textContent = edited ? 'Đã sửa tay' : (autoText(i) !== origKey(i) ? 'Theo từ điển' : 'Giống chữ gốc');
            tr.querySelector('.reset').hidden = !edited;
            const st = status(i), it = items[i];
            const dur = st === 'ready' && it.dur ? ` · ${it.dur.toFixed(1)} giây` : '';
            tr.querySelector('.st').innerHTML = `<span class="badge ${st}">${LABEL[st]}${dur}</span>`
                + (st === 'error' ? `<span class="tts-err">${esc(it.err)}</span>` : '')
                + (st === 'stale' ? '<span class="tts-err" style="color:var(--muted)">Văn bản hoặc giọng đã đổi. Bản cũ vẫn nghe được.</span>' : '');
            tr.querySelector('.listen').disabled = !it.url;
            const regen = tr.querySelector('.regen');
            regen.disabled = st === 'busy' || !readText(i);
            regen.textContent = (it.url || st === 'error') ? 'Tạo lại' : 'Tạo';
        }
        function summary() {
            const n = CUES.length, ready = CUES.filter((_, i) => isReady(i)).length;
            const busy = items.filter(it => it.state === 'busy').length;
            document.getElementById('ttsSummary').textContent = `${ready}/${n} câu sẵn sàng` + (busy ? ` · đang tạo ${busy}` : '');
            document.getElementById('ttsProgress').style.width = (n ? ready / n * 100 : 0) + '%';
            document.dispatchEvent(new Event('vbee:change'));
        }
        function renderAll() { rows.forEach((_, i) => renderRow(i)); summary(); }

        function buildRows() {
            tbody.innerHTML = CUES.map((c, i) => `<tr data-i="${i}">
                <td>${i + 1}</td>
                <td class="orig">${esc(c.text)}</td>
                <td class="read"><textarea rows="2" spellcheck="false" aria-label="Văn bản đọc câu ${i + 1}"></textarea>
                    <div class="meta"><span class="src"></span><button type="button" class="secondary reset" hidden>Khôi phục</button></div></td>
                <td class="st"></td>
                <td class="ops">
                    <button type="button" class="secondary listen">Nghe</button>
                    <button type="button" class="secondary scene">Xem cảnh</button>
                    <button type="button" class="secondary regen">Tạo</button>
                </td>
            </tr>`).join('');
            rows = [...tbody.querySelectorAll('tr')];
            rows.forEach((tr, i) => {
                const ta = tr.querySelector('textarea');
                ta.addEventListener('input', () => {
                    setOverride(i, ta.value);
                    rows.forEach((_, j) => { if (j === i) renderRow(j, true); else if (origKey(j) === origKey(i)) renderRow(j); });
                    summary(); scheduleSave(); scheduleLookup();
                });
                ta.addEventListener('blur', () => { if (!norm(ta.value)) { ta.value = readText(i); renderRow(i); } });
                tr.querySelector('.reset').addEventListener('click', () => {
                    delete S.overrides[origKey(i)];
                    renderAll(); scheduleSave(); scheduleLookup(0);
                });
                tr.querySelector('.listen').addEventListener('click', () => togglePreview(i));
                tr.querySelector('.scene').addEventListener('click', () => previewCue(i));
                tr.querySelector('.regen').addEventListener('click', () => genOne(i, !!items[i].url || status(i) === 'error', genRun));
            });
        }

        // ---------- Giọng, tốc độ, từ điển
        const selVoice = document.getElementById('ttsVoice'), inpVoice = document.getElementById('ttsVoiceCustom');
        const selSpeed = document.getElementById('ttsSpeed'), taDict = document.getElementById('ttsDict');
        if ([...selVoice.options].some(o => o.value === S.voice)) selVoice.value = S.voice;
        else { selVoice.value = '__custom'; inpVoice.hidden = false; inpVoice.value = S.voice; }
        if (![...selSpeed.options].some(o => o.value === S.speed)) selSpeed.add(new Option(S.speed + 'x', S.speed));
        selSpeed.value = S.speed;
        taDict.value = S.dict.map(([f, t]) => `${f} = ${t}`).join('\n');

        function settingsChanged() {
            genRun++; setBusy(false); stopPreview();
            renderAll(); scheduleSave(); scheduleLookup(0);
        }
        function applyVoice() {
            const custom = selVoice.value === '__custom';
            inpVoice.hidden = !custom;
            const v = custom ? inpVoice.value.trim() : selVoice.value;
            inpVoice.setAttribute('aria-invalid', custom && !VOICE_RE.test(v) ? 'true' : 'false');
            if (!VOICE_RE.test(v) || v === S.voice) return;
            S.voice = v; settingsChanged();
        }
        selVoice.addEventListener('change', () => { applyVoice(); if (selVoice.value === '__custom') inpVoice.focus(); });
        let voiceTimer = null;
        inpVoice.addEventListener('input', () => { clearTimeout(voiceTimer); voiceTimer = setTimeout(applyVoice, 600); });
        selSpeed.addEventListener('change', () => { S.speed = selSpeed.value; settingsChanged(); });

        let dictTimer = null;
        taDict.addEventListener('input', () => {
            clearTimeout(dictTimer);
            dictTimer = setTimeout(() => {
                S.dict = taDict.value.split('\n').map(l => {
                    const k = l.indexOf('=');
                    return k > 0 ? [norm(l.slice(0, k)), norm(l.slice(k + 1))] : null;
                }).filter(p => p && p[0] && !p[0].startsWith('#'));
                compileDict(); renderAll(); scheduleSave(); scheduleLookup();
            }, 400);
        });

        function markPlaying(i) { rows.forEach((tr, j) => tr.classList.toggle('playing', j === i)); }
        function missing() { return CUES.filter((_, i) => !isReady(i)).length; }

        compileDict();
        buildRows();
        renderAll();
        lookup();
        return { readText, urlFor, markPlaying, missing, stopPreview, configured: !!TTS_INIT.configured };
    })();

    // ---------------------------------------------------------------- Giọng đọc khi phát
    const optVoice = document.getElementById('optVoice');
    const voiceMode = () => optVoice.value;
    const synth = window.speechSynthesis;
    let viVoice = null;
    function updateVoiceNote() {
        const note = document.getElementById('voiceNote');
        let msg = '';
        if (voiceMode() === 'vbee') {
            const m = Vbee.missing();
            if (m) msg = `${m}/${CUES.length} câu chưa có giọng Vbee khớp với văn bản đọc hiện tại - các câu này sẽ chạy im lặng theo thời gian ước tính. Tạo giọng ở mục "Giọng đọc Vbee" bên dưới.`;
        } else if (voiceMode() === 'browser' && !viVoice) {
            msg = 'Trình duyệt chưa có giọng đọc tiếng Việt, bản tin sẽ chạy theo thời gian ước tính.';
        }
        note.textContent = msg;
        note.hidden = !msg;
    }
    function pickVoice() {
        if (!synth) return;
        const vs = synth.getVoices();
        viVoice = vs.find(v => /^vi(-|_|$)/i.test(v.lang) && /google|natural|online/i.test(v.name)) || vs.find(v => /^vi(-|_|$)/i.test(v.lang)) || null;
        updateVoiceNote();
    }
    if (!Vbee.configured) optVoice.value = 'browser';
    if (synth) { pickVoice(); synth.onvoiceschanged = pickVoice; }
    optVoice.addEventListener('change', () => { stopVoice(); updateVoiceNote(); });
    document.addEventListener('vbee:change', updateVoiceNote);
    updateVoiceNote();

    const rate = () => parseFloat(document.getElementById('optRate').value) || 1;
    const estDur = text => Math.max(2200, (text.trim().split(/\s+/).length * 330 + 700) / rate());

    function speakBrowser(text, dur, tok) {
        if (!synth || !viVoice) return sleep(dur);
        return new Promise(res => {
            let done = false;
            const finish = () => { if (!done) { done = true; clearInterval(watch); res(); } };
            const u = new SpeechSynthesisUtterance(text);
            u.voice = viVoice; u.lang = viVoice.lang; u.rate = rate();
            u.onend = finish; u.onerror = finish;
            synth.cancel();
            synth.speak(u);
            setTimeout(finish, dur * 3 + 4000);
            const watch = setInterval(() => { if (tok !== token) finish(); }, 200);
        });
    }

    let curAudio = null;
    const audioEls = new Map();
    function audioFor(url) {
        let a = audioEls.get(url);
        if (!a) { a = new Audio(); a.preload = 'auto'; a.src = url; audioEls.set(url, a); }
        return a;
    }
    // Đọc metadata để biết CHÍNH XÁC độ dài câu đọc -> hoạt ảnh bản đồ co giãn khớp giọng.
    function loadAudio(url) {
        const a = audioFor(url);
        if (a.readyState >= 1 && isFinite(a.duration) && a.duration > 0) return Promise.resolve(a);
        return new Promise(res => {
            const done = ok => { a.removeEventListener('loadedmetadata', onOk); a.removeEventListener('error', onErr); clearTimeout(t); res(ok ? a : null); };
            const onOk = () => done(isFinite(a.duration) && a.duration > 0), onErr = () => { audioEls.delete(url); done(false); };
            a.addEventListener('loadedmetadata', onOk);
            a.addEventListener('error', onErr);
            const t = setTimeout(() => done(isFinite(a.duration) && a.duration > 0), 8000);
            if (a.networkState === HTMLMediaElement.NETWORK_EMPTY || a.networkState === HTMLMediaElement.NETWORK_NO_SOURCE) a.load();
        });
    }
    // Ghi video: mọi file mp3 Vbee đi qua AudioContext -> vừa nghe được, vừa trộn vào luồng ghi.
    // Một thẻ <audio> chỉ tạo được 1 MediaElementSource nên lưu lại để dùng cho các lần ghi sau.
    let recCtx = null, recDest = null;
    const recSources = new WeakMap();
    function recAudioGraph() {
        if (!recCtx) {
            recCtx = new (window.AudioContext || window.webkitAudioContext)();
            recDest = recCtx.createMediaStreamDestination();
        }
        return recCtx;
    }
    function routeAudio(a) {
        if (!recCtx || recSources.has(a)) return;
        try {
            const s = recCtx.createMediaElementSource(a);
            s.connect(recCtx.destination);
            s.connect(recDest);
            recSources.set(a, s);
        } catch (e) { console.warn('Không nối được âm thanh vào luồng ghi:', e); }
    }

    function playAudio(a, tok) {
        routeAudio(a);
        const ms = a.duration * 1000 / rate();
        return new Promise(res => {
            let done = false;
            const finish = () => {
                if (done) return;
                done = true; clearInterval(watch); clearTimeout(guard);
                a.removeEventListener('ended', finish); a.removeEventListener('error', finish);
                if (curAudio === a) curAudio = null;
                res();
            };
            a.pause(); a.currentTime = 0; a.playbackRate = rate();
            a.addEventListener('ended', finish);
            a.addEventListener('error', finish);
            curAudio = a;
            // Trình duyệt chặn tự phát -> vẫn giữ đúng nhịp câu bằng thời lượng thật của file.
            a.play().catch(() => setTimeout(finish, ms));
            const guard = setTimeout(finish, ms + 4000);
            const watch = setInterval(() => { if (tok !== token) { a.pause(); finish(); } }, 150);
        });
    }
    function stopVoice() {
        synth?.cancel();
        if (curAudio) { curAudio.pause(); curAudio = null; }
    }

    // { dur, run }: dur = độ dài thật của câu (ms) để hành động bản đồ chạy vừa khít giọng đọc.
    async function prepareVoice(i) {
        const mode = voiceMode(), read = Vbee.readText(i);
        if (mode === 'vbee') {
            const url = Vbee.urlFor(i);
            const a = url ? await loadAudio(url) : null;
            if (a) return { dur: a.duration * 1000 / rate(), run: tok => playAudio(a, tok) };
        }
        const dur = estDur(read);
        if (mode === 'browser') return { dur, run: tok => speakBrowser(read, dur, tok) };
        return { dur, run: () => sleep(dur) };
    }

    // Hành động bản đồ "xong" = hoạt ảnh xong + camera dừng hẳn + đủ thời gian cho hiệu ứng CSS (nhãn bật, vòng sóng).
    const ACT_HOLD = { position: 1300, wind: 1600, radius7: 300, radius10: 300, land: 500, dissipate: 900, history: 700, rank: 700 };
    async function runActionFull(c, dur) {
        const t0 = performance.now();
        await runAction(c, dur, false);
        await waitCam();
        const rest = (ACT_HOLD[c.act] || 0) - (performance.now() - t0);
        if (rest > 0) await sleep(rest);
    }

    // ---------------------------------------------------------------- Trình phát
    let cur = -1, playing = false, stopAt = null, onPlayEnd = null;
    const cueEls = [...document.querySelectorAll('.script .cue')];
    const btnPlay = document.getElementById('btnPlay');

    function highlight(i) {
        cueEls.forEach((el, j) => { el.classList.toggle('active', j === i); el.classList.toggle('done', j < i); });
        document.getElementById('progress').style.width = (i < 0 ? 0 : ((i + 1) / CUES.length) * 100) + '%';
    }

    async function playCue(i) {
        if (i >= CUES.length) {
            // Kết thúc bản tin: lùi ra toàn cảnh để người xem thấy cả đường đi dự báo.
            stormClass('blink', false);
            hidePanel();
            camWide(2);
            Vbee.markPlaying(-1);
            setPlaying(false);
            if (onPlayEnd) { const f = onPlayEnd; onPlayEnd = null; f(); }
            return;
        }
        const tok = ++token;
        cur = i;
        const c = CUES[i];
        highlight(i);
        stopVoice();
        Vbee.stopPreview();
        // Lấy độ dài thật của câu đọc TRƯỚC khi bắt đầu -> chữ, giọng và hoạt ảnh xuất phát cùng lúc.
        const voice = await prepareVoice(i);
        if (tok !== token || !playing) return;
        const nextUrl = CUES[i + 1] && voiceMode() === 'vbee' ? Vbee.urlFor(i + 1) : null;
        if (nextUrl) audioFor(nextUrl);
        document.getElementById('subtitle').textContent = c.text;
        Vbee.markPlaying(i);
        if (recState.t0) recState.cueStarts[i] = i === 0 ? 0 : (performance.now() - recState.t0) / 1000;
        fxLayer.clearLayers(); tagMarker = null;
        stormClass('blink', false);
        if (!PANEL_ACTS.has(c.act)) hidePanel();
        // Chỉ sang câu/cảnh tiếp khi CẢ giọng đọc xong VÀ bản đồ đã vẽ xong.
        await Promise.all([voice.run(tok), runActionFull(c, voice.dur)]);
        if (tok !== token || !playing) return;
        if (stopAt === i) { stopAt = null; Vbee.markPlaying(-1); setPlaying(false); return; }
        await sleep(c.act === 'intro' || (CUES[i + 1] && CUES[i + 1].scene !== c.scene) ? 600 : 300);
        if (tok === token && playing) playCue(i + 1);
    }

    // Xem lại đúng 1 câu: dựng trạng thái bản đồ trước câu, phát câu đó (giọng + hoạt ảnh) rồi dừng.
    function previewCue(i) {
        stopAt = i;
        seek(i, true);
        document.getElementById('stage').scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    // Dựng lại toàn bộ trạng thái bản đồ trước câu i, rồi phát câu i -> luôn đồng bộ khi tua.
    async function seek(i, autoplay) {
        stopVoice();
        resetScene();
        for (let j = 0; j < i; j++) await runAction(CUES[j], 0, true);
        if (i > 0) {
            const last = [...CUES.slice(0, i)].reverse().find(c => hasXY(PTS[c.pt]));
            if (last) map.fitBounds(L.latLngBounds([...allLL, LL(PTS[last.pt])]), { padding: [70, 70], maxZoom: 6, animate: false });
        }
        cur = i;
        highlight(i - 1);
        if (autoplay) { setPlaying(true); playCue(i); }
    }

    function setPlaying(on) {
        playing = on;
        btnPlay.textContent = on ? 'Tạm dừng' : (cur >= CUES.length - 1 && cur >= 0 && !on ? 'Phát lại' : 'Phát');
    }

    btnPlay.addEventListener('click', () => {
        if (playing) { setPlaying(false); token++; stopVoice(); return; }
        stopAt = null;
        const start = (cur < 0 || cur >= CUES.length - 1 && btnPlay.textContent === 'Phát lại') ? 0 : cur;
        seek(start, true);
    });
    document.getElementById('btnReset').addEventListener('click', () => { stopAt = null; setPlaying(false); seek(0, false); cur = -1; highlight(-1); Vbee.markPlaying(-1); });
    document.getElementById('btnPrev').addEventListener('click', () => { stopAt = null; seek(Math.max(0, (cur < 0 ? 0 : cur) - 1), true); });
    document.getElementById('btnNext').addEventListener('click', () => { stopAt = null; seek(Math.min(CUES.length - 1, (cur < 0 ? 0 : cur) + 1), true); });
    cueEls.forEach(el => {
        const go = () => { stopAt = null; seek(parseInt(el.dataset.i, 10), true); };
        el.addEventListener('click', go);
        el.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } });
    });
    document.getElementById('btnFull').addEventListener('click', () => {
        const st = document.getElementById('stage');
        if (document.fullscreenElement) document.exitFullscreen(); else st.requestFullscreen?.();
    });
    document.addEventListener('fullscreenchange', () => setTimeout(() => map.invalidateSize(), 150));

    // ---------------------------------------------------------------- T��o video (ghi lại sân khấu bản đồ + giọng đọc)
    //  1. getDisplayMedia chụp chính tab này, cắt đúng khung #stage (Region Capture, nếu không có thì tự cắt theo toạ độ).
    //  2. Vẽ từng khung hình lên canvas đúng độ phân gi��i đã chọn, trộn âm thanh Vbee qua AudioContext.
    //  3. MediaRecorder ghi toàn bộ bản tin; cảnh cuối (đã ẩn phụ đề) được chụp làm thumbnail.
    const YT_INIT = <?= json_encode(['meta' => $ytMeta, 'clientId' => vb_yt_client_id(), 'scopes' => VB_YT_SCOPES, 'storm' => $selected['id']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const byId = id => document.getElementById(id);
    const REC_RES = { 720: [1280, 720, 6e6], 1080: [1920, 1080, 12e6], 1440: [2560, 1440, 24e6], 2160: [3840, 2160, 45e6] };
    const REC_MIMES = [
        'video/mp4;codecs=avc1.640033,mp4a.40.2',
        'video/mp4;codecs=avc1,mp4a.40.2',
        'video/mp4',
        'video/webm;codecs=vp9,opus',
        'video/webm;codecs=vp8,opus',
        'video/webm',
    ];
    const fmtBytes = b => b >= 1073741824 ? (b / 1073741824).toFixed(2) + ' GB' : b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.round(b / 1024) + ' KB';
    const fmtClock = s => Math.floor(s / 60) + ':' + String(Math.floor(s % 60)).padStart(2, '0');
    const recState = { busy: false, video: null, thumb: null, urls: [], t0: 0, cueStarts: [] };
    const recStatusEl = byId('recStatus');
    const recStatus = msg => { recStatusEl.textContent = msg; };

    function waitTiles(ms = 5000) {
        const t0 = performance.now();
        return (async () => { while (base.isLoading && base.isLoading() && performance.now() - t0 < ms) await sleep(150); })();
    }
    const canvasBlob = (cv, type, q) => new Promise(res => cv.toBlob(res, type, q));
    // YouTube: thumbnail tối đa 2 MB, khuyên dùng 1280x720.
    async function makeThumb(src) {
        const tc = document.createElement('canvas');
        tc.width = 1280; tc.height = 720;
        const g = tc.getContext('2d');
        g.imageSmoothingQuality = 'high';
        g.drawImage(src, 0, 0, 1280, 720);
        for (const q of [0.92, 0.85, 0.75, 0.6]) {
            const b = await canvasBlob(tc, 'image/jpeg', q);
            if (b && b.size < 2 * 1024 * 1024) return b;
        }
        return null;
    }
    function setRecUi(on) {
        byId('btnRec').disabled = on;
        byId('btnRec').textContent = on ? 'Đang ghi video...' : 'Tạo video';
        ['btnPlay', 'btnPrev', 'btnNext', 'btnReset', 'btnYtUpload'].forEach(id => { const b = byId(id); if (b) b.disabled = on; });
    }

    async function recordVideo() {
        if (recState.busy) return;
        if (!navigator.mediaDevices?.getDisplayMedia || !window.MediaRecorder || !HTMLCanvasElement.prototype.captureStream) {
            recStatus('Trình duyệt không hỗ trợ ghi video. Hãy dùng Chrome hoặc Edge bản mới nhất trên máy tính.');
            return;
        }
        const mime = REC_MIMES.find(m => MediaRecorder.isTypeSupported(m));
        if (!mime) { recStatus('Trình duyệt không hỗ trợ định dạng MP4/WebM để ghi video.'); return; }
        if (voiceMode() !== 'vbee') {
            if (!confirm('Video chỉ thu được giọng đọc Vbee. Chuyển giọng đọc sang Vbee để ghi?')) return;
            optVoice.value = 'vbee';
            updateVoiceNote();
        }
        const miss = Vbee.missing();
        if (miss && !confirm(`${miss}/${CUES.length} câu chưa có giọng Vbee khớp văn bản đọc hiện tại - các câu này sẽ không có tiếng trong video. Vẫn tiếp tục?`)) return;

        const [W, H, bps] = REC_RES[byId('recRes').value] || REC_RES[1080];
        const fps = parseInt(byId('recFps').value, 10) || 30;
        stopAt = null; onPlayEnd = null; setPlaying(false); token++; stopVoice(); Vbee.stopPreview();
        recAudioGraph();

        // Xin độ phân giải chụp cao hơn kích thư��c tab -> Chrome dựng tab ở mật độ điểm ảnh cao hơn (nét hơn khi xuất 2K/4K).
        const vw = innerWidth, vh = innerHeight, sw = Math.min(vw, vh * 16 / 9), sh = sw * 9 / 16;
        let stream;
        try {
            stream = await navigator.mediaDevices.getDisplayMedia({
                video: { displaySurface: 'browser', frameRate: { ideal: fps, max: fps }, width: { ideal: Math.round(W * vw / sw) }, height: { ideal: Math.round(H * vh / sh) } },
                audio: false,
                preferCurrentTab: true,
                selfBrowserSurface: 'include',
                surfaceSwitching: 'exclude',
                monitorTypeSurfaces: 'exclude',
            });
        } catch (e) {
            recStatus('Đã huỷ: cần cho phép chia sẻ tab này để tạo video.');
            return;
        }
        const track = stream.getVideoTracks()[0];
        const surface = track.getSettings().displaySurface;
        if (surface && surface !== 'browser') {
            stream.getTracks().forEach(t => t.stop());
            recStatus('Cần chọn chia sẻ đúng tab n��y (không chọn cửa sổ hay toàn màn hình).');
            return;
        }

        recState.busy = true;
        setRecUi(true);
        const titleBak = document.title;
        let cancelled = false, cancelMsg = '', finished = false, endWait = null;
        const cancel = msg => {
            if (cancelled || finished) return;
            cancelled = true; cancelMsg = msg;
            onPlayEnd = null; token++; setPlaying(false); stopVoice();
            if (endWait) endWait();
        };
        const onKey = e => { if (e.key === 'Escape') cancel('Đã huỷ ghi video.'); };
        track.addEventListener('ended', () => cancel('Đã dừng chia sẻ tab - video bị huỷ.'));
        document.addEventListener('keydown', onKey);

        let drawTimer = null, progTimer = null, rec = null, recStarted = false, tStart = 0;
        const chunks = [];
        try {
            try { await recCtx.resume(); } catch (e) { /* vẫn ghi hình nếu không có âm thanh */ }
            document.body.classList.add('vb-rec');
            stageEl.classList.add('rec');
            await sleep(400);
            map.invalidateSize();
            hidePanel();
            seek(0, false);
            fitAll(false);
            if (hasXY(PTS[0])) ensureStorm(PTS[0]);

            let cropped = false;
            try {
                if (window.CropTarget && track.cropTo) { await track.cropTo(await CropTarget.fromElement(stageEl)); cropped = true; }
            } catch (e) { console.warn('Region Capture không khả dụng, chuyển sang tự cắt khung:', e); }

            const v = document.createElement('video');
            v.muted = true; v.playsInline = true; v.srcObject = stream;
            await new Promise(res => { if (v.readyState >= 1) res(); else v.onloadedmetadata = res; setTimeout(res, 3000); });
            await v.play().catch(() => {});

            const cv = document.createElement('canvas');
            cv.width = W; cv.height = H;
            const g = cv.getContext('2d', { alpha: false });
            g.imageSmoothingEnabled = true; g.imageSmoothingQuality = 'high';
            const draw = () => {
                if (!v.videoWidth) return;
                if (cropped) { g.drawImage(v, 0, 0, W, H); return; }
                const r = stageEl.getBoundingClientRect();
                const sx = v.videoWidth / innerWidth, sy = v.videoHeight / innerHeight;
                g.drawImage(v, r.left * sx, r.top * sy, r.width * sx, r.height * sy, 0, 0, W, H);
            };
            drawTimer = setInterval(draw, 1000 / fps);

            const out = cv.captureStream(fps);
            recDest.stream.getAudioTracks().forEach(t => out.addTrack(t));
            rec = new MediaRecorder(out, { mimeType: mime, videoBitsPerSecond: bps, audioBitsPerSecond: 192000 });
            rec.ondataavailable = e => { if (e.data && e.data.size) chunks.push(e.data); };
            const stopped = new Promise(res => { rec.onstop = res; });

            await waitTiles();
            await sleep(300);
            if (cancelled) throw new Error('__cancel');
            draw();
            rec.start(1000);
            recStarted = true;
            tStart = performance.now(); recState.t0 = tStart; recState.cueStarts = [];
            progTimer = setInterval(() => {
                const n = Math.max(0, Math.min(CUES.length, cur + 1));
                document.title = `● REC ${n}/${CUES.length} - ${titleBak}`;
                recStatus(`Đang ghi video ${W}×${H}: câu ${n}/${CUES.length} · ${fmtClock((performance.now() - tStart) / 1000)}`);
            }, 500);

            await sleep(1000); // khung hình mở đầu
            if (!cancelled) await new Promise(res => { endWait = res; onPlayEnd = res; seek(0, true); });

            if (!cancelled) {
                // Cảnh cuối: toàn cảnh đường đi, ẩn phụ đề -> vừa là đoạn kết của video vừa là thumbnail.
                byId('subtitle').textContent = '';
                hidePanel();
                await waitCam();
                await waitTiles(3000);
                await sleep(800);
                draw();
                recState.thumb = await makeThumb(cv);
                await sleep(2200);
            }
            finished = true;
            if (rec.state !== 'inactive') rec.stop();
            await stopped;
        } catch (e) {
            if (e.message !== '__cancel') { cancelled = true; cancelMsg = 'Lỗi khi ghi video: ' + e.message; }
            if (rec && recStarted && rec.state !== 'inactive') rec.stop();
        } finally {
            finished = true;
            clearInterval(drawTimer); clearInterval(progTimer); recState.t0 = 0;
            document.removeEventListener('keydown', onKey);
            stream.getTracks().forEach(t => t.stop());
            document.body.classList.remove('vb-rec');
            stageEl.classList.remove('rec');
            document.title = titleBak;
            setTimeout(() => map.invalidateSize(), 100);
            recState.busy = false;
            setRecUi(false);
        }

        if (cancelled || !chunks.length) { recStatus(cancelMsg || 'Không ghi được dữ liệu video.'); return; }
        const type = mime.split(';')[0];
        showVideo(new Blob(chunks, { type }), type, W, H, fps, (performance.now() - tStart) / 1000, recState.cueStarts.length === CUES.length ? recState.cueStarts : null);
    }

    // ---------------------------------------------------------------- Mốc thời gian (chapters) cho mô tả YouTube
    const CHAPTER_HEAD = 'MỐC THỜI GIAN:';
    const stamp = s => {
        s = Math.max(0, Math.floor(s));
        const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), r = s % 60;
        return (h ? h + ':' + String(m).padStart(2, '0') : String(m)) + ':' + String(r).padStart(2, '0');
    };
    function sceneTitle(cues, k, state) {
        const acts = cues.map(c => c.act);
        const land = cues.find(c => c.act === 'land');
        const pt = cues[0].pt;
        if (acts.includes('greet')) return 'Mở đầu';
        if (acts.includes('outro')) return 'Khuyến cáo và lời kết';
        if (acts.includes('history')) return 'Lịch sử tên bão';
        if (acts.includes('rank')) return 'Xếp hạng cường độ trong năm';
        if (pt > 0) {
            const base = 'Dự báo ' + (PTS[pt] && PTS[pt].time ? PTS[pt].time : 'tiếp theo');
            return land && land.land && land.land.onLand && land.land.provShort ? base + ' - trên đất liền ' + land.land.provShort : base;
        }
        if (acts.includes('position') || acts.includes('dissipate')) { state.cur = true; return 'Vị trí và cường độ hiện tại'; }
        return state.cur ? 'Hướng di chuyển hiện tại' : 'Thông tin nổi bật';
    }
    function buildChapters(starts) {
        if (!Array.isArray(starts) || starts.length !== CUES.length) return [];
        const scenes = [];
        CUES.forEach((c, i) => {
            const last = scenes[scenes.length - 1];
            if (last && last.scene === c.scene) last.cues.push(c);
            else scenes.push({ scene: c.scene, cues: [c], t: starts[i] });
        });
        const state = { cur: false };
        const out = [];
        scenes.forEach((s, k) => {
            const title = sceneTitle(s.cues, k, state);
            const t = k === 0 ? 0 : s.t;
            const prev = out[out.length - 1];
            if (prev && t - prev.t < 10) return; // YouTube yêu cầu mỗi chương tối thiểu 10 giây
            out.push({ t, title });
        });
        return out.length >= 3 ? out : [];
    }
    function applyChapters(starts) {
        const descEl = byId('ytDesc');
        const list = buildChapters(starts);
        if (!list.length) return false;
        const block = CHAPTER_HEAD + '\n' + list.map(c => stamp(c.t) + ' ' + c.title).join('\n');
        let d = descEl.value.replace(new RegExp('\\n*' + CHAPTER_HEAD + '\\n(?:\\d+:\\d\\d(?::\\d\\d)? [^\\n]*\\n?)+', 'u'), '\n');
        const marker = 'NỘI DUNG BẢN TIN:';
        d = d.includes(marker) ? d.replace(marker, block + '\n\n' + marker) : d.trimEnd() + '\n\n' + block;
        const size = s => new Blob([s]).size;
        const tailAt = d.indexOf('\n\nNguồn dữ liệu:');
        while (size(d) > 4950 && tailAt > -1) {
            const i = d.indexOf('\n\n' + marker), j = d.indexOf('\n\nNguồn dữ liệu:');
            const body = d.slice(i, j), cut = body.lastIndexOf('\n\n');
            if (cut <= marker.length + 2) break;
            d = d.slice(0, i) + body.slice(0, cut) + d.slice(j);
        }
        descEl.value = d;
        descEl.dispatchEvent(new Event('input'));
        return true;
    }

    function showVideo(blob, type, W, H, fps, secs, cueStarts) {
        if (cueStarts) applyChapters(cueStarts);
        recState.urls.forEach(u => URL.revokeObjectURL(u));
        recState.urls = [];
        recState.video = blob;
        const ext = type.includes('mp4') ? 'mp4' : 'webm';
        const fname = (YT_INIT.meta?.file || 'ban-tin-bao') + '-' + H + 'p';
        const vUrl = URL.createObjectURL(blob);
        recState.urls.push(vUrl);
        byId('vidPreview').src = vUrl;
        byId('vidDownload').href = vUrl;
        byId('vidDownload').download = fname + '.' + ext;
        const thumbLink = byId('thumbDownload');
        if (recState.thumb) {
            const tUrl = URL.createObjectURL(recState.thumb);
            recState.urls.push(tUrl);
            byId('vidThumb').src = tUrl;
            byId('vidPreview').poster = tUrl;
            thumbLink.href = tUrl;
            thumbLink.download = fname + '-thumbnail.jpg';
            thumbLink.hidden = false;
        } else {
            thumbLink.hidden = true;
        }
        byId('vidInfo').textContent = `${W}×${H} · ${fps} fps · ${fmtClock(secs)} · ${fmtBytes(blob.size)} · ${ext.toUpperCase()}`;
        byId('vidResult').hidden = false;
        recStatus('Đã tạo xong video. Kiểm tra lại tiêu đề, mô tả, tag rồi bấm "Upload YouTube".');
        byId('vidResult').scrollIntoView({ behavior: 'smooth', block: 'start' });
        Yt.check(false);
    }
    // ---------------------------------------------------------------- Dựng video trên máy chủ (PHP GD + ffmpeg)
    //  Trình duyệt chỉ gửi lệnh (kèm danh sách mp3 Vbee đã tạo) rồi hỏi tiến độ. Máy chủ tự dựng nền nên đóng tab vẫn chạy tiếp.
    const srv = { job: null, timer: null };
    const srvUrl = q => location.pathname + '?storm=' + encodeURIComponent(YT_INIT.storm) + '&' + q;
    const recMode = () => byId('recMode').value;
    function applyRecMode() {
        const server = recMode() === 'server';
        byId('recHelpServer').hidden = !server;
        byId('recHelpBrowser').hidden = server;
    }
    byId('recMode').addEventListener('change', applyRecMode);
    applyRecMode();

    function setSrvUi(on) {
        setRecUi(on);
        byId('btnRec').textContent = on ? 'Đang dựng trên máy chủ...' : 'Tạo video';
        byId('btnRecCancel').hidden = !on;
        byId('recProgress').style.width = on ? byId('recProgress').style.width : '0';
    }

    async function srvStart() {
        if (recState.busy) return;
        const miss = Vbee.missing();
        if (miss && !confirm(`${miss}/${CUES.length} câu chưa có giọng Vbee khớp văn bản đọc hiện tại - các câu này sẽ không có tiếng trong video. Vẫn tiếp tục?`)) return;
        const fd = new FormData();
        fd.append('res', byId('recRes').value);
        fd.append('fps', byId('recFps').value);
        fd.append('base', byId('optBase').value);
        fd.append('tts', JSON.stringify(CUES.map((_, i) => Vbee.urlFor(i) || '')));
        recState.busy = true;
        setSrvUi(true);
        recStatus('Đang gửi lệnh dựng video tới máy chủ...');
        let j = null;
        try {
            const r = await fetch(srvUrl('ff=start'), { method: 'POST', body: fd });
            const raw = await r.text();
            try { j = JSON.parse(raw); } catch (e) { j = { ok: false, error: 'Máy chủ trả về dữ liệu không hợp lệ: ' + raw.slice(0, 200) }; }
        } catch (e) {
            j = { ok: false, error: 'Không gọi được máy chủ (' + e.message + ').' };
        }
        if (!j || !j.ok) {
            recState.busy = false;
            setSrvUi(false);
            recStatus('Không bắt đầu được: ' + ((j && j.error) || 'lỗi không rõ'));
            return;
        }
        srvWatch(j.job);
    }

    function srvWatch(job) {
        srv.job = job;
        recState.busy = true;
        setSrvUi(true);
        clearTimeout(srv.timer);
        const tick = async () => {
            let j = null;
            try { j = await (await fetch(srvUrl('ff=status&job=' + encodeURIComponent(job)), { cache: 'no-store' })).json(); }
            catch (e) { recStatus('Mất kết nối tạm thời tới máy chủ, đang thử lại... (video vẫn đang được dựng)'); srv.timer = setTimeout(tick, 3000); return; }
            if (!j || !j.ok) { recState.busy = false; setSrvUi(false); recStatus('Không tìm thấy tiến trình dựng trên máy chủ.'); return; }
            byId('recProgress').style.width = Math.round((j.progress || 0) * 100) + '%';
            if (j.state === 'running' || j.state === 'queued') {
                recStatus(`${j.stage || 'Đang dựng'} · ${Math.round((j.progress || 0) * 100)}%`
                    + (j.frame && j.frames ? ` · khung ${j.frame}/${j.frames}` : '')
                    + (j.cue ? ` · câu ${j.cue}/${CUES.length}` : '')
                    + (j.eta ? ` · còn khoảng ${fmtClock(j.eta)}` : '')
                    + ' · có thể đóng tab, máy chủ vẫn dựng tiếp');
                srv.timer = setTimeout(tick, 1500);
                return;
            }
            recState.busy = false;
            setSrvUi(false);
            if (j.state === 'done') await srvFinish(j);
            else if (j.state === 'cancelled') recStatus('Đã huỷ dựng video.');
            else if (j.state === 'stale') recStatus('Tiến trình dựng trên máy chủ đã dừng đột ngột (có thể do giới hạn thời gian chạy hoặc bộ nhớ của PHP). Thử độ phân giải thấp hơn hoặc nâng giới hạn của máy chủ.');
            else {
                recStatus('Lỗi dựng video: ' + (j.error || 'không rõ') + (j.log ? ' (xem chi tiết trong console)' : ''));
                if (j.log) console.warn('[ffmpeg]\n' + j.log);
            }
        };
        tick();
    }

    async function srvFinish(j) {
        recStatus('Đã dựng xong, đang tải video từ máy chủ...');
        byId('btnRecLoad').hidden = true;
        try {
            const r = await fetch(srvUrl('ff=get&job=' + encodeURIComponent(j.job) + '&file=out.mp4'), { cache: 'no-store' });
            if (!r.ok) throw new Error('HTTP ' + r.status);
            const blob = new Blob([await r.blob()], { type: 'video/mp4' });
            recState.thumb = null;
            if (j.hasThumb) {
                try {
                    const tr = await fetch(srvUrl('ff=get&job=' + encodeURIComponent(j.job) + '&file=thumb.jpg'), { cache: 'no-store' });
                    if (tr.ok) recState.thumb = await tr.blob();
                } catch (e) { /* không có thumbnail vẫn dùng được video */ }
            }
            showVideo(blob, 'video/mp4', j.w, j.h, j.fps, j.secs, j.cueStarts);
            if (j.noAudio) recStatus('Đã dựng xong video nhưng KHÔNG có tiếng (chưa có giọng Vbee khớp hoặc ghép âm thanh lỗi).');
        } catch (e) {
            recStatus('Không tải được video từ máy chủ: ' + e.message);
            byId('btnRecLoad').hidden = false;
            byId('btnRecLoad').onclick = () => srvFinish(j);
        }
    }

    byId('btnRecCancel').addEventListener('click', async () => {
        if (!srv.job) return;
        const fd = new FormData();
        fd.append('job', srv.job);
        try { await fetch(srvUrl('ff=cancel'), { method: 'POST', body: fd }); recStatus('Đang huỷ dựng video...'); } catch (e) { recStatus('Không gửi được lệnh huỷ: ' + e.message); }
    });

    byId('btnRec').addEventListener('click', () => (recMode() === 'server' ? srvStart() : recordVideo()));

    // Mở lại trang: nối tiếp job đang chạy, hoặc cho tải video đã dựng xong.
    (async () => {
        try {
            const j = await (await fetch(srvUrl('ff=latest'), { cache: 'no-store' })).json();
            if (!j || !j.ok || j.none) return;
            if (j.state === 'running' || j.state === 'queued') { byId('recMode').value = 'server'; applyRecMode(); srvWatch(j.job); }
            else if (j.state === 'done' && Date.now() / 1000 - (j.updated || 0) < 6 * 3600) {
                const btn = byId('btnRecLoad');
                btn.hidden = false;
                btn.textContent = `Mở video đã dựng trên máy chủ (${j.w}×${j.h})`;
                btn.onclick = () => { byId('recMode').value = 'server'; applyRecMode(); srvFinish(j); };
            }
        } catch (e) { /* bỏ qua: chưa có job nào */ }
    })();

    // ---------------------------------------------------------------- YouTube: đăng nhập + upload (theo news.php)
    const Yt = (() => {
        let ytToken = null, tokenClient = null;
        const authEl = byId('ytAuth'), statusEl = byId('ytStatus'), barEl = byId('ytProgress');
        const titleEl = byId('ytTitle'), descEl = byId('ytDesc'), tagsEl = byId('ytTags');
        const status = (msg, html) => { if (html) statusEl.innerHTML = msg; else statusEl.textContent = msg; };

        async function post(data) {
            const fd = new FormData();
            Object.entries(data).forEach(([k, v]) => fd.append(k, v));
            const r = await fetch(location.pathname + location.search, { method: 'POST', body: fd });
            const j = await r.json().catch(() => null);
            if (!j) throw new Error('Máy chủ trả về dữ liệu không hợp lệ (HTTP ' + r.status + ')');
            if (!j.ok) throw new Error(j.error || 'Lỗi không xác định');
            return j;
        }

        // ---------- Tiêu đ��� / mô tả / tag
        const byteLen = s => new Blob([s]).size;
        const parseTags = () => [...new Map(tagsEl.value.split(',').map(t => t.replace(/[<>"]/g, '').trim()).filter(Boolean).map(t => [t.toLowerCase(), t])).values()];
        const tagCost = tags => tags.reduce((n, t) => n + t.length + (t.includes(' ') ? 2 : 0) + 1, 0);
        function counters() {
            const tl = titleEl.value.length, db = byteLen(descEl.value), tc = tagCost(parseTags());
            const set = (id, txt, over) => { const el = byId(id); el.textContent = txt; el.classList.toggle('over', over); };
            set('ytTitleCnt', `${tl}/100`, tl > 100 || tl === 0);
            set('ytDescCnt', `${db}/5000 byte`, db > 5000);
            set('ytTagsCnt', `${tc}/500`, tc > 500);
        }
        function fillMeta() {
            const m = YT_INIT.meta || { title: '', description: '', tags: [] };
            titleEl.value = m.title; descEl.value = m.description; tagsEl.value = m.tags.join(', ');
            counters();
        }
        [titleEl, descEl, tagsEl].forEach(el => el.addEventListener('input', counters));
        byId('btnYtReset').addEventListener('click', () => { if (confirm('Khôi phục tiêu đề, mô tả và tag tự động? Nội dung đã sửa sẽ mất.')) fillMeta(); });

        // ---------- Đăng nhập
        function renderAuth() {
            authEl.textContent = '';
            const add = (tag, cls, text, onClick) => {
                const el = document.createElement(tag);
                if (cls) el.className = cls;
                el.textContent = text;
                if (onClick) { el.type = 'button'; el.addEventListener('click', onClick); }
                authEl.appendChild(el);
            };
            if (ytToken) {
                add('span', 'ok', 'Đã đăng nhập YouTube');
                add('button', 'secondary', 'Đăng xuất', logout);
            } else {
                add('span', 'muted', 'Chưa đăng nhập YouTube.');
                add('button', '', 'Đăng nhập (lưu lâu dài)', loginFull);
                add('button', 'secondary', 'Đăng nhập nhanh', loginQuick);
            }
        }
        async function check(force) {
            try {
                const j = await post({ action: 'yt_check', force: force ? 1 : 0 });
                ytToken = j.has_token ? j.access_token : null;
            } catch (e) { ytToken = null; }
            renderAuth();
            if (ytToken) loadChannels();
            return !!ytToken;
        }
        async function loginFull() {
            if (recState.video && !confirm('Đăng nhập lưu lâu dài sẽ chuyển sang trang Google, video vừa tạo sẽ bị mất. Hãy tải video xuống trước hoặc dùng "Đăng nhập nhanh". Vẫn tiếp tục?')) return;
            try {
                const j = await post({ action: 'yt_auth_url', storm: YT_INIT.storm });
                location.href = j.auth_url;
            } catch (e) { status('Không tạo được link đăng nhập Google: ' + e.message); }
        }
        // Cửa sổ bật lên của Google Identity Services -> không rời trang, giữ nguyên video vừa tạo.
        function loginQuick() {
            if (!window.google?.accounts?.oauth2) { status('Thư viện đăng nhập Google chưa tải xong, thử lại sau giây lát.'); return; }
            if (!tokenClient) {
                tokenClient = google.accounts.oauth2.initTokenClient({
                    client_id: YT_INIT.clientId,
                    scope: YT_INIT.scopes,
                    callback: async r => {
                        if (!r.access_token) { status('Không đăng nhập được Google: ' + (r.error || 'không rõ lỗi')); return; }
                        ytToken = r.access_token;
                        try { await post({ action: 'yt_save_token', access_token: r.access_token, expires_in: r.expires_in || 3600 }); } catch (e) { /* vẫn dùng được trong phiên này */ }
                        renderAuth();
                        loadChannels();
                    },
                });
            }
            tokenClient.requestAccessToken();
        }
        async function logout() {
            try { await post({ action: 'yt_revoke' }); } catch (e) { /* vẫn xoá phía trình duyệt */ }
            ytToken = null;
            renderAuth();
            byId('ytChannel').innerHTML = '<option value="">-- Chưa đăng nhập --</option>';
        }
        async function loadChannels() {
            const sel = byId('ytChannel');
            sel.innerHTML = '<option value="">-- Đang tải kênh... --</option>';
            try {
                const r = await fetch('https://www.googleapis.com/youtube/v3/channels?part=snippet&mine=true', { headers: { Authorization: 'Bearer ' + ytToken } });
                if (!r.ok) throw new Error('HTTP ' + r.status);
                const items = (await r.json()).items || [];
                sel.textContent = '';
                if (!items.length) sel.add(new Option('-- Tài khoản chưa có kênh --', ''));
                items.forEach(ch => sel.add(new Option(ch.snippet.title, ch.id)));
            } catch (e) {
                sel.innerHTML = '<option value="">-- Lỗi tải kênh --</option>';
            }
        }

        // ---------- Upload (resumable) -> đặt thumbnail -> lưu tv_ytb
        function putWithProgress(url, blob) {
            return new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest();
                xhr.upload.addEventListener('progress', e => {
                    if (!e.lengthComputable) return;
                    const pct = Math.round(e.loaded / e.total * 100);
                    barEl.style.width = pct + '%';
                    status(`Đang upload: ${fmtBytes(e.loaded)} / ${fmtBytes(e.total)} (${pct}%)`);
                });
                xhr.addEventListener('load', () => {
                    let j = null;
                    try { j = JSON.parse(xhr.responseText); } catch (e) { /* không phải JSON */ }
                    if (xhr.status >= 200 && xhr.status < 300 && j) resolve(j);
                    else reject(new Error(j?.error?.message || 'Upload thất bại (HTTP ' + xhr.status + ')'));
                });
                xhr.addEventListener('error', () => reject(new Error('Lỗi kết nối mạng')));
                xhr.addEventListener('abort', () => reject(new Error('Upload bị huỷ')));
                xhr.open('PUT', url);
                xhr.setRequestHeader('Authorization', 'Bearer ' + ytToken);
                xhr.setRequestHeader('Content-Type', blob.type || 'video/webm');
                xhr.send(blob);
            });
        }

        async function upload() {
            if (!recState.video) { status('Chưa có video. Bấm "Tạo video" trước.'); return; }
            const title = titleEl.value.replace(/[<>]/g, '').trim();
            const description = descEl.value.replace(/[<>]/g, '');
            const tags = parseTags();
            if (!title || title.length > 100) { status('Tiêu đề phải có từ 1 đến 100 ký tự.'); titleEl.focus(); return; }
            if (byteLen(description) > 5000) { status('Mô tả vượt quá 5000 byte, hãy rút gọn.'); descEl.focus(); return; }
            if (tagCost(tags) > 500) { status('Tổng độ dài tag vượt quá 500 ký tự, hãy bớt tag.'); tagsEl.focus(); return; }
            if (!(await check(false))) { status('Cần đăng nhập YouTube trước khi upload.'); return; }

            const btn = byId('btnYtUpload');
            btn.disabled = true; btn.textContent = 'Đang upload...';
            barEl.style.width = '0%';
            status('Đang khởi tạo phiên upload...');
            const blob = recState.video;
            try {
                const meta = {
                    snippet: { title, description, tags, categoryId: byId('ytCategory').value || '25', defaultLanguage: 'vi', defaultAudioLanguage: 'vi' },
                    status: { privacyStatus: byId('ytPrivacy').value, selfDeclaredMadeForKids: false, embeddable: true },
                };
                const init = await fetch('https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status', {
                    method: 'POST',
                    headers: {
                        Authorization: 'Bearer ' + ytToken,
                        'Content-Type': 'application/json; charset=UTF-8',
                        'X-Upload-Content-Length': String(blob.size),
                        'X-Upload-Content-Type': blob.type || 'video/webm',
                    },
                    body: JSON.stringify(meta),
                });
                if (!init.ok) {
                    const e = await init.json().catch(() => null);
                    throw new Error(e?.error?.message || 'Không khởi tạo được upload (HTTP ' + init.status + ')');
                }
                const uploadUrl = init.headers.get('Location');
                if (!uploadUrl) throw new Error('YouTube không trả về địa chỉ upload');

                const res = await putWithProgress(uploadUrl, blob);
                const id = res.id;
                if (!id) throw new Error('Upload xong nhưng không nhận được mã video');
                barEl.style.width = '100%';
                const videoUrl = 'https://www.youtube.com/watch?v=' + id;

                let thumbNote = '';
                if (recState.thumb) {
                    status('Đang đặt ảnh thumbnail...');
                    const tr = await fetch('https://www.googleapis.com/upload/youtube/v3/thumbnails/set?uploadType=media&videoId=' + encodeURIComponent(id), {
                        method: 'POST',
                        headers: { Authorization: 'Bearer ' + ytToken, 'Content-Type': 'image/jpeg' },
                        body: recState.thumb,
                    }).catch(() => null);
                    if (tr && tr.ok) thumbNote = 'Đã đặt thumbnail cảnh cuối.';
                    else {
                        const e = tr ? await tr.json().catch(() => null) : null;
                        thumbNote = 'Chưa đặt được thumbnail' + (e?.error?.message ? ' (' + e.error.message + ')' : '') + ' - kênh cần xác minh để dùng thumbnail tuỳ chỉnh, có thể tải ảnh xuống và đặt thủ công trong YouTube Studio.';
                    }
                }

                let dbNote = '';
                try { await post({ action: 'yt_log', link_ytb: videoUrl, tieu_de: title, mo_ta: description }); dbNote = 'Đã lưu vào tv_ytb.'; }
                catch (e) { dbNote = 'Chưa lưu được vào tv_ytb: ' + e.message; }

                status(`Upload thành công! <a href="${esc(videoUrl)}" target="_blank" rel="noopener">Xem video</a> · <a href="https://studio.youtube.com/video/${esc(id)}/edit" target="_blank" rel="noopener">YouTube Studio</a><br>${esc(thumbNote)} ${esc(dbNote)}<br>YouTube có thể mất vài phút để xử lý video.`, true);
            } catch (e) {
                status('Lỗi upload: ' + e.message);
            } finally {
                btn.disabled = false; btn.textContent = 'Upload YouTube';
            }
        }
        byId('btnYtUpload').addEventListener('click', upload);

        fillMeta();
        check(false);
        return { check };
    })();

    // Quay về sau khi đăng nhập Google (Authorization Code Flow).
    (() => {
        const qp = new URLSearchParams(location.search);
        const r = qp.get('youtube_auth');
        if (!r) return;
        recStatus(r === 'success' ? 'Đã đăng nhập YouTube thành công. Bấm "Tạo video" để bắt đầu.' : 'Đăng nhập YouTube thất bại, vui lòng thử lại.');
        qp.delete('youtube_auth');
        history.replaceState(null, '', location.pathname + (qp.toString() ? '?' + qp : ''));
    })();

    // Khung hình đầu: chỉ hiện vị trí hiện tại để xem trước.
    if (hasXY(PTS[0])) { ensureStorm(PTS[0]); }
})();
</script>
<?php endif; ?>
</body>
</html>
