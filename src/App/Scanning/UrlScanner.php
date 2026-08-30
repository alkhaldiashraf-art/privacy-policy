<?php

declare(strict_types=1);

namespace App\Scanning;

/**
 * Runs a battery of real, evidence-based checks against a live URL by
 * actually fetching it (and a handful of well-known auxiliary paths) —
 * nothing here is simulated. Every finding traces back to a specific
 * response header, response body pattern, or connection property.
 */
final class UrlScanner
{
    /** @return array{ok:bool, error?:string, findings?:array<int,array>, finalUrl?:string} */
    public static function scan(string $url): array
    {
        $result = SafeHttpClient::fetch($url);
        if (!$result->ok) {
            return ['ok' => false, 'error' => $result->error];
        }

        $findings = [];
        self::checkTransport($result, $findings);
        self::checkSecurityHeaders($result, $findings);
        self::checkCookies($result, $findings);
        self::checkExposedPaths($url, $findings);
        self::checkSeo($result, $findings);
        self::checkMobileAndAccessibility($result, $findings);
        self::checkPerformance($result, $findings);
        self::checkCommonVulnerabilitySignals($result, $findings);

        return ['ok' => true, 'findings' => $findings, 'finalUrl' => $result->finalUrl];
    }

    private static function add(array &$findings, string $category, string $severity, string $title, string $description, ?string $recommendation = null): void
    {
        $findings[] = [
            'category' => $category,
            'severity' => $severity,
            'title' => $title,
            'description' => $description,
            'recommendation' => $recommendation,
            'file_path' => null,
            'line_number' => null,
        ];
    }

    private static function checkTransport(FetchResult $r, array &$findings): void
    {
        $isHttps = str_starts_with(strtolower($r->finalUrl), 'https://');
        if (!$isHttps) {
            self::add($findings, 'transport', 'critical', 'Site is not served over HTTPS',
                'The final response was served over plain HTTP, so all traffic (including any forms, cookies, or logins) is unencrypted and can be intercepted or modified in transit.',
                'Obtain a TLS certificate (e.g. free via Let\'s Encrypt) and redirect all HTTP traffic to HTTPS.');
        }

        if (count($r->redirectChain) > 1 && !$isHttps) {
            self::add($findings, 'transport', 'medium', 'HTTP is not redirected to HTTPS',
                'The site was reached over HTTP and did not redirect to an HTTPS version.',
                'Add a permanent (301) redirect from http:// to https:// at the web server or .htaccess level.');
        }

        if ($isHttps && $r->tlsInfo !== null) {
            $cert = $r->tlsInfo['cert'] ?? null;
            if (is_array($cert) && !empty($cert['Expire date'])) {
                $expiry = strtotime($cert['Expire date']);
                if ($expiry !== false) {
                    $daysLeft = (int) floor(($expiry - time()) / 86400);
                    if ($daysLeft < 0) {
                        self::add($findings, 'transport', 'critical', 'TLS certificate has expired',
                            'The SSL/TLS certificate expired ' . abs($daysLeft) . ' day(s) ago. Browsers will show a hard security warning to every visitor.',
                            'Renew the TLS certificate immediately.');
                    } elseif ($daysLeft < 14) {
                        self::add($findings, 'transport', 'high', 'TLS certificate expires soon',
                            "The SSL/TLS certificate expires in {$daysLeft} day(s).",
                            'Renew the certificate now, or enable auto-renewal (e.g. certbot) to avoid an outage.');
                    }
                }
            }
        }
    }

