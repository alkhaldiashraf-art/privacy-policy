<?php

declare(strict_types=1);

namespace App\Scanning;

/**
 * Rule-based static analysis over an uploaded project file or ZIP archive.
 * Every finding is a real regex/heuristic match against the actual
 * uploaded content — nothing here is simulated or pre-canned.
 */
final class CodeScanner
{
    private const MAX_FILES = 400;
    private const MAX_TOTAL_UNCOMPRESSED_BYTES = 30 * 1024 * 1024;
    private const MAX_FILE_BYTES = 2 * 1024 * 1024;
    private const SKIP_DIR_SEGMENTS = ['vendor', 'node_modules', '.git', 'dist', 'build', '.next', '.svn', '__pycache__'];
    private const SCANNABLE_EXTENSIONS = [
        'php', 'js', 'jsx', 'ts', 'tsx', 'vue', 'py', 'rb', 'html', 'htm', 'css',
        'json', 'env', 'yml', 'yaml', 'sql', 'sh', 'twig', 'blade',
    ];

    /**
     * @return array{ok:bool, error?:string, findings?:array<int,array>, filesScanned?:int}
     */
    public static function scanUploadedFile(string $tmpPath, string $originalName): array
    {
        self::$relativeNames = [];
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $workDir = null;

        try {
            if ($ext === 'zip') {
                $extraction = self::extractZip($tmpPath);
                if (!$extraction['ok']) {
                    return ['ok' => false, 'error' => $extraction['error']];
                }
                $workDir = $extraction['dir'];
                $files = self::collectFiles($workDir);
            } else {
                if (!in_array($ext, self::SCANNABLE_EXTENSIONS, true)) {
                    return ['ok' => false, 'error' => "Unsupported file type \".{$ext}\". Upload a .zip of your project, or a single supported source file."];
                }
                $files = [['path' => $tmpPath, 'relative' => $originalName]];
            }

            if ($files === []) {
                return ['ok' => false, 'error' => 'No scannable source files were found in the upload.'];
            }

            $findings = [];
            foreach ($files as $file) {
                $content = @file_get_contents($file['path']);
                if ($content === false) {
                    continue;
                }
                self::scanFileContent($file['relative'], $content, $findings);
            }

            return ['ok' => true, 'findings' => $findings, 'filesScanned' => count($files)];
        } finally {
            if ($workDir !== null) {
                self::rrmdir($workDir);
            }
        }
    }

