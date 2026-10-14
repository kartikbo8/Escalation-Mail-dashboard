<?php
require_once __DIR__ . '/../config/ai.php';

function classifyMailWithAI($subject, $bodyPreview = '') {
    if (!GEMINI_API_KEY) {
        return null;
    }

    $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/'
        . rawurlencode(GEMINI_MODEL) . ':generateContent?key=' . rawurlencode(GEMINI_API_KEY);
    $payload = [
        'generationConfig' => [
            'temperature' => 0,
            'responseMimeType' => 'application/json',
        ],
        'systemInstruction' => [
            'parts' => [[
                'text' => 'Classify an email for a response escalation workflow. Return JSON only: {"actionable":true|false,"confidence":0-1}. Mark actionable false for posters, celebrations, newsletters, automated notifications, HRMS updates, no-reply notices, and informational messages. Mark actionable true only when a human response or work action is reasonably required.',
            ]],
        ],
        'contents' => [[
            'parts' => [[
                'text' => "Subject:\n{$subject}\n\nBody preview:\n{$bodyPreview}",
            ]],
        ]],
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $decoded = json_decode($response ?: '', true);
    $content = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
    $classification = is_string($content) ? json_decode($content, true) : null;
    if (!is_array($classification) || !isset($classification['actionable'], $classification['confidence'])) {
        return null;
    }

    return [
        'actionable' => (bool)$classification['actionable'],
        'confidence' => (float)$classification['confidence'],
    ];
}