    private static function checkSecurityHeaders(FetchResult $r, array &$findings): void
    {
        $checks = [
            'strict-transport-security' => ['high', 'Missing HSTS header',
                'No Strict-Transport-Security header was returned, so browsers will not enforce HTTPS on repeat visits and downgrade attacks (SSL-stripping) remain possible.',
                'Send "Strict-Transport-Security: max-age=31536000; includeSubDomains" once HTTPS is confirmed to work sitewide.'],
            'x-content-type-options' => ['medium', 'Missing X-Content-Type-Options header',
                'Without "nosniff", some browsers will try to guess a response\'s content type, which can turn an uploaded file into executable script in certain scenarios.',
                'Send "X-Content-Type-Options: nosniff" on every response.'],
            'x-frame-options' => ['medium', 'Missing clickjacking protection (X-Frame-Options / frame-ancestors)',
                'Neither an X-Frame-Options header nor a CSP frame-ancestors directive was found, so the page can be embedded in a hidden iframe on another site and used for clickjacking.',
                'Send "X-Frame-Options: DENY" (or a CSP "frame-ancestors \'none\'") unless the page must legitimately be embedded elsewhere.'],
            'content-security-policy' => ['medium', 'Missing Content-Security-Policy header',
                'No CSP header was found. CSP is the strongest available defense-in-depth control against XSS and data-injection attacks.',
                'Add a Content-Security-Policy header restricting script-src, style-src, and frame-ancestors to trusted origins.'],
            'referrer-policy' => ['low', 'Missing Referrer-Policy header',
                'Without a Referrer-Policy, full URLs (which can include tokens or query parameters) may leak to third-party sites via the Referer header.',
                'Send "Referrer-Policy: strict-origin-when-cross-origin" or stricter.'],
            'permissions-policy' => ['low', 'Missing Permissions-Policy header',
                'No Permissions-Policy header restricts access to sensitive browser features (camera, microphone, geolocation) for embedded/third-party content.',
                'Send a Permissions-Policy header disabling features the site does not use.'],
        ];

        foreach ($checks as $header => [$severity, $title, $desc, $rec]) {
            if ($r->header($header) === null) {
                if ($header === 'x-frame-options') {
                    $csp = $r->header('content-security-policy') ?? '';
                    if (str_contains($csp, 'frame-ancestors')) {
                        continue;
                    }
                }
                self::add($findings, 'security-headers', $severity, $title, $desc, $rec);
            }
        }

        $serverHeader = $r->header('server');
        if ($serverHeader !== null && preg_match('/\d/', $serverHeader)) {
            self::add($findings, 'security-headers', 'low', 'Server header discloses version information',
                "The Server header (\"{$serverHeader}\") reveals specific software/version details that make it easier for an attacker to target known vulnerabilities.",
                'Configure the web server to send a generic or empty Server header.');
        }

        $poweredBy = $r->header('x-powered-by');
        if ($poweredBy !== null) {
            self::add($findings, 'security-headers', 'low', 'X-Powered-By header discloses technology stack',
                "The X-Powered-By header (\"{$poweredBy}\") reveals the backend technology and version in use.",
                'Disable the X-Powered-By header (e.g. "expose_php = Off" in php.ini).');
        }
    }

    private static function checkCookies(FetchResult $r, array &$findings): void
    {
        foreach ($r->setCookies as $cookie) {
            $lower = strtolower($cookie);
            if (!str_contains($lower, 'secure') && str_starts_with(strtolower($r->finalUrl), 'https://')) {
                self::add($findings, 'cookies', 'medium', 'Cookie set without the Secure flag',
                    'A cookie was set without the "Secure" attribute, so it could be sent over an unencrypted connection if one is ever reachable.',
                    'Add the Secure attribute to every cookie on an HTTPS site.');
            }
            if (!str_contains($lower, 'httponly')) {
                self::add($findings, 'cookies', 'medium', 'Cookie set without the HttpOnly flag',
                    'A cookie was set without "HttpOnly", meaning JavaScript can read it — if the site has any XSS vulnerability, this cookie could be stolen.',
                    'Add the HttpOnly attribute to cookies that don\'t need to be read by client-side JavaScript.');
            }
            if (!str_contains($lower, 'samesite')) {
                self::add($findings, 'cookies', 'low', 'Cookie set without a SameSite attribute',
                    'Without SameSite, this cookie may be sent on cross-site requests, which can enable CSRF in some browsers.',
                    'Set SameSite=Lax (or Strict) on session/auth cookies.');
            }
        }
    }

