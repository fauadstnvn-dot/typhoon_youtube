<?php
// =============================================================================
//  TỰ ĐỘNG HOÁ BẢN TIN BÃO -> VIDEO -> YOUTUBE (chạy bởi GitHub Actions)
//  -----------------------------------------------------------------------------
//  Đặt file này CÙNG THƯ MỤC với video.php (dùng chung ../config.php, tts_cache/, ff_jobs/, youtube_tokens.json).
//
//  Cách hoạt động: GitHub Actions (cron UTC 02:00, 05:00, ... 23:00) gọi lặp lại
//      video_github.php?action=tick      (kèm header X-Auth-Key)
//  Mỗi lần gọi chỉ làm 1 bước ngắn rồi trả JSON {state: working | idle | error} nên không bị timeout.
//  Gọi tới khi state = idle thì dừng.
//
//  Quy trình cho từng cơn bão đang hiện hữu (bảng jma_typhoons), lần lượt từng cơn:
//    1. Tạo bản tin + kịch bản bản đồ Leaflet/dữ liệu bão  -> video.php?storm=ID&api=json
//    2. Tạo giọng đọc Vbee từng câu                           -> video.php (tts_start / tts_poll, có cache)
//    3. Dựng video MP4 trên máy chủ (GD + ffmpeg)             -> video.php (ff=start / ff=status)
//    4. Tự tạo tiêu đề, mô tả, tag, thumbnail (cảnh cuối)
//    5. Upload YouTube (resumable theo từng khúc) + đặt thumbnail + lưu tv_ytb
//  Chống trùng: mỗi bản tin có "bulletin_key" = sha1(mã bão + nội dung bản tin). Đã upload (bảng tv_bao_auto) thì bỏ qua.
//  Không có cơn bão nào / không còn bản tin mới -> trả state=idle ngay.
//
//  Khai báo trong ../config.php:
//    define('VIDEO_GITHUB_KEY', 'chuoi-bi-mat-dai');          // bắt buộc, trùng với GitHub secret VIDEO_KEY
//    define('VIDEO_BASE_URL', 'https://domain/thu-muc/video.php'); // tuỳ chọn (mặc định suy ra từ request)
//    define('YOUTUBE_CLIENT_ID', '...'); define('YOUTUBE_CLIENT_SECRET', '...');  // như video.php
//    // tuỳ chọn: VIDEO_AUTO_RES (720|1080|1440|2160), VIDEO_AUTO_FPS, VIDEO_AUTO_BASE (sat|dark|light),
//    //           VIDEO_AUTO_PRIVACY (public|unlisted|private), VIDEO_AUTO_VOICE, VIDEO_AUTO_MAX (số bão tối đa mỗi lượt)
//  Cần đăng nhập YouTube một lần ở video.php (để có refresh_token trong youtube_tokens.json).
//  Có thể chạy tay bằng CLI: php video_github.php tick | status | reset
//  CHẾ ĐỘ GITHUB RUNNER (ffmpeg chạy trên GitHub): workflow tự cài php/ffmpeg/font, chạy `php -S 127.0.0.1:8080`,
//  đặt VIDEO_BASE_URL=http://127.0.0.1:8080/video.php rồi gọi `php video_github.php tick` lặp lại (xem .github/workflows/storm-video.yml).
// =============================================================================

require_once __DIR__ . '/../config.php';
date_default_timezone_set('Asia/Ho_Chi_Minh');
@set_time_limit(0);
@ini_set('memory_limit', '768M');
ignore_user_abort(true);

const VG_DIR        = __DIR__ . '/auto_jobs';
const VG_STATE      = VG_DIR . '/state.json';
const VG_STALE_SEC  = 21600;      // lượt chạy cũ hơn 6 giờ thì lập kế hoạch lại
const VG_TICK_SEC   = 40;         // thời gian tối đa của 1 lần gọi
const VG_CHUNK      = 33554432;   // 32 MB (bội số của 256 KB theo yêu cầu YouTube)
const VG_MAX_PENDING_TTS = 5;

function vg_conf($key, $def = '') {
    if (defined($key)) return (string)constant($key);
    $v = getenv($key);
    return ($v === false || $v === '') ? $def : (string)$v;
}

