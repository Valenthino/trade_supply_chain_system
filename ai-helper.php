<?php
/**
 * Developed by Rameez Scripts
 * WhatsApp: https://wa.me/923224083545 (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 *
 * AI Helper — Gemini API Wrapper
 * Shared utility for all AI-powered features
 */

require_once 'config.php';

/**
 * Call Google Gemini API
 *
 * @param string $prompt The user prompt / question
 * @param string $systemInstruction System instruction for the AI
 * @param string|null $model Override model (null = use setting)
 * @return array ['success' => bool, 'text' => string] or ['success' => false, 'message' => string]
 */
function callGemini($prompt, $systemInstruction = '', $model = null) {
    $apiKey = getSetting('gemini_api_key', '');
    if (empty($apiKey)) {
        return ['success' => false, 'message' => 'Gemini API key not configured. Go to Settings > AI Configuration.'];
    }

    $aiEnabled = getSetting('ai_enabled', '1');
    if ($aiEnabled !== '1') {
        return ['success' => false, 'message' => 'AI features are disabled. Enable them in Settings > AI Configuration.'];
    }

    if ($model === null) {
        $model = getSetting('gemini_model', 'gemini-2.0-flash');
    }

    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

    // Build request body
    $body = [
        'contents' => [
            ['parts' => [['text' => $prompt]]]
        ],
        'generationConfig' => [
            'temperature' => 0.7,
            'maxOutputTokens' => 4096
        ]
    ];

    // Add system instruction if provided
    if (!empty($systemInstruction)) {
        $body['systemInstruction'] = [
            'parts' => [['text' => $systemInstruction]]
        ];
    }

    $jsonBody = json_encode($body);

    // Use file_get_contents with stream context
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $jsonBody,
            'timeout' => 60,
            'ignore_errors' => true
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ];

    $context = stream_context_create($options);

    try {
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return ['success' => false, 'message' => 'Failed to connect to Gemini API. Check your internet connection and API key.'];
        }

        $data = json_decode($response, true);

        if (isset($data['error'])) {
            $errorMsg = $data['error']['message'] ?? 'Unknown API error';
            return ['success' => false, 'message' => 'Gemini API error: ' . $errorMsg];
        }

        if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            return [
                'success' => true,
                'text' => $data['candidates'][0]['content']['parts'][0]['text'],
                'model' => $model
            ];
        }

        return ['success' => false, 'message' => 'Unexpected API response format'];

    } catch (\Throwable $e) {
        return ['success' => false, 'message' => 'AI request failed: ' . $e->getMessage()];
    }
}

/**
 * Test Gemini API connection
 *
 * @return array ['success' => bool, 'message' => string]
 */
function testGeminiConnection() {
    $result = callGemini("Respond with exactly: Connection successful. Do not add anything else.");
    if ($result['success']) {
        return ['success' => true, 'message' => 'Connection successful! Model: ' . ($result['model'] ?? 'unknown')];
    }
    return $result;
}

/**
 * Read receipt/document image with Gemini Vision
 */
function readReceiptWithAI($imageBase64, $mimeType) {
    $apiKey = getSetting('gemini_api_key', '');
    if (empty($apiKey)) return ['success' => false, 'message' => 'Gemini API key not configured. Go to Settings > AI Configuration.'];

    $aiEnabled = getSetting('ai_enabled', '1');
    if ($aiEnabled !== '1') return ['success' => false, 'message' => 'AI features are disabled. Enable them in Settings > AI Configuration.'];

    $model = getSetting('gemini_model', 'gemini-2.0-flash');

    $prompt = "You are analyzing a delivery receipt or trading document for a cashew nut (anacarde) trading company in Côte d'Ivoire. " .
        "Extract the following fields if visible. Return ONLY valid JSON, no markdown:\n" .
        '{"date":"YYYY-MM-DD or empty","reference_number":"receipt/ref number or empty","customer_name":"buyer/customer name or empty",' .
        '"weight_kg":"net weight in kg as number or 0","num_bags":"number of bags as number or 0",' .
        '"price_per_kg":"price per kg as number or 0","total_amount":"total amount as number or 0",' .
        '"quality_grade":"quality/grade if mentioned or empty","humidity":"humidity percentage as number or empty",' .
        '"kor":"KOR/out-turn ratio as number or empty","notes":"any other relevant notes or empty"}';

    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

    $body = [
        'contents' => [[
            'parts' => [
                ['text' => $prompt],
                ['inline_data' => ['mime_type' => $mimeType, 'data' => $imageBase64]]
            ]
        ]],
        'generationConfig' => [
            'temperature' => 0.1,
            'maxOutputTokens' => 2048
        ]
    ];

    $jsonBody = json_encode($body);

    $options = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $jsonBody,
            'timeout' => 30,
            'ignore_errors' => true
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ];

    $context = stream_context_create($options);

    try {
        $response = @file_get_contents($url, false, $context);
        if ($response === false) return ['success' => false, 'message' => 'Failed to connect to Gemini API.'];

        $data = json_decode($response, true);
        if (isset($data['error'])) return ['success' => false, 'message' => 'Gemini API error: ' . ($data['error']['message'] ?? 'Unknown')];

        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        // strip markdown code fences
        $text = preg_replace('/```json\s*/', '', $text);
        $text = preg_replace('/```\s*/', '', $text);
        $text = trim($text);

        $parsed = json_decode($text, true);
        if (!$parsed) return ['success' => false, 'message' => 'Could not parse AI response', 'raw' => $text];

        return ['success' => true, 'data' => $parsed];

    } catch (\Throwable $e) {
        return ['success' => false, 'message' => 'AI request failed: ' . $e->getMessage()];
    }
}

// AJAX handler — receipt reading
if (isset($_GET['action']) && $_GET['action'] === 'readReceipt') {
    header('Content-Type: application/json');

    // auth check
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit();
    }

    if (!isset($_FILES['receipt_image'])) {
        echo json_encode(['success' => false, 'message' => 'No image uploaded']);
        exit();
    }

    $file = $_FILES['receipt_image'];
    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    // validate mime server-side
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $realMime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($realMime, $allowed)) {
        echo json_encode(['success' => false, 'message' => 'Invalid file type. Use JPG, PNG, WebP, or PDF.']);
        exit();
    }

    // 10MB max
    if ($file['size'] > 10 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'File too large. Max 10MB.']);
        exit();
    }

    $imageData = base64_encode(file_get_contents($file['tmp_name']));
    $result = readReceiptWithAI($imageData, $realMime);
    echo json_encode($result);
    exit();
}

/**
 * Get the business system instruction for Gemini
 *
 * @return string
 */
function getBusinessSystemPrompt() {
    return "You are an AI business analyst for a cashew nut trading company (négoce de noix de cajou) based in Côte d'Ivoire. " .
           "The company buys raw cashew nuts (anacarde) from local suppliers (farmers, cooperatives, traders, pisteurs) " .
           "and sells to international and local customers. Currency is FCFA (Franc CFA). " .
           "The business operates in locations like Daloa, Seguela, Aladjkro, Vavoua, and Blolequin. " .
           "Key metrics include: KOR (kernel output ratio), grainage, weight in kg, price per kg. " .
           "Seasons run from October to March (peak buying) with lighter activity April-June. " .
           "Always be concise, data-driven, and actionable. Use bullet points. " .
           "When asked for French output, write in professional business French.";
}