    /** @return array{ok:bool, error?:string, dir?:string} */
    private static function extractZip(string $zipPath): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['ok' => false, 'error' => 'Could not open the uploaded file as a ZIP archive.'];
        }

        $totalSize = 0;
        $fileCount = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }
            $name = $stat['name'];
            if (str_contains($name, '..') || str_starts_with($name, '/')) {
                $zip->close();
                return ['ok' => false, 'error' => 'The ZIP archive contains an unsafe file path and was rejected.'];
            }
            if (!str_ends_with($name, '/')) {
                $fileCount++;
                $totalSize += (int) $stat['size'];
            }
        }

        if ($fileCount > self::MAX_FILES * 3) {
            $zip->close();
            return ['ok' => false, 'error' => 'The ZIP archive has too many files to scan safely.'];
        }
        if ($totalSize > self::MAX_TOTAL_UNCOMPRESSED_BYTES) {
            $zip->close();
            return ['ok' => false, 'error' => 'The ZIP archive is too large when uncompressed (over 30 MB).'];
        }

        $dir = sys_get_temp_dir() . '/siugoals-scan-' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0700, true)) {
            $zip->close();
            return ['ok' => false, 'error' => 'Could not create a temporary directory for extraction.'];
        }

        $extracted = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false || str_ends_with($stat['name'], '/')) {
                continue;
            }
            if (self::isInSkippedDir($stat['name'])) {
                continue;
            }
            $ext = strtolower(pathinfo($stat['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, self::SCANNABLE_EXTENSIONS, true)) {
                continue;
            }
            if ($extracted >= self::MAX_FILES) {
                break;
            }
            if ((int) $stat['size'] > self::MAX_FILE_BYTES) {
                continue;
            }

            $dest = $dir . '/' . $extracted . '_' . basename($stat['name']);
            $contents = $zip->getFromIndex($i);
            if ($contents === false) {
                continue;
            }
            file_put_contents($dest, $contents);
            self::$relativeNames[$dest] = $stat['name'];
            $extracted++;
        }

        $zip->close();
        return ['ok' => true, 'dir' => $dir];
    }

    /** @var array<string,string> Maps a temp extraction path back to its original relative path, for reporting. */
    private static array $relativeNames = [];

    private static function isInSkippedDir(string $path): bool
    {
        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if (in_array($segment, self::SKIP_DIR_SEGMENTS, true)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<int,array{path:string,relative:string}> */
    private static function collectFiles(string $dir): array
    {
        $files = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . '/' . $entry;
            if (is_file($full)) {
                $files[] = ['path' => $full, 'relative' => self::$relativeNames[$full] ?? $entry];
            }
        }
        return $files;
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private static function add(array &$findings, string $relativePath, string $category, string $severity, string $title, string $description, string $recommendation, ?int $line = null): void
    {
        $findings[] = [
            'category' => $category,
            'severity' => $severity,
            'title' => $title,
            'description' => $description,
            'recommendation' => $recommendation,
            'file_path' => $relativePath,
            'line_number' => $line,
        ];
    }

    private static function lineOf(string $content, int $offset): int
    {
        return substr_count($content, "\n", 0, $offset) + 1;
    }

    private static function scanFileContent(string $relativePath, string $content, array &$findings): void
    {
        $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        $basename = strtolower(basename($relativePath));

        if ($basename === '.env' || $basename === '.env.production') {
            self::add($findings, $relativePath, 'secrets', 'critical',
                'Environment file included in the uploaded project',
                'A .env file was found inside the uploaded project. If this project is deployed with this file included (or it was ever committed to a public repo), all secrets inside it are exposed.',
                'Never ship .env to production or commit it to version control; keep only .env.example with placeholder values, and add .env to .gitignore.');
        }

        self::scanSecrets($relativePath, $content, $findings);
        self::scanGenericIssues($relativePath, $content, $findings);

        if ($ext === 'php') {
            self::scanPhp($relativePath, $content, $findings);
        } elseif (in_array($ext, ['js', 'jsx', 'ts', 'tsx', 'vue'], true)) {
            self::scanJavaScript($relativePath, $content, $findings);
        } elseif (in_array($ext, ['html', 'htm', 'blade', 'twig'], true)) {
            self::scanHtml($relativePath, $content, $findings);
        }
    }

    private static function scanSecrets(string $relativePath, string $content, array &$findings): void
    {
        $patterns = [
            '/AKIA[0-9A-Z]{16}/' => 'A hardcoded AWS access key ID was found.',
            '/-----BEGIN (RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----/' => 'A private key was found embedded directly in a source file.',
            '/(?:sk_live|pk_live)_[0-9a-zA-Z]{16,}/' => 'A live Stripe API key was found.',
            '/xox[baprs]-[0-9a-zA-Z-]{10,}/' => 'A Slack API token was found.',
        ];
        foreach ($patterns as $pattern => $desc) {
            if (preg_match($pattern, $content, $m, PREG_OFFSET_CAPTURE)) {
                self::add($findings, $relativePath, 'secrets', 'critical', 'Hardcoded credential found in source',
                    $desc . ' Anyone with access to this code (or this uploaded ZIP) can use it directly.',
                    'Remove the credential from source control, rotate/revoke it immediately, and load it from an environment variable instead.',
                    self::lineOf($content, $m[0][1]));
            }
        }

        if (preg_match('/\b(?:password|passwd|secret|api[_-]?key|access[_-]?token|auth[_-]?token)\s*[:=]\s*[\'"]([^\'"]{8,})[\'"]/i', $content, $m, PREG_OFFSET_CAPTURE)
            && !self::looksLikePlaceholder($m[1][0])) {
            self::add($findings, $relativePath, 'secrets', 'high', 'Possible hardcoded credential',
                'A variable assignment matching a password/secret/API-key/token pattern was found with what looks like a real value, not a placeholder.',
                'Move this value into an environment variable or secret manager and remove it from source.',
                self::lineOf($content, $m[0][1]));
        }

        // .env / shell-style unquoted KEY=VALUE assignments (no quotes, so the pattern above doesn't apply).
        if (preg_match('/^[ \t]*[A-Z0-9_]*(?:PASSWORD|SECRET|API_KEY|ACCESS_TOKEN|AUTH_TOKEN|PRIVATE_KEY)[A-Z0-9_]*[ \t]*=[ \t]*(\S{8,})[ \t]*$/mi', $content, $m, PREG_OFFSET_CAPTURE)
            && !self::looksLikePlaceholder($m[1][0])) {
            self::add($findings, $relativePath, 'secrets', 'high', 'Possible hardcoded credential in config file',
                'A config-style KEY=VALUE line matching a password/secret/token name was found with what looks like a real value, not a placeholder.',
                'Keep real secrets only in an untracked .env on the server; commit only a .env.example with placeholder values.',
                self::lineOf($content, $m[0][1]));
        }
    }

    private static function looksLikePlaceholder(string $value): bool
    {
        $value = strtolower($value);
        foreach (['changeme', 'example', 'placeholder', 'xxxxxx', 'your_', 'test123', 'password123', '<', 'null', 'none'] as $needle) {
            if (str_contains($value, $needle)) {
                return true;
            }
        }
        return false;
    }

    private static function scanGenericIssues(string $relativePath, string $content, array &$findings): void
    {
        if (preg_match('/\b(TODO|FIXME|HACK)\b[:\s]/', $content, $m, PREG_OFFSET_CAPTURE)) {
            self::add($findings, $relativePath, 'maintainability', 'info', 'Unresolved TODO/FIXME marker',
                'A TODO/FIXME/HACK comment was left in the code, which often marks incomplete or fragile logic.',
                'Review and resolve before shipping, or file it as a tracked issue instead of a comment.',
                self::lineOf($content, $m[0][1]));
        }
    }

    private static function scanPhp(string $relativePath, string $content, array &$findings): void
    {
        $checks = [
            '/\beval\s*\(/' => ['critical', 'Use of eval()', 'eval() executes arbitrary PHP from a string, which is extremely dangerous if any part of that string can be influenced by user input.', 'Remove eval() entirely; there is almost always a safer, explicit way to achieve the same result.'],
            '/\b(?:system|exec|shell_exec|passthru|popen|proc_open)\s*\(\s*(?:\$_(?:GET|POST|REQUEST|COOKIE)|\$[a-zA-Z_]+)/' => ['critical', 'Shell command built from a variable', 'A shell-execution function is called with a variable argument. If that variable ever contains user input, this is a command-injection vulnerability.', 'Avoid shelling out with user-influenced input; if unavoidable, use escapeshellarg() on every argument and prefer PHP-native APIs.'],
            '/\bextract\s*\(\s*\$_(?:GET|POST|REQUEST|COOKIE)/' => ['critical', 'extract() used on user input', 'extract() on $_GET/$_POST/$_REQUEST lets an attacker define or overwrite arbitrary variables in the current scope.', 'Never call extract() on user-controlled arrays; read expected keys explicitly instead.'],
            '/\binclude(?:_once)?\s*\(?\s*\$_(?:GET|POST|REQUEST|COOKIE)/' => ['critical', 'File include path comes from user input', 'include()/require() is being called with a path derived from user input, which is a classic Local/Remote File Inclusion (LFI/RFI) vulnerability.', 'Never build include paths from user input; use a fixed allow-list mapping identifiers to file paths.'],
            '/\b(?:mysql_query|mysqli_query)\s*\([^)]*\.\s*\$_(?:GET|POST|REQUEST)/' => ['critical', 'SQL query built by string concatenation with user input', 'A SQL query is concatenated directly with $_GET/$_POST/$_REQUEST, which is a SQL injection vulnerability.', 'Use prepared statements with bound parameters (PDO or mysqli) for every query that includes user input.'],
            '/\becho\s+\$_(?:GET|REQUEST)\s*\[/' => ['high', 'Unescaped user input echoed directly', 'A value from $_GET/$_REQUEST is echoed without htmlspecialchars()/output escaping, which is a reflected XSS vulnerability.', 'Escape all user-controlled output with htmlspecialchars($value, ENT_QUOTES, "UTF-8") before printing it into HTML.'],
            '/\bmd5\s*\(\s*\$(?:password|pass|pwd)/i' => ['medium', 'Weak hashing algorithm used for a password', 'md5() is used on what appears to be a password. md5 is fast and unsalted, making it trivial to crack with modern hardware.', 'Use password_hash() with PASSWORD_DEFAULT/PASSWORD_BCRYPT and verify with password_verify().'],
            '/\bini_set\s*\(\s*[\'"]display_errors[\'"]\s*,\s*[\'"]?1[\'"]?\s*\)/' => ['medium', 'display_errors forced on', 'The code explicitly turns on display_errors, which can leak stack traces, file paths, and query fragments to visitors in production.', 'Never force display_errors on in application code; control it via php.ini/environment per deployment stage.'],
            '/\bmysql_(?:connect|query|fetch_array)\s*\(/' => ['medium', 'Deprecated mysql_* extension used', 'The legacy mysql_* functions were removed in PHP 7 and have no security patches.', 'Migrate to PDO or mysqli with prepared statements.'],
            '#\bvar_dump\s*\(|\bprint_r\s*\(\s*\$#' => ['low', 'Debug output left in code', 'A var_dump()/print_r() call was found, which can leak internal data structures if it ever runs in production.', 'Remove debug output before shipping, or gate it behind an APP_DEBUG check.'],
        ];

        foreach ($checks as $pattern => [$severity, $title, $desc, $rec]) {
            if (preg_match($pattern, $content, $m, PREG_OFFSET_CAPTURE)) {
                self::add($findings, $relativePath, 'code-security', $severity, $title, $desc, $rec, self::lineOf($content, $m[0][1]));
            }
        }
    }

    private static function scanJavaScript(string $relativePath, string $content, array &$findings): void
    {
        $checks = [
            '/\beval\s*\(/' => ['critical', 'Use of eval()', 'eval() executes arbitrary JavaScript from a string. If any part of that string is influenced by user input, this is a code-injection vulnerability.', 'Remove eval(); use JSON.parse() for data or explicit logic instead.'],
            '/\bnew\s+Function\s*\(/' => ['high', 'Dynamic function construction (new Function())', 'new Function() compiles a string into executable code at runtime, similar in risk to eval().', 'Avoid constructing functions from strings; write the logic directly.'],
            '/\.innerHTML\s*=\s*(?!["\'`]\s*["\'`])/' => ['medium', 'innerHTML assigned from a non-literal value', 'Assigning to .innerHTML with anything other than a fixed string can introduce DOM-based XSS if the value ever includes user input.', 'Use .textContent for plain text, or sanitize HTML with a trusted library (e.g. DOMPurify) before assigning innerHTML.'],
            '/\bdocument\.write\s*\(/' => ['low', 'Use of document.write()', 'document.write() blocks parsing, breaks with async scripts, and can introduce XSS if given dynamic content.', 'Use DOM APIs (createElement/appendChild) or a templating approach instead.'],
            '/localStorage\.setItem\s*\(\s*[\'"](?:token|auth|jwt|access_token)[\'"]/i' => ['medium', 'Auth token stored in localStorage', 'Storing an auth/JWT token in localStorage makes it readable by any script on the page, so any XSS becomes full account takeover.', 'Prefer an HttpOnly, Secure cookie for auth tokens so client-side JavaScript cannot read it.'],
            '/console\.(?:log|debug)\s*\(/' => ['info', 'console.log left in code', 'Debug logging was left in the code; this can leak internal data in the browser console in production.', 'Remove console.log calls before shipping, or strip them in the production build step.'],
        ];

        foreach ($checks as $pattern => [$severity, $title, $desc, $rec]) {
            if (preg_match($pattern, $content, $m, PREG_OFFSET_CAPTURE)) {
                self::add($findings, $relativePath, 'code-security', $severity, $title, $desc, $rec, self::lineOf($content, $m[0][1]));
            }
        }
    }

    private static function scanHtml(string $relativePath, string $content, array &$findings): void
    {
        if (!preg_match('/<meta[^>]+name=["\']viewport["\']/i', $content)) {
            self::add($findings, $relativePath, 'mobile', 'medium', 'Missing viewport meta tag',
                'This HTML file has no <meta name="viewport"> tag, so it will not render correctly on mobile devices.',
                'Add <meta name="viewport" content="width=device-width, initial-scale=1"> to the <head>.');
        }

        if (preg_match('/<script[^>]+src=["\']http:\/\//i', $content, $m, PREG_OFFSET_CAPTURE)) {
            self::add($findings, $relativePath, 'code-security', 'medium', 'Script loaded over insecure HTTP',
                'A <script src="http://..."> tag loads a third-party script over an unencrypted connection, which can be tampered with in transit (and browsers will block it as mixed content on an HTTPS page).',
                'Load third-party scripts over HTTPS only.',
                self::lineOf($content, $m[0][1]));
        }
    }
}