function vg_out(array $data, $code = 200) {
    if (PHP_SAPI !== 'cli') {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    exit;
}

function vg_norm($t) {
    return trim(preg_replace('/\s+/u', ' ', (string)$t));
}

// ----------------------------------------------------------------------------- HTTP
function vg_http($method, $url, array $headers = [], $body = null, $timeout = 120) {
    $ch = curl_init($url);
    $headers[] = 'Expect:';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err = curl_error($ch);
    curl_close($ch);
    $head = is_string($raw) ? substr($raw, 0, $hs) : '';
    $text = is_string($raw) ? substr($raw, $hs) : '';
    $hdr = [];
    foreach (preg_split('/\r?\n/', $head) as $line) {
        if (strpos($line, ':') !== false) {
            [$k, $v] = explode(':', $line, 2);
            $hdr[strtolower(trim($k))] = trim($v);
        }
    }
    $json = json_decode($text, true);
    return ['code' => $code, 'headers' => $hdr, 'body' => $text, 'json' => is_array($json) ? $json : null, 'error' => $err];
}

function vg_base() {
    $u = vg_conf('VIDEO_BASE_URL');
    if ($u !== '') return $u;
    if (PHP_SAPI === 'cli') throw new RuntimeException('Chạy CLI cần define VIDEO_BASE_URL trong config.php.');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/video.php';
}

// Gọi video.php (loopback) - tái sử dụng nguyên logic bản tin, Vbee, dựng video.
function vg_video($method, $query, $post = null, $timeout = 90) {
    $base = vg_base();
    $url = $base . (strpos($base, '?') === false ? '?' : '&') . $query;
    return vg_http($method, $url, [], $post, $timeout);
}

// ----------------------------------------------------------------------------- Cơ sở dữ liệu
function vg_db() {
    global $conn;
    if (!isset($conn) || !($conn instanceof mysqli)) throw new RuntimeException('../config.php không tạo biến $conn (mysqli).');
    if ($conn->connect_error) throw new RuntimeException('Lỗi kết nối MySQL: ' . $conn->connect_error);
    return $conn;
}

function vg_db_init() {
    $c = vg_db();
    $c->query("CREATE TABLE IF NOT EXISTS tv_bao_auto (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tc_id VARCHAR(40) NOT NULL,
        bulletin_key CHAR(40) NOT NULL,
        link_ytb VARCHAR(255) NOT NULL,
        tieu_de VARCHAR(500) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_bulletin (bulletin_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $c->query("CREATE TABLE IF NOT EXISTS tv_ytb (
        stt INT AUTO_INCREMENT PRIMARY KEY,
        link_ytb VARCHAR(255) NOT NULL,
        tieu_de VARCHAR(500) NOT NULL,
        mo_ta TEXT,
        ngay_dang DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vg_is_done($key) {
    $stmt = vg_db()->prepare('SELECT 1 FROM tv_bao_auto WHERE bulletin_key = ? LIMIT 1');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $stmt->store_result();
    $found = $stmt->num_rows > 0;
    $stmt->close();
    return $found;
}

function vg_log_upload($tc, $key, $link, $title, $desc) {
    $c = vg_db();
    $s = $c->prepare('INSERT IGNORE INTO tv_bao_auto (tc_id, bulletin_key, link_ytb, tieu_de) VALUES (?, ?, ?, ?)');
    $s->bind_param('ssss', $tc, $key, $link, $title);
    $s->execute();
    $s->close();
    $now = date('Y-m-d H:i:s');
    $s = $c->prepare('INSERT INTO tv_ytb (link_ytb, tieu_de, mo_ta, ngay_dang) VALUES (?, ?, ?, ?)');
    $s->bind_param('ssss', $link, $title, $desc, $now);
    $s->execute();
    $s->close();
}

// ----------------------------------------------------------------------------- Trạng thái
function vg_ensure_dir($d) {
    if (!is_dir($d) && !@mkdir($d, 0755, true)) throw new RuntimeException('Không tạo được thư mục ' . basename($d));
    if ($d === VG_DIR && !is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "Require all denied\n");
}

function vg_state_load() {
    $s = is_file(VG_STATE) ? json_decode((string)file_get_contents(VG_STATE), true) : null;
    return is_array($s) ? $s : null;
}

function vg_state_save(array $s) {
    file_put_contents(VG_STATE, json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function vg_rm($path) {
    if (is_file($path) || is_link($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $f) if ($f !== '.' && $f !== '..') vg_rm($path . '/' . $f);
    @rmdir($path);
}

function vg_item_dir(array $it) {
    return VG_DIR . '/' . $it['key'];
}

// ----------------------------------------------------------------------------- Giọng đọc (cùng quy tắc với trình phát của video.php)
function vg_settings() {
    $f = __DIR__ . '/tts_cache/_settings.json';
    $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    $d = is_array($d) ? $d : [];
    $voice = vg_conf('VIDEO_AUTO_VOICE', (string)($d['voice'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_\-.]{3,80}$/', $voice)) $voice = 'hn_female_ngochuyen_full_48k-fhg';
    $speed = is_numeric($d['speed'] ?? null) ? (float)$d['speed'] : 1.0;
    return [
        'voice'     => $voice,
        'speed'     => sprintf('%.2f', max(0.5, min(2.0, $speed))),
        'dict'      => is_array($d['dict'] ?? null) ? $d['dict'] : [],
        'overrides' => is_array($d['overrides'] ?? null) ? $d['overrides'] : [],
    ];
}

function vg_read_text($text, array $set) {
    $orig = vg_norm($text);
    if (isset($set['overrides'][$orig]) && vg_norm($set['overrides'][$orig]) !== '') return vg_norm($set['overrides'][$orig]);
    $map = []; $terms = [];
    foreach ($set['dict'] as $p) {
        if (!is_array($p) || count($p) < 2) continue;
        $k = mb_strtolower(vg_norm($p[0]));
        if ($k === '' || isset($map[$k])) continue;
        $map[$k] = vg_norm($p[1]);
        $terms[] = vg_norm($p[0]);
    }
    if ($terms) {
        usort($terms, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $parts = array_map(function ($t) {
            return (preg_match('/^[\p{L}\p{N}]/u', $t) ? '(?<![\p{L}\p{N}])' : '') . preg_quote($t, '/')
                 . (preg_match('/[\p{L}\p{N}]$/u', $t) ? '(?![\p{L}\p{N}])' : '');
        }, $terms);
        $re = '/' . implode('|', $parts) . '/iu';
        $orig = (string)preg_replace_callback($re, fn($m) => $map[mb_strtolower($m[0])] ?? $m[0], $orig);
    }
    return vg_norm($orig);
}

function vg_tts_key($voice, $speed, $text) {
    return sha1($voice . '|' . $speed . '|' . $text);
}

// ----------------------------------------------------------------------------- Tiêu đề / mô tả / tag (giống vb_yt_meta của video.php)
function vg_ucfirst($s) {
    return $s === '' ? '' : mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
}

function vg_clean($s) {
    return str_replace(['<', '>'], ['‹', '›'], (string)$s);
}

function vg_meta(array $api) {
    $player = $api['player'];
    $name = (string)$player['name'];
    $no = (string)$player['no'];
    $cur = $player['points'][0] ?? null;
    $cls = $cur['cls'] ?? 'bão';
    $clsWord = $cls === 'tan' ? 'bão' : $cls;
    $label = $name !== '' ? $name : ($no !== '' ? 'số hiệu ' . $no : '');
    $subject = vg_ucfirst(trim($clsWord . ' ' . $label));
    $lvTxt = ($cur && ($cur['lv'] ?? null) !== null && $cls !== 'tan') ? ' cấp ' . (int)$cur['lv'] : '';

    $hour = (int)date('G'); $min = 0; $day = date('d'); $mon = date('m');
    $when = $hour . 'h ngày ' . $day . '/' . $mon;
    $whenLong = null;
    if (!empty($cur['time']) && preg_match('/^(\d{1,2})h(\d{2})? (\d{2})\/(\d{2})$/', $cur['time'], $m)) {
        $hour = (int)$m[1]; $min = (int)($m[2] ?? 0); $day = $m[3]; $mon = $m[4];
        $when = $hour . 'h ngày ' . $day . '/' . $mon;
        $whenLong = $hour . ' giờ' . ($min ? ' ' . $min . ' phút' : '') . ' ngày ' . (int)$day . ' tháng ' . (int)$mon;
    }
    $year = date('Y');

    $title = '';
    foreach ([
        "Tin bão mới nhất: {$subject}{$lvTxt} - Vị trí, đường đi và dự báo ({$when})",
        "Tin bão mới nhất: {$subject}{$lvTxt} - Đường đi và dự báo ({$when})",
        "{$subject}{$lvTxt} - Đường đi và dự báo mới nhất ({$when})",
        "{$subject}{$lvTxt} - Dự báo đường đi mới nhất",
    ] as $t) {
        if (mb_strlen($t) <= 100) { $title = $t; break; }
    }
    if ($title === '') $title = mb_substr("{$subject}{$lvTxt} - Dự báo đường đi mới nhất", 0, 100);

    $tagName = preg_replace('/[^\p{L}\p{N}]+/u', '', vg_ucfirst($name !== '' ? $name : $no));
    $paras = $api['paragraphs'];
    if (count($paras) > 1) array_pop($paras);
    $body = '';
    foreach ($paras as $p) {
        if (mb_strlen($body) + mb_strlen($p) > 2600) break;
        $body .= ($body === '' ? '' : "\n\n") . $p;
    }
    $lines = [
        "{$subject}{$lvTxt}: cập nhật vị trí tâm bão, sức gió, vùng gió mạnh và dự báo đường đi mới nhất" . ($whenLong ? ' lúc ' . $whenLong . ' (giờ Việt Nam)' : '') . '.',
        'Chi tiết về cơn bão ' . $label . ' có thể xem chi tiết tại: https://nangmua.vn/ty',
        '',
        'NỘI DUNG BẢN TIN:',
        $body,
        '',
        'Nguồn dữ liệu: Cơ quan Khí tượng Nhật Bản (JMA). Thời gian trong video là giờ Việt Nam.',
        'Theo dõi kênh để cập nhật nhanh nhất các bản tin bão, áp thấp nhiệt đới và dự báo thời tiết.',
        '',
        implode(' ', array_filter([$tagName !== '' ? '#Bão' . $tagName : null, '#TinBão', '#DựBáoThờiTiết', $name !== '' ? '#Typhoon' . $tagName : null])),
    ];
    $description = trim(implode("\n", $lines));
    while (strlen($description) > 4900) $description = mb_substr($description, 0, mb_strlen($description) - 200);

    $raw = [];
    if ($name !== '') array_push($raw, "bão {$name}", $name, "typhoon {$name}", "tin bão {$name}", "bão {$name} mới nhất", "đường đi bão {$name}", "dự báo bão {$name}", "bão {$name} {$year}");
    elseif ($no !== '') array_push($raw, "bão {$no}", "tin bão {$no}");
    if ($cls === 'siêu bão') array_push($raw, 'siêu bão', "siêu bão {$year}");
    array_push($raw, 'tin bão', 'tin bão mới nhất', 'bản tin bão', 'dự báo bão', 'đường đi của bão', "bão {$year}", 'dự báo thời tiết', 'thời tiết hôm nay', 'áp thấp nhiệt đới', 'biển Đông', 'JMA', 'nangmua');
    $tags = []; $total = 0; $seen = [];
    foreach ($raw as $t) {
        $t = trim(vg_clean($t));
        $k = mb_strtolower($t);
        if ($t === '' || isset($seen[$k])) continue;
        $cost = mb_strlen($t) + (strpos($t, ' ') !== false ? 2 : 0) + 1;
        if ($total + $cost > 480) break;
        $seen[$k] = true; $tags[] = $t; $total += $cost;
    }
    return ['title' => vg_clean($title), 'description' => vg_clean($description), 'tags' => $tags];
}

// ----------------------------------------------------------------------------- YouTube
function vg_yt_token_file() {
    $f = vg_conf('YOUTUBE_TOKEN_FILE');
    return $f !== '' ? $f : __DIR__ . '/youtube_tokens.json';
}

function vg_yt_token() {
    $f = vg_yt_token_file();
    $t = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    if (!is_array($t)) throw new RuntimeException('Chưa đăng nhập YouTube: mở video.php và bấm đăng nhập YouTube một lần.');
    $exp = (int)($t['saved_at'] ?? 0) + (int)($t['expires_in'] ?? 3600) - 300;
    if (!empty($t['access_token']) && time() < $exp) return $t['access_token'];
    if (empty($t['refresh_token'])) throw new RuntimeException('Token YouTube không có refresh_token: đăng nhập lại trong video.php.');
    $id = vg_conf('YOUTUBE_CLIENT_ID'); $secret = vg_conf('YOUTUBE_CLIENT_SECRET');
    if ($id === '' || $secret === '') throw new RuntimeException('Thiếu YOUTUBE_CLIENT_ID / YOUTUBE_CLIENT_SECRET trong ../config.php.');
    $r = vg_http('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'client_id' => $id, 'client_secret' => $secret, 'refresh_token' => $t['refresh_token'], 'grant_type' => 'refresh_token',
    ]), 30);
    if ($r['code'] !== 200 || empty($r['json']['access_token'])) throw new RuntimeException('Không làm mới được token YouTube (HTTP ' . $r['code'] . ').');
    $new = $r['json'];
    $new['refresh_token'] = $new['refresh_token'] ?? $t['refresh_token'];
    $new['saved_at'] = time();
    file_put_contents($f, json_encode($new, JSON_PRETTY_PRINT), LOCK_EX);
    return $new['access_token'];
}

function vg_yt_error(array $r, $fallback) {
    return $r['json']['error']['message'] ?? ($r['error'] ?: ($fallback . ' (HTTP ' . $r['code'] . ')'));
}

// ----------------------------------------------------------------------------- Các bước xử lý 1 cơn bão
function vg_api(array $it) {
    $f = vg_item_dir($it) . '/api.json';
    $j = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    if (!is_array($j)) throw new RuntimeException('Mất dữ liệu bản tin (api.json).');
    return $j;
}

// Bước 2: giọng đọc Vbee từng câu. Trả true khi đủ mp3.
function vg_step_tts(array &$it, $deadline) {
    $api = vg_api($it);
    $cues = $api['player']['cues'];
    $set = vg_settings();
    if (!isset($it['tts'])) {
        $it['tts'] = [];
        foreach ($cues as $i => $c) {
            $read = vg_read_text($c['text'] ?? '', $set);
            $it['tts'][$i] = ['read' => $read, 'key' => $read === '' ? null : vg_tts_key($set['voice'], $set['speed'], $read), 'rid' => null, 'ready' => $read === '', 'tries' => 0];
        }
    }
    do {
        $pending = 0; $ready = 0;
        foreach ($it['tts'] as $i => &$t) {
            if ($t['ready']) { $ready++; continue; }
            if ($t['key'] && is_file(__DIR__ . '/tts_cache/' . $t['key'] . '.mp3')) { $t['ready'] = true; $ready++; continue; }
            if ($t['rid']) {
                $pending++;
                $r = vg_video('POST', 'x=1', ['action' => 'tts_poll', 'key' => $t['key'], 'request_id' => $t['rid']], 60);
                $j = $r['json'];
                if ($j && !empty($j['ok']) && ($j['status'] ?? '') === 'ready') { $t['ready'] = true; $pending--; $ready++; }
                elseif (!$j || empty($j['ok'])) { if (++$t['tries'] > 6) throw new RuntimeException('Vbee lỗi ở câu ' . ($i + 1) . ': ' . ($j['error'] ?? 'HTTP ' . $r['code'])); $t['rid'] = null; $pending--; }
            }
        }
        unset($t);
        foreach ($it['tts'] as $i => &$t) {
            if ($t['ready'] || $t['rid'] || $pending >= VG_MAX_PENDING_TTS || time() > $deadline) continue;
            $r = vg_video('POST', 'x=1', ['action' => 'tts_start', 'voice' => $set['voice'], 'speed' => $set['speed'], 'text' => $t['read']], 60);
            $j = $r['json'];
            if (!$j || empty($j['ok'])) { if (++$t['tries'] > 6) throw new RuntimeException('Vbee từ chối câu ' . ($i + 1) . ': ' . ($j['error'] ?? 'HTTP ' . $r['code'])); continue; }
            if (($j['status'] ?? '') === 'ready') { $t['ready'] = true; $ready++; }
            else { $t['rid'] = $j['request_id']; $pending++; }
        }
        unset($t);
        if ($ready === count($it['tts'])) return true;
        if (time() > $deadline) return false;
        sleep(3);
    } while (true);
}

// Bước 3: dựng video trên máy chủ. Trả true khi đã có out.mp4 trong thư mục tạm của bản tin.
function vg_step_render(array &$it) {
    $q = 'storm=' . rawurlencode($it['storm']);
    if (empty($it['job'])) {
        $urls = [];
        foreach ($it['tts'] as $i => $t) $urls[$i] = $t['key'] ? 'tts_cache/' . $t['key'] . '.mp3' : '';
        $r = vg_video('POST', $q . '&ff=start', [
            'res'  => (string)(int)vg_conf('VIDEO_AUTO_RES', '1080'),
            'fps'  => (string)(int)vg_conf('VIDEO_AUTO_FPS', '30'),
            'base' => vg_conf('VIDEO_AUTO_BASE', 'sat'),
            'tts'  => json_encode($urls),
        ], 120);
        if (!$r['json'] || empty($r['json']['ok'])) throw new RuntimeException('Không bắt đầu dựng video: ' . ($r['json']['error'] ?? 'HTTP ' . $r['code'] . ' ' . substr($r['body'], 0, 150)));
        $it['job'] = $r['json']['job'];
        $it['detail'] = 'Đã gửi lệnh dựng video';
        return false;
    }
    $r = vg_video('GET', $q . '&ff=status&job=' . rawurlencode($it['job']), null, 60);
    $j = $r['json'];
    if (!$j || empty($j['ok'])) throw new RuntimeException('Không đọc được tiến độ dựng video.');
    $st = $j['state'] ?? '';
    if ($st === 'queued' || $st === 'running') {
        $it['detail'] = ($j['stage'] ?? 'Đang dựng') . ' ' . round(($j['progress'] ?? 0) * 100) . '%';
        return false;
    }
    if ($st !== 'done') {
        $logTail = '';
        $logFile = __DIR__ . '/ff_jobs/' . basename($it['job']) . '/ffmpeg.log';
        if (is_file($logFile)) $logTail = substr((string)file_get_contents($logFile), -1200);
        elseif (!empty($j['log'])) $logTail = substr((string)$j['log'], -1200);
        if ($logTail !== '') fwrite(STDERR, "---- ffmpeg.log ({$it['storm']}) ----\n" . $logTail . "\n----\n");
        throw new RuntimeException('Dựng video thất bại (' . $st . '): ' . ($j['error'] ?? 'không rõ'));
    }
    $src = __DIR__ . '/ff_jobs/' . basename($it['job']);
    if (!is_file($src . '/out.mp4')) throw new RuntimeException('Dựng xong nhưng không thấy out.mp4.');
    $dst = vg_item_dir($it);
    if (!@rename($src . '/out.mp4', $dst . '/out.mp4') && !@copy($src . '/out.mp4', $dst . '/out.mp4')) throw new RuntimeException('Không di chuyển được out.mp4.');
    if (is_file($src . '/thumb.jpg')) @copy($src . '/thumb.jpg', $dst . '/thumb.jpg');
    vg_video('POST', $q . '&ff=cleanup', ['job' => $it['job']], 30);
    return true;
}

// Bước 5: upload resumable từng khúc. Trả true khi upload xong.
function vg_step_upload(array &$it) {
    $file = vg_item_dir($it) . '/out.mp4';
    $size = filesize($file);
    $token = vg_yt_token();
    $auth = 'Authorization: Bearer ' . $token;
    $api = vg_api($it);
    $meta = vg_meta($api);
    $it['title'] = $meta['title'];

    if (empty($it['upload_url'])) {
        $body = json_encode([
            'snippet' => ['title' => $meta['title'], 'description' => $meta['description'], 'tags' => $meta['tags'],
                'categoryId' => vg_conf('VIDEO_AUTO_CATEGORY', '25'), 'defaultLanguage' => 'vi', 'defaultAudioLanguage' => 'vi'],
            'status'  => ['privacyStatus' => vg_conf('VIDEO_AUTO_PRIVACY', 'public'), 'selfDeclaredMadeForKids' => false, 'embeddable' => true],
        ], JSON_UNESCAPED_UNICODE);
        $r = vg_http('POST', 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status', [
            $auth, 'Content-Type: application/json; charset=UTF-8', 'X-Upload-Content-Length: ' . $size, 'X-Upload-Content-Type: video/mp4',
        ], $body, 60);
        if ($r['code'] !== 200 || empty($r['headers']['location'])) throw new RuntimeException('Không khởi tạo được upload YouTube: ' . vg_yt_error($r, 'lỗi'));
        $it['upload_url'] = $r['headers']['location'];
        $it['offset'] = 0;
        $it['detail'] = 'Đã khởi tạo upload YouTube';
        return false;
    }

    $off = (int)($it['offset'] ?? 0);
    $len = min(VG_CHUNK, $size - $off);
    $fh = fopen($file, 'rb');
    fseek($fh, $off);
    $data = fread($fh, $len);
    fclose($fh);
    $r = vg_http('PUT', $it['upload_url'], [$auth, 'Content-Type: video/mp4', 'Content-Range: bytes ' . $off . '-' . ($off + $len - 1) . '/' . $size], $data, 900);
    unset($data);

    if ($r['code'] === 200 || $r['code'] === 201) {
        if (empty($r['json']['id'])) throw new RuntimeException('Upload xong nhưng không nhận được mã video.');
        $it['video_id'] = $r['json']['id'];
        return true;
    }
    if ($r['code'] === 308) {
        $it['offset'] = isset($r['headers']['range']) && preg_match('/bytes=0-(\d+)/', $r['headers']['range'], $m) ? (int)$m[1] + 1 : 0;
    } else {
        // Lỗi mạng/5xx: hỏi lại YouTube đã nhận tới đâu để tiếp tục.
        $q = vg_http('PUT', $it['upload_url'], [$auth, 'Content-Range: bytes */' . $size, 'Content-Length: 0'], '', 60);
        if ($q['code'] === 200 || $q['code'] === 201) {
            if (empty($q['json']['id'])) throw new RuntimeException('Upload xong nhưng không nhận được mã video.');
            $it['video_id'] = $q['json']['id'];
            return true;
        }
        if ($q['code'] !== 308) {
            if (++$it['upload_fail'] > 3) throw new RuntimeException('Upload YouTube lỗi: ' . vg_yt_error($r, 'lỗi'));
            return false;
        }
        $it['offset'] = isset($q['headers']['range']) && preg_match('/bytes=0-(\d+)/', $q['headers']['range'], $m) ? (int)$m[1] + 1 : 0;
    }
    $it['detail'] = 'Đang upload YouTube ' . round($it['offset'] / $size * 100) . '%';
    return false;
}

// Bước 6: thumbnail + ghi nhận vào CSDL + dọn dẹp.
function vg_step_finish(array &$it) {
    $api = vg_api($it);
    $meta = vg_meta($api);
    $link = 'https://www.youtube.com/watch?v=' . $it['video_id'];
    vg_log_upload($it['storm'], $it['key'], $link, $meta['title'], $meta['description']);
    $thumb = vg_item_dir($it) . '/thumb.jpg';
    if (is_file($thumb)) {
        $r = vg_http('POST', 'https://www.googleapis.com/upload/youtube/v3/thumbnails/set?uploadType=media&videoId=' . rawurlencode($it['video_id']),
            ['Authorization: Bearer ' . vg_yt_token(), 'Content-Type: image/jpeg'], file_get_contents($thumb), 120);
        $it['thumb'] = $r['code'] === 200 ? 'ok' : 'Chưa đặt được thumbnail: ' . vg_yt_error($r, 'lỗi');
    }
    $it['link'] = $link;
    vg_rm(vg_item_dir($it));
}

// ----------------------------------------------------------------------------- Lập kế hoạch: bão đang hiện hữu + chống trùng
function vg_plan() {
    vg_db_init();
    $res = vg_db()->query('SELECT tc_id, spec_json FROM jma_typhoons');
    if (!$res) throw new RuntimeException('Lỗi truy vấn bảng jma_typhoons.');
    $items = []; $skipped = 0; $total = 0;
    $max = max(1, (int)vg_conf('VIDEO_AUTO_MAX', '10'));
    while ($row = $res->fetch_assoc()) {
        $spec = json_decode((string)($row['spec_json'] ?? ''), true);
        if (!is_array($spec) || !$spec) continue;
        $total++;
        $id = (string)$row['tc_id'];
        $r = vg_video('GET', 'storm=' . rawurlencode($id) . '&api=json', null, 90);
        $api = $r['json'];
        if (!$api || empty($api['ok']) || empty($api['paragraphs']) || empty($api['player']['cues'])) continue;
        $key = sha1($id . '|' . ($api['text'] ?? implode("\n\n", $api['paragraphs'])));
        if (vg_is_done($key)) { $skipped++; continue; }
        if (count($items) >= $max) continue;
        $dir = VG_DIR . '/' . $key;
        vg_ensure_dir($dir);
        file_put_contents($dir . '/api.json', json_encode($api, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $items[] = ['storm' => $id, 'key' => $key, 'name' => $api['player']['name'] ?? '', 'step' => 'tts', 'status' => 'pending', 'fail' => 0];
    }
    return ['created' => time(), 'finished' => false, 'storms_total' => $total, 'skipped' => $skipped, 'items' => $items];
}

function vg_summary(array $s) {
    $done = $fail = $left = 0; $links = []; $errors = [];
    foreach ($s['items'] as $it) {
        if ($it['status'] === 'done') { $done++; $links[] = $it['link'] ?? ''; }
        elseif ($it['status'] === 'failed') { $fail++; $errors[] = ($it['storm'] . ': ' . ($it['error'] ?? '')); }
        else $left++;
    }
    return ['storms_in_db' => $s['storms_total'], 'already_uploaded' => $s['skipped'], 'uploaded' => $done, 'failed' => $fail, 'remaining' => $left, 'links' => $links, 'errors' => $errors];
}

// ----------------------------------------------------------------------------- Điều phối
function vg_tick() {
    vg_ensure_dir(VG_DIR);
    $lock = fopen(VG_DIR . '/tick.lock', 'c');
    if (!flock($lock, LOCK_EX | LOCK_NB)) vg_out(['ok' => true, 'state' => 'working', 'message' => 'Một lượt xử lý khác đang chạy.']);

    $deadline = time() + VG_TICK_SEC;
    $s = vg_state_load();
    if (!$s || !empty($s['finished']) || time() - (int)($s['created'] ?? 0) > VG_STALE_SEC) {
        if ($s) foreach ($s['items'] ?? [] as $it) if ($it['status'] !== 'done') vg_rm(VG_DIR . '/' . $it['key']);
        $s = vg_plan();
        if (!$s['items']) {
            $s['finished'] = true;
            vg_state_save($s);
            vg_out(['ok' => true, 'state' => 'idle', 'message' => $s['storms_total'] ? 'Không có bản tin mới (đã upload hết).' : 'Không có cơn bão nào.'] + vg_summary($s));
        }
        vg_state_save($s);
    }

    $idx = null;
    foreach ($s['items'] as $i => $it) if ($it['status'] === 'pending') { $idx = $i; break; }
    if ($idx === null) {
        $s['finished'] = true;
        vg_state_save($s);
        $sum = vg_summary($s);
        vg_out(['ok' => true, 'state' => 'idle', 'message' => 'Hoàn tất lượt chạy.'] + $sum);
    }

    $it = &$s['items'][$idx];
    try {
        $dir = vg_item_dir($it);
        vg_ensure_dir($dir);
        switch ($it['step']) {
            case 'tts':
                if (vg_step_tts($it, $deadline)) { $it['step'] = 'render'; $it['detail'] = 'Đã đủ giọng đọc'; }
                else $it['detail'] = 'Đang tạo giọng đọc Vbee';
                break;
            case 'render':
                if (vg_step_render($it)) { $it['step'] = 'upload'; $it['detail'] = 'Đã dựng xong video'; }
                break;
            case 'upload':
                $it['upload_fail'] = $it['upload_fail'] ?? 0;
                if (vg_step_upload($it)) { $it['step'] = 'finish'; $it['detail'] = 'Đã upload'; }
                break;
            case 'finish':
                vg_step_finish($it);
                $it['status'] = 'done';
                $it['detail'] = 'Hoàn tất';
                break;
        }
    } catch (Throwable $e) {
        $it['status'] = 'failed';
        $it['error'] = $e->getMessage();
        if (!empty($it['job'])) vg_video('POST', 'storm=' . rawurlencode($it['storm']) . '&ff=cancel', ['job' => $it['job']], 20);
        if (empty($it['video_id'])) vg_rm(VG_DIR . '/' . $it['key']);
    }
    $info = ['storm' => $it['storm'], 'name' => $it['name'], 'step' => $it['step'], 'status' => $it['status'], 'detail' => $it['detail'] ?? '', 'error' => $it['error'] ?? null];
    unset($it);
    vg_state_save($s);
    vg_out(['ok' => true, 'state' => 'working', 'current' => $info] + vg_summary($s));
}

try {
    $isCli = PHP_SAPI === 'cli';
    if (!$isCli) {
        $secret = vg_conf('VIDEO_GITHUB_KEY');
        $given = (string)($_SERVER['HTTP_X_AUTH_KEY'] ?? ($_GET['key'] ?? ''));
        if ($secret === '' || !hash_equals($secret, $given)) vg_out(['ok' => false, 'state' => 'error', 'error' => 'Unauthorized'], 403);
    }
    $action = $isCli ? ($argv[1] ?? 'tick') : (string)($_GET['action'] ?? 'tick');
    if ($action === 'status') {
        $s = vg_state_load();
        vg_out(['ok' => true, 'state' => $s && empty($s['finished']) ? 'working' : 'idle'] + ($s ? vg_summary($s) : []));
    }
    if ($action === 'reset') {
        vg_rm(VG_DIR);
        vg_out(['ok' => true, 'state' => 'idle', 'message' => 'Đã xoá trạng thái và file tạm.']);
    }
    vg_tick();
} catch (Throwable $e) {
    vg_out(['ok' => false, 'state' => 'error', 'error' => $e->getMessage()], 500);
}