    private static function checkExposedPaths(string $baseUrl, array &$findings): void
    {
        $parts = parse_url($baseUrl);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');

        $sensitivePaths = [
            '/.env' => ['critical', 'Environment file (.env) is publicly accessible',
                'A request to /.env returned a 200 response. This file typically contains database credentials, API keys, and app secrets.'],
            '/.git/config' => ['critical', 'Git repository metadata is publicly accessible',
                'A request to /.git/config returned a 200 response, meaning the full source history (including any past secrets) may be downloadable.'],
            '/wp-config.php.bak' => ['high', 'Backup config file is publicly accessible',
                'A backup of a configuration file was found publicly accessible, which can expose credentials.'],
        ];

        foreach ($sensitivePaths as $path => [$severity, $title, $desc]) {
            $res = SafeHttpClient::fetch($origin . $path);
            if ($res->ok && $res->status === 200 && strlen($res->body) > 0) {
                self::add($findings, 'exposed-files', $severity, $title, $desc,
                    'Remove or block public access to this path at the web server level (it should never be inside the public document root).');
            }
        }

        $robots = SafeHttpClient::fetch($origin . '/robots.txt');
        if (!$robots->ok || $robots->status !== 200) {
            self::add($findings, 'seo', 'low', 'No robots.txt found',
                'robots.txt was missing or unreachable. It is not required, but its absence means there is no explicit crawling policy for search engines.',
                'Add a robots.txt file with at least a default crawling policy and a sitemap reference.');
        }

        $sitemap = SafeHttpClient::fetch($origin . '/sitemap.xml');
        if (!$sitemap->ok || $sitemap->status !== 200) {
            self::add($findings, 'seo', 'info', 'No sitemap.xml found',
                'sitemap.xml was missing or unreachable, which can slow down how quickly search engines discover new pages.',
                'Generate and publish a sitemap.xml, and reference it from robots.txt.');
        }
    }

    private static function checkSeo(FetchResult $r, array &$findings): void
    {
        $body = $r->body;

        if (!preg_match('/<title[^>]*>(.*?)<\/title>/is', $body, $m) || trim(strip_tags($m[1])) === '') {
            self::add($findings, 'seo', 'medium', 'Missing or empty <title> tag',
                'The page has no <title> element (or it is empty). This is one of the strongest on-page SEO and browser-tab signals.',
                'Add a unique, descriptive <title> (50–60 characters) to every page.');
        }

        if (!preg_match('/<meta[^>]+name=["\']description["\'][^>]*>/i', $body)) {
            self::add($findings, 'seo', 'low', 'Missing meta description',
                'No <meta name="description"> tag was found, so search engines will auto-generate a snippet instead of using a curated one.',
                'Add a concise meta description (roughly 120–160 characters) summarizing the page.');
        }

        if (!preg_match('/<html[^>]+lang=["\'][a-zA-Z-]+["\']/i', $body)) {
            self::add($findings, 'accessibility', 'medium', 'Missing lang attribute on <html>',
                'The <html> tag has no "lang" attribute, which hurts screen-reader pronunciation and search-engine language detection.',
                'Add lang="en" (or the site\'s primary language code) to the <html> tag.');
        }

        if (!preg_match('/<meta[^>]+property=["\']og:title["\']/i', $body)) {
            self::add($findings, 'seo', 'info', 'Missing Open Graph tags',
                'No Open Graph (og:title/og:description/og:image) tags were found, so link previews on social platforms and chat apps will look generic.',
                'Add basic Open Graph and Twitter Card meta tags.');
        }

        if (preg_match_all('/<h1[^>]*>/i', $body, $h1m) && count($h1m[0]) > 1) {
            self::add($findings, 'seo', 'low', 'Multiple <h1> tags on the page',
                'The page contains ' . count($h1m[0]) . ' <h1> elements. Search engines and assistive technology expect a single top-level heading per page.',
                'Use one <h1> per page and structure the rest of the content with <h2>/<h3>.');
        }
    }

