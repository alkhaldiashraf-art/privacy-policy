<?php

declare(strict_types=1);

namespace App\Scanning;

use App\Core\Env;
use App\Core\Logger;

/**
 * Turns a scan's findings into ready-to-paste fix prompts. When an
 * ANTHROPIC_API_KEY is configured, this calls the real Anthropic Messages
 * API for a genuinely AI-written prompt per finding plus one comprehensive
 * prompt. When no key is configured, it falls back to a clearly-labelled
 * deterministic template — findings themselves are always real either way,
 * only the prompt wording's source differs, and that source is always
 * reported honestly to the caller.
 */
final class AiPromptGenerator
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    private const DEFAULT_MODEL = 'claude-3-5-sonnet-20241022';
    private const TIMEOUT_SECONDS = 45;

    /**
     * @param array<int,array> $findings each with category/severity/title/description/recommendation/file_path/line_number
     * @return array{comprehensive:string, comprehensiveSource:string, perFinding:array<int,array{prompt:string,source:string}>}
     */
    public static function generate(array $findings, string $scanContextLabel): array
    {
        if ($findings === []) {
            return ['comprehensive' => '', 'comprehensiveSource' => 'template', 'perFinding' => []];
        }

        $apiKey = Env::get('ANTHROPIC_API_KEY', '');
        if (is_string($apiKey) && $apiKey !== '') {
            $aiResult = self::tryAi($findings, $scanContextLabel, $apiKey);
            if ($aiResult !== null) {
                return $aiResult;
            }
        }

        return self::templateFallback($findings);
    }

    private static function tryAi(array $findings, string $contextLabel, string $apiKey): ?array
    {
        $findingsForPrompt = [];
        foreach ($findings as $i => $f) {
            $findingsForPrompt[] = [
                'id' => $i,
                'category' => $f['category'],
                'severity' => $f['severity'],
                'title' => $f['title'],
                'description' => $f['description'],
                'recommendation' => $f['recommendation'] ?? null,
                'file' => $f['file_path'] ?? null,
                'line' => $f['line_number'] ?? null,
            ];
        }

        $system = <<<SYS
You are a senior application security and web engineering reviewer. You will
be given a JSON list of findings from an automated scan of "{$contextLabel}".
For each finding, write a short, precise, ready-to-paste prompt a developer
(or their AI coding assistant) can use to fix exactly that issue: name the
file/line when given, explain the concrete risk in one sentence, and state
the fix action clearly. Then write one additional "comprehensive" prompt
that summarizes and asks to fix ALL findings in a single pass, grouped by
severity.

Respond with ONLY minified JSON, no markdown fences, no commentary, in
exactly this shape:
{"comprehensive":"...","fixes":[{"id":0,"prompt":"..."}]}
SYS;

        $userContent = json_encode($findingsForPrompt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($userContent === false) {
            return null;
        }

        $payload = json_encode([
            'model' => Env::get('ANTHROPIC_MODEL', self::DEFAULT_MODEL),
            'max_tokens' => 4096,
            'system' => $system,
            'messages' => [
                ['role' => 'user', 'content' => $userContent],
            ],
        ]);

        if ($payload === false) {
            return null;
        }

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: ' . self::API_VERSION,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            Logger::error('AI fix-prompt generation failed', ['http_code' => $httpCode, 'curl_error' => $curlError]);
            return null;
        }

        $decoded = json_decode($response, true);
        $text = $decoded['content'][0]['text'] ?? null;
        if (!is_string($text)) {
            return null;
        }

        $text = trim($text);
        $text = preg_replace('/^```(?:json)?|```$/m', '', $text) ?? $text;
        $parsed = json_decode(trim($text), true);
        if (!is_array($parsed) || !isset($parsed['comprehensive'], $parsed['fixes']) || !is_array($parsed['fixes'])) {
            Logger::error('AI fix-prompt response was not valid JSON', ['raw' => substr($text, 0, 500)]);
            return null;
        }

        $perFinding = [];
        foreach ($parsed['fixes'] as $fix) {
            if (isset($fix['id'], $fix['prompt']) && is_int($fix['id']) && is_string($fix['prompt'])) {
                $perFinding[$fix['id']] = ['prompt' => $fix['prompt'], 'source' => 'ai'];
            }
        }

        // Fill any finding the model skipped with a template prompt, so every finding always has one.
        foreach (array_keys($findingsForPrompt) as $id) {
            if (!isset($perFinding[$id])) {
                $perFinding[$id] = ['prompt' => self::templatePromptFor($findings[$id]), 'source' => 'template'];
            }
        }
        ksort($perFinding);

        return [
            'comprehensive' => (string) $parsed['comprehensive'],
            'comprehensiveSource' => 'ai',
            'perFinding' => array_values($perFinding),
        ];
    }

    private static function templateFallback(array $findings): array
    {
        $perFinding = [];
        foreach ($findings as $f) {
            $perFinding[] = ['prompt' => self::templatePromptFor($f), 'source' => 'template'];
        }

        $bySeverity = [];
        foreach (\App\Models\ScanFinding::SEVERITY_ORDER as $severity) {
            $bySeverity[$severity] = [];
        }
        foreach ($findings as $f) {
            $bySeverity[$f['severity']][] = $f;
        }

        $lines = ["Fix the following issues found by an automated scan, addressing critical and high severity items first:", ''];
        foreach ($bySeverity as $severity => $items) {
            if ($items === []) {
                continue;
            }
            $lines[] = strtoupper($severity) . ':';
            foreach ($items as $f) {
                $location = $f['file_path'] ? " ({$f['file_path']}" . ($f['line_number'] ? ':' . $f['line_number'] : '') . ')' : '';
                $lines[] = "- {$f['title']}{$location}: {$f['description']}"
                    . (!empty($f['recommendation']) ? " Fix: {$f['recommendation']}" : '');
            }
            $lines[] = '';
        }

        return [
            'comprehensive' => implode("\n", $lines),
            'comprehensiveSource' => 'template',
            'perFinding' => $perFinding,
        ];
    }

    private static function templatePromptFor(array $f): string
    {
        $location = $f['file_path'] ? "in {$f['file_path']}" . ($f['line_number'] ? " at line {$f['line_number']}" : '') : 'in this project';
        $rec = !empty($f['recommendation']) ? " Recommended fix: {$f['recommendation']}" : '';
        return "Fix the following {$f['severity']}-severity issue {$location}: \"{$f['title']}\". {$f['description']}{$rec} "
            . 'Make the minimal change needed to remediate this properly and explain what you changed.';
    }
}
