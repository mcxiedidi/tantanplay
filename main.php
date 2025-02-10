<?php
/***************************************
 * 弹弹play弹幕获取接口
 * 版本：1.0
 * 最后更新：2025-02-10
 ***************************************/

// === 配置区域 ============================================
define('APP_ID', 'your_app_id_here');         // 开放平台AppId
define('APP_SECRET', 'your_app_secret_here'); // 开放平台AppSecret
define('API_URL', 'https://api.dandanplay.net/api/v2/');
define('CACHE_DIR', __DIR__.'/danmaku_cache/'); // 缓存目录
define('CACHE_TTL', 86400);                   // 24小时缓存
define('CLEANUP_PROBABILITY', 0.01);          // 1%清理概率
define('HASH_CACHE_TTL', 604800);             // 哈希缓存7天
define('MAX_FILE_SIZE', 16 * 1024 * 1024);    // 16MB

// === 初始化检查 ==========================================
// 创建缓存目录
if (!file_exists(CACHE_DIR) && !mkdir(CACHE_DIR, 0755, true)) {
    header('Content-Type: application/json');
    die(json_encode(['error' => '无法创建缓存目录']));
}

// 清理旧缓存（概率执行）
if (mt_rand(1, 100) <= (CLEANUP_PROBABILITY * 100)) {
    cleanupExpiredCache();
}

// === 主程序 ==============================================
header('Content-Type: application/xml; charset=utf-8');

try {
    // 验证输入参数
    if (!isset($_GET['url']) || empty($_GET['url'])) {
        throw new Exception('URL参数不能为空', 400);
    }
    
    $requestUrl = $_GET['url'];
    $cacheKey = md5($requestUrl);
    $cacheFile = CACHE_DIR . $cacheKey . '.xml';
    $metaFile = CACHE_DIR . $cacheKey . '.meta';

    // 尝试读取缓存
    if (tryServeCache($cacheFile, $metaFile)) {
        exit;
    }

    // 获取文件哈希
    $fileHash = getCachedFileHash($requestUrl);
    if (!$fileHash) {
        throw new Exception('无法获取文件哈希', 500);
    }

    // 调用匹配API
    $matchResult = callDandanApi('match', [
        'fileName' => basename(parse_url($requestUrl, PHP_URL_PATH)),
        'hash' => $fileHash,
        'withAuth' => 'true'
    ]);

    if (empty($matchResult['matches'])) {
        throw new Exception('未找到匹配的弹幕库', 404);
    }
    $episodeId = $matchResult['matches'][0]['episodeId'];

    // 获取弹幕数据
    $commentResult = callDandanApi("comment/{$episodeId}", [
        'withRelated' => 'true'
    ]);

    // 生成XML并写入缓存
    $xmlString = generateDanmakuXml($commentResult);
    writeCacheFiles($cacheFile, $metaFile, $xmlString, $requestUrl, $episodeId);
    
    echo $xmlString;

} catch (Exception $e) {
    handleException($e);
}

// === 功能函数 ============================================

/**
 * 尝试提供缓存内容
 */
function tryServeCache($cacheFile, $metaFile) {
    if (!file_exists($cacheFile) || !file_exists($metaFile)) {
        return false;
    }

    $meta = json_decode(file_get_contents($metaFile), true);
    if (!$meta || (time() - $meta['timestamp']) > CACHE_TTL) {
        return false;
    }

    readfile($cacheFile);
    return true;
}

/**
 * 带缓存的哈希计算
 */
function getCachedFileHash($url) {
    $cacheKey = md5($url) . '_hash';
    $cacheFile = CACHE_DIR . $cacheKey;

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < HASH_CACHE_TTL) {
        return file_get_contents($cacheFile);
    }

    $context = stream_context_create(['http' => [
        'header' => "Range: bytes=0-" . (MAX_FILE_SIZE - 1),
        'timeout' => 15
    ]]);

    $data = @file_get_contents($url, false, $context);
    if ($data === false) return false;

    $hash = md5(substr($data, 0, MAX_FILE_SIZE));
    file_put_contents($cacheFile, $hash);
    return $hash;
}

/**
 * 生成API签名
 */
function generateApiSignature($path) {
    $timestamp = time();
    $data = APP_ID . $timestamp . $path . APP_SECRET;
    return [
        'X-AppId: ' . APP_ID,
        'X-Timestamp: ' . $timestamp,
        'X-Signature: ' . base64_encode(hash('sha256', $data, true))
    ];
}

/**
 * 调用API接口
 */
function callDandanApi($endpoint, $params = []) {
    $url = API_URL . $endpoint;
    $headers = generateApiSignature('/api/v2/'.$endpoint);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url . (empty($params) ? '' : '?'.http_build_query($params)),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'DandanPlay API Client/2.0'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception("API请求失败: HTTP {$httpCode}", 500);
    }

    $data = json_decode($response, true);
    if (isset($data['errorCode'])) {
        throw new Exception("API错误: {$data['errorMessage']}", 500);
    }

    return $data;
}

/**
 * 生成弹幕XML
 */
function generateDanmakuXml($commentResult) {
    $xml = new SimpleXMLElement('<i></i>');
    $xml->addAttribute('chatserver', 'chat.bilibili.com');
    $xml->addAttribute('ts', time());

    if (!empty($commentResult['comments'])) {
        foreach ($commentResult['comments'] as $comment) {
            $d = $xml->addChild('d');
            $d->addAttribute('p', implode(',', [
                round($comment['time'], 2),
                $comment['type'] ?? 1,
                $comment['size'] ?? 25,
                sprintf('%06X', $comment['color'] ?? 0xFFFFFF),
                time(),
                0,
                $comment['userIdHash'] ?? '',
                $comment['id'] ?? 0
            ]));
            $d[0] = htmlspecialchars($comment['text'], ENT_XML1);
        }
    }

    return $xml->asXML();
}

/**
 * 写入缓存文件
 */
function writeCacheFiles($cacheFile, $metaFile, $content, $url, $episodeId) {
    file_put_contents($cacheFile, $content);
    file_put_contents($metaFile, json_encode([
        'timestamp' => time(),
        'url' => $url,
        'episodeId' => $episodeId,
        'size' => strlen($content)
    ]));
}

/**
 * 清理过期缓存
 */
function cleanupExpiredCache() {
    $now = time();
    foreach (glob(CACHE_DIR . '*.meta') as $metaFile) {
        $content = @file_get_contents($metaFile);
        if (!$content) continue;

        $meta = json_decode($content, true);
        if ($meta && ($now - $meta['timestamp']) > CACHE_TTL) {
            $base = substr($metaFile, 0, -5);
            @unlink($metaFile);
            @unlink($base . '.xml');
            @unlink($base . '_hash');
        }
    }
}

/**
 * 异常处理
 */
function handleException($e) {
    $code = $e->getCode() ?: 500;
    http_response_code($code);
    
    if (strpos(implode(headers_list()), 'Content-Type: application/xml') !== false) {
        die($e->getMessage());
    }
    
    header('Content-Type: application/json');
    die(json_encode([
        'error' => $e->getMessage(),
        'code' => $code
    ]));
}
?>