    private static function checkMobileAndAccessibility(FetchResult $r, array &$findings): void
    {
        $body = $r->body;

        if (!preg_match('/<meta[^>]+name=["\']viewport["\']/i', $body)) {
            self::add($findings, 'mobile', 'high', 'Missing viewport meta tag',
                'No <meta name="viewport"> tag was found. Without it, mobile browsers render the page at desktop width and scale it down, producing a broken mobile experience.',
                'Add <meta name="viewport" content="width=device-width, initial-scale=1"> to the <head>.');
        }

        $imgCount = preg_match_all('/<img\b[^>]*>/i', $body, $imgs);
        if ($imgCount > 0) {
            $missingAlt = 0;
            foreach ($imgs[0] as $img) {
                if (!preg_match('/\balt\s*=\s*["\']/i', $img)) {
                    $missingAlt++;
                }
            }
            if ($missingAlt > 0) {
                self::add($findings, 'accessibility', 'medium', 'Images missing alt text',
                    "{$missingAlt} of {$imgCount} <img> tag(s) have no alt attribute, so screen-reader users get no description and broken images show no fallback text.",
                    'Add a meaningful alt attribute to every content image (use alt="" only for purely decorative images).');
            }
        }

        $inputCount = preg_match_all('/<input\b[^>]*>/i', $body, $inputs);
        if ($inputCount > 0) {
            $unlabeled = 0;
            foreach ($inputs[0] as $input) {
                if (preg_match('/\btype\s*=\s*["\'](hidden|submit|button)["\']/i', $input)) {
                    continue;
                }
                if (!preg_match('/\baria-label\s*=/i', $input) && !preg_match('/\bid\s*=\s*["\']([^"\']+)["\']/i', $input, $idm)) {
                    $unlabeled++;
                } elseif (isset($idm[1]) && !preg_match('/<label[^>]+for=["\']' . preg_quote($idm[1], '/') . '["\']/i', $body) && !preg_match('/\baria-label\s*=/i', $input)) {
                    $unlabeled++;
                }
            }
            if ($unlabeled > 0) {
                self::add($findings, 'accessibility', 'medium', 'Form inputs without an associated label',
                    "{$unlabeled} form input(s) appear to have neither a <label for=\"...\"> nor an aria-label, making the form hard or impossible to use with a screen reader.",
                    'Associate every input with a <label for="..."> (or add aria-label) so assistive technology can announce its purpose.');
            }
        }

        if (preg_match_all('/<a\b[^>]*>(.*?)<\/a>/is', $body, $links)) {
            $empty = 0;
            foreach ($links[1] as $inner) {
                if (trim(strip_tags($inner)) === '' && !preg_match('/aria-label/i', $inner)) {
                    $empty++;
                }
            }
            if ($empty > 0) {
                self::add($findings, 'accessibility', 'low', 'Links with no accessible text',
                    "{$empty} <a> element(s) have no visible text or aria-label, so screen readers announce them as just \"link\".",
                    'Give every link either visible text or an aria-label describing its destination.');
            }
        }
    }

    private static function checkPerformance(FetchResult $r, array &$findings): void
    {
        $sizeKb = round(strlen($r->body) / 1024, 1);
        if ($sizeKb > 2000) {
            self::add($findings, 'performance', 'medium', 'HTML document is very large',
                "The HTML document is {$sizeKb} KB, which is large enough to noticeably slow first paint, especially on mobile networks.",
                'Move large inline scripts/styles/data into separate cached files, and consider server-side pagination for large content blocks.');
        }

        if ($r->totalTimeMs > 3000) {
            self::add($findings, 'performance', 'medium', 'Slow server response time',
                'The page took ' . round($r->totalTimeMs) . ' ms to respond, well above the ~500-800 ms generally considered a good Time to First Byte.',
                'Investigate server-side bottlenecks (uncached queries, slow includes) or add a caching layer / CDN.');
        }

        $encoding = $r->header('content-encoding');
        if ($encoding === null && strlen($r->body) > 5000) {
            self::add($findings, 'performance', 'low', 'Response is not compressed',
                'No Content-Encoding (gzip/br) was returned for a response over 5 KB, so more bytes are transferred than necessary.',
                'Enable gzip or Brotli compression at the web server level.');
        }

        $renderBlockingScripts = preg_match_all('/<script(?![^>]*\b(async|defer|type=["\']module["\'])\b)[^>]+src=/i', $r->body);
        if ($renderBlockingScripts > 2) {
            self::add($findings, 'performance', 'low', 'Multiple render-blocking scripts',
                "{$renderBlockingScripts} <script src=\"...\"> tag(s) in <head>/body have neither async nor defer, which blocks HTML parsing while each one downloads and executes.",
                'Add the defer (or async, where order doesn\'t matter) attribute to non-critical scripts.');
        }
    }

    private static function checkCommonVulnerabilitySignals(FetchResult $r, array &$findings): void
    {
        $body = $r->body;

        if (preg_match('/(Warning|Fatal error|Notice):.*?on line \d+/i', $body)
            || preg_match('/Stack trace:/i', $body)
            || preg_match('/Traceback \(most recent call last\)/i', $body)) {
            self::add($findings, 'vulnerabilities', 'high', 'Application error output is exposed to visitors',
                'A raw PHP/Python error message or stack trace was found in the page output. Stack traces routinely leak file paths, library versions, and query fragments useful for an attacker.',
                'Disable debug/verbose error display in production and log errors server-side instead.');
        }

        $acao = $r->header('access-control-allow-origin');
        if ($acao === '*' && $r->header('access-control-allow-credentials') === 'true') {
            self::add($findings, 'vulnerabilities', 'critical', 'Dangerous CORS configuration',
                'Access-Control-Allow-Origin is "*" together with Access-Control-Allow-Credentials: true. Browsers should reject this combination, but where they do not it lets any site read authenticated responses.',
                'Never combine a wildcard origin with allow-credentials; reflect a specific, validated origin instead.');
        }

        if (preg_match('/<form\b[^>]*method=["\']post["\'][^>]*>(?!(?:(?!<\/form>).)*_csrf|(?:(?!<\/form>).)*csrf_token)/is', $body)) {
            self::add($findings, 'vulnerabilities', 'info', 'POST form without an obvious CSRF token',
                'At least one POST form was found with no field named containing "csrf" in its markup. This is a heuristic only — server-side CSRF protection may still exist another way.',
                'Confirm every state-changing POST endpoint validates a per-session CSRF token.');
        }

        if (preg_match('/jquery[.-]1\.(?:[0-9]|1[01])\b/i', $body) || preg_match('/jquery[.-]2\.[0-9]\b/i', $body)) {
            self::add($findings, 'vulnerabilities', 'medium', 'Outdated jQuery version referenced',
                'A reference to an old jQuery 1.x/2.x build was found. Several of these versions have known, publicly documented XSS vulnerabilities.',
                'Upgrade to a current, supported jQuery release (3.x or later).');
        }

        if (preg_match('/<input[^>]+type=["\']password["\'][^>]*>/i', $body) && !str_starts_with(strtolower($r->finalUrl), 'https://')) {
            self::add($findings, 'vulnerabilities', 'critical', 'Password field submitted over an insecure page',
                'A password input was found on a page served over plain HTTP, so credentials would be sent in clear text.',
                'Serve any page containing a login or password field exclusively over HTTPS.');
        }
    }
}
