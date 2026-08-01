<?php

namespace B7S\Catraca\Gate;

use Symfony\Component\Process\Process;

use function array_filter;
use function count;
use function escapeshellarg;
use function file_exists;
use function file_get_contents;
use function glob;
use function in_array;
use function is_array;
use function is_dir;
use function is_string;
use function json_decode;
use function max;
use function mb_strimwidth;
use function mb_strpos;
use function mb_substr;
use function mb_substr_count;
use function preg_match;
use function preg_match_all;
use function str_contains;
use function str_starts_with;
use function strtotime;
use function time;
use function trim;

class SecuritySubCheck
{
    use ScansSourceFiles;

    private const array EXCLUDE_PATHS = [
        'vendor',
        'node_modules',
        'storage',
        'bootstrap/cache',
        '.git',
    ];

    private const array EXCLUDE_PATHS_WITH_TESTS = [
        'vendor',
        'node_modules',
        'storage',
        'bootstrap/cache',
        '.git',
        'tests',
    ];

    private const string USER_INPUT_SOURCES = 'request|_GET|_POST|_REQUEST|input';

    private const array HARD_CODED_SECRET_PATTERNS = [
        '/["\'](?:password|passwd|pwd|secret|api_key|apikey|api_secret|token|auth_token|access_token|private_key|client_secret|app_secret|webhook_secret|refresh_token|bearer|authorization|gitlab_token|gitlab_pat|google_api_key|gcp_key|paypal_secret|paypal_client_secret|stripe_key|twilio_token|sendgrid_key|mailgun_key|slack_token|discord_token|notion_token|openai_key|openai_api_key|anthropic_key|anthropic_api_key|claude_api_key|claude_key|mistral_api_key|groq_api_key|replicate_api_key|replicate_token|huggingface_token|hf_token|perplexity_api_key|together_api_key|gemini_api_key|vertex_api_key|openrouter_api_key|fireworks_api_key|xai_api_key|grok_api_key|elevenlabs_api_key|elevenlabs_apikey|cohere_api_key|langchain_api_key|langsmith_api_key|langfuse_secret_key|langfuse_public_key|voyage_api_key|jina_api_key|databricks_token|npm_token|pypi_token|shopify_token|mapbox_token|square_token|firebase_key|oauth_secret|signing_secret|encryption_key|db_password|database_password)["\']\s*=>\s*["\'][^"\']{4,}["\']/i',
        '/\$(?:password|secret|api_key|apikey|token|access_token|private_key|client_secret|gitlab_token|google_api_key|paypal_secret|refresh_token|webhook_secret|anthropic_key|claude_key|openai_key|grok_key|mistral_key|hf_token|replicate_token|perplexity_key|langsmith_key|langfuse_secret)\s*=\s*["\'][^"\']{4,}["\']/i',
        '/AKIA[0-9A-Z]{16}/',
        '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/',
        '/sk_(?:live|test)_[0-9a-zA-Z]{24,}/',
        '/["\']Bearer\s+[A-Za-z0-9\-._~+\/]{20,}["\']/',
        '/ghp_[A-Za-z0-9]{36}/',
        '/github_pat_[A-Za-z0-9_]{20,}/',
        '/gho_[A-Za-z0-9]{36}/',
        '/ghu_[A-Za-z0-9]{36}/',
        '/ghs_[A-Za-z0-9]{36}/',
        '/ghr_[A-Za-z0-9]{36}/',
        '/xox[baprs]-[0-9A-Za-z\-]{10,}/',
        '/gl(?:pat|cbt|dt|rt)-[A-Za-z0-9_.-]{20,}/',
        '/AIza[0-9A-Za-z\-_]{35}/',
        '/GOCSPX-[A-Za-z0-9_-]{20,}/',
        '/ya29\.[A-Za-z0-9_-]+/',
        '/WH-[0-9A-Z][A-Za-z0-9-]{10,}/',
        '/access_token\\\$production\\\$/',
        '/access_token\\\$sandbox\\\$/',
        '/access_token\$production\$/',
        '/access_token\$sandbox\$/',
        '/sk-[A-Za-z0-9]+T3BlbkFJ[A-Za-z0-9]+/',
        '/\bsk-[A-Za-z0-9]{45,}\b/',
        '/sk-proj-[A-Za-z0-9_-]{20,}/',
        '/sk-or-v1-[A-Za-z0-9_-]{20,}/',
        '/sk-ant-[A-Za-z0-9_-]{10,}/',
        '/cg_[A-Za-z0-9]{20,}/',
        '/gsk_[A-Za-z0-9]{20,}/',
        '/\br8_[A-Za-z0-9]{30,}\b/',
        '/\bhf_[A-Za-z0-9]{30,}\b/',
        '/pplx-[A-Za-z0-9_-]{20,}/',
        '/xai-[A-Za-z0-9_-]{20,}/',
        '/fw_[A-Za-z0-9_-]{20,}/',
        '/pa-[A-Za-z0-9_-]{30,}/',
        '/jina_[A-Za-z0-9_-]{20,}/',
        '/sk-lf-[A-Za-z0-9_-]{20,}/',
        '/lsv2_(?:pt|sk)_[A-Za-z0-9_-]{20,}/',
        '/npm_[A-Za-z0-9]{36,}/',
        '/pypi-AgE[Ii][0-9A-Za-z_-]{50,}/',
        '/rubygems_[A-Za-z0-9]{48,}/',
        '/SK[0-9a-f]{32}/',
        '/AC[0-9a-f]{32}/',
        '/SG\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/',
        '/key-[0-9a-fA-F]{32}/',
        '/shp(?:at|ss|ca|pa)_[a-fA-F0-9]{32}/',
        '/sq0(?:csp|atp|idp)-[A-Za-z0-9_-]{10,}/',
        '/(?:pk|sk)\.[a-zA-Z0-9]{60,}/',
        '/sl\.[A-Za-z0-9_-]{10,}-[A-Za-z0-9_-]{10,}/',
        '/[0-9]{10,}\/[0-9]:[a-f0-9]{32}:/',
        '/\b(?:[MN][A-Za-z\d]{23}|[A-Za-z\d]{23,28})\.[A-Za-z\d-]{6}\.[A-Za-z\d_-]{27,}\b/',
        '/\d{8,10}:[A-Za-z0-9_-]{35}/',
        '/oauth:[a-z0-9]{30,}/i',
        '/\bsecret_[A-Za-z0-9]{40,}\b/',
        '/lin_api_[A-Za-z0-9]{20,}/',
        '/dapi[a-f0-9]{32}/',
        '/NRAK-[A-Z0-9]{27}/',
        '/[a-f0-9]{32}@o[0-9]+\.ingest\.(?:de\.)?sentry\.io/',
        '/cloudinary:\/\/[0-9]+:[^@"\s]+@[a-z0-9.-]+/i',
        '/dop_v1_[a-f0-9]{64}/',
        '/pat-(?:eu1|na1)-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/',
        '/EAA[a-zA-Z0-9]{20,}/',
        '/[a-f0-9]{32}-us[0-9]{1,2}\b/',
        '/"private_key"\s*:\s*"-----BEGIN/',
    ];

    private const array SAFE_SECRET_FUNCTIONS = ['env(', 'config(', 'getenv('];

    private const array SQL_INJECTION_PATTERNS = [
        '/DB::\w+\s*\(\s*(?:"[^"]*\$[a-z_\[\'"]|\'[^\']*\$[a-z_\[\'"])/i',
        '/->whereRaw\s*\(\s*(?:"[^"]*\$[a-z_\[\'"]|\'[^\']*\$[a-z_\[\'"])/i',
        '/->(?:select|orderBy|having|groupBy|from)Raw\s*\(\s*(?:"[^"]*\$[a-z_\[\'"]|\'[^\']*\$[a-z_\[\'"])/i',
        '/DB::raw\s*\(\s*(?:"[^"]*\$[a-z_\[\'"]|\'[^\']*\$[a-z_\[\'"])/i',
        '/DB::\w+\s*\(\s*["\'][^"\']*["\']\s*\.\s*\$/',
        '/sprintf\s*\(\s*["\'](?:SELECT|INSERT|UPDATE|DELETE|CREATE|DROP|ALTER)\b/i',
    ];

    private const array DANGEROUS_EXEC_FUNCTIONS = [
        'exec',
        'shell_exec',
        'system',
        'passthru',
        'proc_open',
        'popen',
    ];

    private const array GITIGNORE_REQUIRED_PATTERNS = [
        '.env',
        '*.key',
        '*.pem',
        '.env.backup',
        '.env.production',
    ];

    private const string WEAK_CRYPTO_SECURITY_KEYWORDS = 'password|secret|token|signature|hmac|api_?key|auth|verify|salt';

    private const string INSECURE_RNG_SECURITY_KEYWORDS = 'token|secret|reset|csrf|nonce|salt|password|verifier|api_?key|otp|2fa|confirmation';

    public function __construct(
        private readonly string $root,
        private readonly array $paths,
    ) {}

    public function checkHardcodedSecrets(): array
    {
        $findings = [];
        $files = $this->scanPhpFiles($this->paths, self::EXCLUDE_PATHS_WITH_TESTS);

        foreach ($files as $pathname) {
            $relative = $this->relativePath($this->root, $pathname);
            $lines = $this->readFileLines($pathname);
            if ($lines === null) {
                continue;
            }

            if ($this->isLangFile($relative) || $this->isDatabaseFile($relative)) {
                continue;
            }

            foreach ($lines as $i => $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#')) {
                    continue;
                }

                foreach (self::HARD_CODED_SECRET_PATTERNS as $pattern) {
                    if (!preg_match($pattern, $line)) {
                        continue;
                    }

                    $isSafe = false;
                    foreach (self::SAFE_SECRET_FUNCTIONS as $fn) {
                        if (str_contains($line, $fn)) {
                            $isSafe = true;
                            break;
                        }
                    }

                    if (!$isSafe && preg_match('/[\'"]password[\'"]\s*=>\s*[\'"]hashed[\'"]/i', $line)) {
                        $isSafe = true;
                    }

                    if (
                        !$isSafe
                        && preg_match(
                            '/=>\s*["\'](?:required|nullable|sometimes|present|prohibited|accepted|email|string|integer|numeric|uuid|url|json|file|image|confirmed|min:|max:|regex:|in:|not_in:|unique:|exists:)[^"\']*["\']/i',
                            $line,
                        )
                    ) {
                        $isSafe = true;
                    }

                    if (!$isSafe) {
                        $findings[] = "{$relative}:" . ($i + 1) . ' — ' . mb_strimwidth($trimmed, 0, 120, '…');
                        break;
                    }
                }
            }
        }

        return $findings;
    }

    public function checkSqlInjection(): array
    {
        $findings = [];

        foreach ($this->scanLines($this->paths, self::EXCLUDE_PATHS) as [$pathname, $i, $line]) {
            foreach (self::SQL_INJECTION_PATTERNS as $pattern) {
                if (preg_match($pattern, $line)) {
                    $findings[] = $this->fmt($this->root, $pathname, $i, $line, 'SQL injection risk');
                    break;
                }
            }
        }

        return $findings;
    }

    public function checkCommandInjection(): array
    {
        $findings = [];
        $fnPattern = implode('|', self::DANGEROUS_EXEC_FUNCTIONS);
        $pattern = '/\b(?:' . $fnPattern . ')\s*\([^)]*\$(?!this)[a-zA-Z_]/';

        foreach ($this->scanLines($this->paths, self::EXCLUDE_PATHS) as [$pathname, $i, $line]) {
            if (!preg_match($pattern, $line)) {
                continue;
            }
            if (preg_match('/escapeshellarg|escapeshellcmd/', $line)) {
                continue;
            }
            $findings[] = $this->fmt($this->root, $pathname, $i, $line, 'Command injection risk');
        }

        return $findings;
    }

    public function checkCsrf(): array
    {
        $viewsPath = $this->root . '/resources/views';
        if (!is_dir($viewsPath)) {
            return [];
        }

        $findings = [];
        $bladeFiles = $this->scanFiles([$viewsPath], ['blade.php'], self::EXCLUDE_PATHS);

        foreach ($bladeFiles as $pathname) {
            $content = file_get_contents($pathname);
            if ($content === false) {
                continue;
            }
            $relative = $this->relativePath($this->root, $pathname);

            if (preg_match_all('/<form\b[^>]*method=["\'](?:POST|PUT|PATCH|DELETE)["\'][^>]*>/i', $content, $matches)) {
                $httpForms = array_filter(
                    $matches[0],
                    static fn(string $formTag): bool => !preg_match('/\bwire:submit\b/i', $formTag),
                );

                if ($httpForms === []) {
                    continue;
                }

                $hasCsrf =
                    str_contains($content, '@csrf')
                    || str_contains($content, 'csrf_field()')
                    || str_contains($content, 'csrf_token()');

                if (!$hasCsrf) {
                    $findings[] =
                        "{$relative}: "
                        . count($httpForms)
                        . ' form(s) with POST/PUT/PATCH/DELETE but no @csrf directive.';
                }
            }
        }

        $kernelPath = $this->root . '/app/Http/Kernel.php';
        $bootstrapPath = $this->root . '/bootstrap/app.php';
        $middlewareSourceExists = file_exists($kernelPath) || file_exists($bootstrapPath);

        if ($middlewareSourceExists) {
            $kernelHasCsrf = false;
            $bootstrapHasCsrf = false;

            if (file_exists($kernelPath)) {
                $kernelContent = (string) file_get_contents($kernelPath);
                $kernelHasCsrf =
                    str_contains($kernelContent, 'VerifyCsrfToken')
                    || str_contains($kernelContent, 'PreventRequestForgery');
            }

            $bootstrapContent = file_exists($bootstrapPath) ? (string) file_get_contents($bootstrapPath) : '';
            if ($bootstrapContent !== '') {
                $bootstrapHasCsrf =
                    str_contains($bootstrapContent, 'VerifyCsrfToken')
                    || str_contains($bootstrapContent, 'PreventRequestForgery');
            }

            $onlyBootstrap = !file_exists($kernelPath) && file_exists($bootstrapPath);
            $csrfRemoved = $onlyBootstrap && $this->csrfMiddlewareRemovedFromWebInBootstrap($bootstrapContent);
            $csrfPresent = $kernelHasCsrf || $bootstrapHasCsrf || !$csrfRemoved;

            if (!$csrfPresent) {
                $findings[] = 'CSRF middleware (VerifyCsrfToken / PreventRequestForgery) not found in Kernel/bootstrap — check that CSRF is not disabled globally.';
            }
        }

        return $findings;
    }

    public function checkPathTraversal(): array
    {
        $findings = [];
        $userInput = self::USER_INPUT_SOURCES;

        foreach ($this->scanLines($this->paths, self::EXCLUDE_PATHS_WITH_TESTS) as [$pathname, $i, $line]) {
            if (preg_match('/\b(?:basename|realpath|pathinfo)\s*\(/', $line)) {
                continue;
            }

            if (preg_match(
                '/Storage::(?:disk\([^)]*\)->)?(?:get|put|putFileAs|move|copy|delete|download|readStream|writeStream)\s*\(\s*\$(?:'
                . $userInput
                . ')\b/',
                $line,
            )) {
                $findings[] = $this->fmt($this->root, $pathname, $i, $line, 'Storage:: with user-controlled path');

                continue;
            }

            if (preg_match(
                '/\b(file_get_contents|fopen|readfile|file_put_contents)\s*\(\s*\$(?:' . $userInput . ')\b/',
                $line,
                $m,
            )) {
                $findings[] = $this->fmt($this->root, $pathname, $i, $line, $m[1] . '() with user-controlled path');

                continue;
            }

            if (preg_match('/\b(include|include_once|require|require_once)\s+\$(?:' . $userInput . ')\b/', $line, $m)) {
                $findings[] = $this->fmt($this->root, $pathname, $i, $line, $m[1] . ' with user-controlled path');

                continue;
            }

            if (preg_match(
                '/(?:file_get_contents|fopen|readfile|file_put_contents|Storage::|include|require)\b[^;]*[\'"]\w+[\'"]\s*\.\s*\$(?:'
                . $userInput
                . ')\b/',
                $line,
            )) {
                $findings[] = $this->fmt(
                    $this->root,
                    $pathname,
                    $i,
                    $line,
                    'File operation with concatenated user input',
                );
            }
        }

        return $findings;
    }

    public function checkInsecureDeserialization(): array
    {
        $findings = [];

        foreach ($this->scanLines($this->paths, self::EXCLUDE_PATHS) as [$pathname, $i, $line]) {
            if (preg_match('/\bunserialize\s*\(\s*\$(?!this)[a-zA-Z_]/', $line)) {
                if (preg_match('/allowed_classes["\']?\s*=>/i', $line)) {
                    continue;
                }
                $findings[] = $this->fmt($this->root, $pathname, $i, $line, 'unserialize() with dynamic input');
            }

            if (
                preg_match('/unserialize\s*\(\s*base64_decode/', $line) && !$this->isInsideString($line, 'unserialize')
            ) {
                $findings[] = $this->fmt(
                    $this->root,
                    $pathname,
                    $i,
                    $line,
                    'unserialize(base64_decode(...)) — exploit chain',
                );
            }
        }

        return $findings;
    }

    public function checkSsrf(): array
    {
        $findings = [];
        $userInput = self::USER_INPUT_SOURCES;

        foreach ($this->scanLines($this->paths, self::EXCLUDE_PATHS_WITH_TESTS) as [$pathname, $i, $line]) {
            if (preg_match(
                '/\bHttp::(?:get|post|put|patch|delete|head|send)\s*\(\s*\$(?:' . $userInput . ')\b/',
                $line,
            )) {
                $findings[] = $this->fmt($this->root, $pathname, $i, $line, 'Http:: called with user-controlled URL');

                continue;
            }

            if (preg_match(
                '/->(?:request|get|post|put|patch|delete|head|send)\s*\(\s*(?:["\'][A-Z]+["\']\s*,\s*)?\$(?:'
                . $userInput
                . ')\b/',
                $line,
            )) {
                $content = file_get_contents($pathname);
                if (
                    $content !== false
                    && (
                        str_contains($content, 'GuzzleHttp')
                        || preg_match('/Guzzle|GuzzleHttp|HttpClient|Client/', $line)
                    )
                ) {
                    $findings[] = $this->fmt(
                        $this->root,
                        $pathname,
                        $i,
                        $line,
                        'HTTP client called with user-controlled URL',
                    );

                    continue;
                }
            }

            if (preg_match(
                '/\b(?:file_get_contents|fopen|get_headers|readfile)\s*\(\s*\$(?:' . $userInput . ')\b/',
                $line,
            )) {
                $findings[] = $this->fmt($this->root, $pathname, $i, $line, 'Remote call with user-controlled URL');

                continue;
            }

            if (preg_match('/curl_setopt\s*\([^,]+,\s*CURLOPT_URL\s*,\s*\$(?:' . $userInput . ')\b/', $line)) {
                $findings[] = $this->fmt(
                    $this->root,
                    $pathname,
                    $i,
                    $line,
                    'curl_setopt(CURLOPT_URL) with user-controlled URL',
                );
            }
        }

        return $findings;
    }

    public function checkTlsVerification(): array
    {
        $findings = [];

        foreach ($this->scanLines($this->paths, self::EXCLUDE_PATHS_WITH_TESTS) as [$pathname, $i, $line]) {
            if (
                preg_match('/->withoutVerifying\s*\(/', $line) || preg_match('/\bHttp::withoutVerifying\s*\(/', $line)
            ) {
                $findings[] = $this->fmt($this->root, $pathname, $i, $line, 'withoutVerifying() disables TLS');

                continue;
            }

            if (preg_match('/["\']verify["\']\s*=>\s*false/', $line)) {
                $findings[] = $this->fmt($this->root, $pathname, $i, $line, '\'verify\' => false disables TLS');

                continue;
            }

            if (preg_match('/CURLOPT_SSL_VERIFY(?:PEER|HOST)\s*,\s*(?:false|0)\b/i', $line)) {
                $findings[] = $this->fmt($this->root, $pathname, $i, $line, 'CURLOPT_SSL_VERIFYPEER/HOST disabled');

                continue;
            }

            if (preg_match('/["\']verify_peer(?:_name)?["\']\s*=>\s*false/', $line)) {
                $findings[] = $this->fmt($this->root, $pathname, $i, $line, 'verify_peer disabled in stream context');

                continue;
            }

            if (preg_match('/["\']allow_self_signed["\']\s*=>\s*true/', $line)) {
                $findings[] = $this->fmt($this->root, $pathname, $i, $line, 'allow_self_signed enabled');
            }
        }

        return $findings;
    }

    public function checkInsecureRng(): array
    {
        $findings = [];

        foreach ($this->scanLines($this->paths, self::EXCLUDE_PATHS_WITH_TESTS) as [$pathname, $i, $line]) {
            if (
                preg_match('/\b(rand|mt_rand|uniqid)\s*\(/', $line, $m)
                && preg_match('/' . self::INSECURE_RNG_SECURITY_KEYWORDS . '/i', $line)
            ) {
                $findings[] = $this->fmt(
                    $this->root,
                    $pathname,
                    $i,
                    $line,
                    $m[1] . '() used for a secret/token (use random_bytes)',
                );
            }
        }

        return $findings;
    }

    public function checkGitignore(): array
    {
        $gitignorePath = $this->root . '/.gitignore';
        if (!file_exists($gitignorePath)) {
            return ['.gitignore not found — sensitive files may be committed'];
        }

        $content = (string) file_get_contents($gitignorePath);
        $findings = [];

        foreach (self::GITIGNORE_REQUIRED_PATTERNS as $pattern) {
            if (str_contains($content, $pattern)) {
                continue;
            }

            if (!$this->sensitiveFileExists($pattern)) {
                continue;
            }

            $findings[] = "\"{$pattern}\" is not listed in .gitignore";
        }

        $envPath = $this->root . '/.env';
        if (file_exists($envPath)) {
            exec('git -C ' . escapeshellarg($this->root) . ' ls-files --error-unmatch .env 2>/dev/null', $out, $code);
            if ($code === 0) {
                $findings[] = '.env is actively tracked by git — remove with `git rm --cached .env`';
            }
        }

        return $findings;
    }

    public function checkPackageFreshness(int $minimumAgeDays = 3): array
    {
        $lockPath = $this->root . '/composer.lock';
        if (!file_exists($lockPath)) {
            return [];
        }

        $lock = @json_decode((string) file_get_contents($lockPath), true);
        if (!is_array($lock)) {
            return [];
        }

        $threshold = time() - ($minimumAgeDays * 86400);
        $findings = [];

        foreach (['packages', 'packages-dev'] as $section) {
            foreach ($lock[$section] ?? [] as $package) {
                $name = $package['name'] ?? null;
                $version = $package['version'] ?? '?';
                $time = $package['time'] ?? null;

                if ($name === null || $time === null) {
                    continue;
                }

                $releasedAt = strtotime($time);
                if ($releasedAt === false || $releasedAt < $threshold) {
                    continue;
                }

                $ageHours = max(0, (int) floor((time() - $releasedAt) / 3600));
                $scope = $section === 'packages-dev' ? ' [dev]' : '';
                $findings[] = "{$name} {$version} released {$ageHours}h ago{$scope}";
            }
        }

        return $findings;
    }

    public function checkWeakCryptography(): array
    {
        $findings = [];

        foreach ($this->scanLines($this->paths, self::EXCLUDE_PATHS_WITH_TESTS) as [$pathname, $i, $line]) {
            if (preg_match('/\bmcrypt_[a-z_]+\s*\(/', $line)) {
                $findings[] = $this->fmt($this->root, $pathname, $i, $line, 'mcrypt_* is deprecated and broken');

                continue;
            }

            if (preg_match('/openssl_(?:encrypt|decrypt)\s*\([^)]*[\'"][^"\']*-ecb[\'"]/i', $line)) {
                $findings[] = $this->fmt(
                    $this->root,
                    $pathname,
                    $i,
                    $line,
                    'ECB cipher mode is insecure (use CBC/GCM)',
                );

                continue;
            }

            if (
                preg_match('/[\'"](?:des|3des|rc4|rc2)(?:-[a-z0-9]+)?[\'"]/i', $line)
                && preg_match('/openssl_(?:encrypt|decrypt)/', $line)
            ) {
                $findings[] = $this->fmt($this->root, $pathname, $i, $line, 'DES/3DES/RC4 cipher is broken');

                continue;
            }

            if (
                preg_match('/\b(md5|sha1)\s*\(/i', $line, $m)
                && preg_match('/' . self::WEAK_CRYPTO_SECURITY_KEYWORDS . '/i', $line)
            ) {
                $findings[] = $this->fmt($this->root, $pathname, $i, $line, "weak hash {$m[1]}() in security context");
            }
        }

        return $findings;
    }

    public function checkCorsConfig(): array
    {
        $configPath = $this->root . '/config/cors.php';
        if (!file_exists($configPath)) {
            return [];
        }

        $content = (string) file_get_contents($configPath);
        $findings = [];

        $allowsAllOrigins = preg_match("/'allowed_origins'\s*=>\s*\[\s*['\"]\\*['\"]\s*\]/", $content) === 1;
        $allowsAllOriginsPatterns =
            preg_match("/'allowed_origins_patterns'\s*=>\s*\[\s*['\"]\\.\\*['\"]\s*\]/", $content) === 1;
        $supportsCredentials = preg_match("/'supports_credentials'\s*=>\s*true/", $content) === 1;
        $allowsAllHeaders = preg_match("/'allowed_headers'\s*=>\s*\[\s*['\"]\\*['\"]\s*\]/", $content) === 1;
        $allowsAllMethods = preg_match("/'allowed_methods'\s*=>\s*\[\s*['\"]\\*['\"]\s*\]/", $content) === 1;

        if (($allowsAllOrigins || $allowsAllOriginsPatterns) && $supportsCredentials) {
            $findings[] = 'config/cors.php: allowed_origins \'*\' combined with supports_credentials => true — CRITICAL';
        } elseif ($allowsAllOrigins || $allowsAllOriginsPatterns) {
            $findings[] = 'config/cors.php: allowed_origins is \'*\' — restrict to known origins';
        }

        if ($allowsAllHeaders && $supportsCredentials) {
            $findings[] = 'config/cors.php: allowed_headers \'*\' combined with supports_credentials => true';
        }

        if ($allowsAllMethods) {
            $findings[] = 'config/cors.php: allowed_methods is \'*\' — explicitly list only needed methods';
        }

        if (preg_match("/'paths'\s*=>\s*\[\s*['\"]\\*['\"]\s*\]/", $content)) {
            $findings[] = 'config/cors.php: paths is \'*\' — CORS applies to every route';
        }

        return $findings;
    }

    /**
     * @return array{findings: array<int, string>, critical: int}
     */
    public function runComposerAudit(string $composerBin): array
    {
        $process = new Process([$composerBin, 'audit', '--format=json'], timeout: 120);
        $process->setWorkingDirectory($this->root);
        $process->run();

        $output = $process->getOutput();
        $data = json_decode($output, true);
        if (!is_array($data)) {
            return ['findings' => [], 'critical' => 0];
        }

        $rawAdvisories = $data['advisories'] ?? [];
        $advisories = is_array($rawAdvisories) ? $rawAdvisories : [];

        $critical = array_filter($advisories, static function (mixed $a): bool {
            if (!is_array($a)) {
                return false;
            }
            $severity = $a['severity'] ?? '';

            return in_array($severity, ['critical', 'high'], true);
        });

        $criticalCount = count($critical);
        $findings = [];

        foreach ($critical as $a) {
            if (!is_array($a)) {
                continue;
            }
            $title = is_string($a['title'] ?? null) ? $a['title'] : 'unknown';
            $cve = is_string($a['cve'] ?? null) ? $a['cve'] : 'N/A';
            $findings[] = "{$title} ({$cve})";
        }

        return ['findings' => $findings, 'critical' => $criticalCount];
    }

    public function checkNpmAudit(): array
    {
        if (!file_exists($this->root . '/package.json')) {
            return [];
        }

        $hasLock =
            file_exists($this->root . '/package-lock.json')
            || file_exists($this->root . '/yarn.lock')
            || file_exists($this->root . '/pnpm-lock.yaml');

        if (!$hasLock) {
            return [];
        }

        $whichNpm = new Process(['which', 'npm']);
        $whichNpm->run();
        if (!$whichNpm->isSuccessful()) {
            return [];
        }

        $process = new Process(['npm', 'audit', '--json'], $this->root, timeout: 120);
        $process->run();

        $output = @json_decode($process->getOutput(), true) ?? [];
        $vulnerabilities = $output['vulnerabilities'] ?? [];
        if (!is_array($vulnerabilities)) {
            return [];
        }

        $findings = [];
        foreach ($vulnerabilities as $name => $vuln) {
            if (!is_array($vuln)) {
                continue;
            }
            $severity = $vuln['severity'] ?? 'unknown';
            if (in_array($severity, ['critical', 'high'], true)) {
                $via = $vuln['via'] ?? [];
                $title = $name;
                if (is_array($via)) {
                    foreach ($via as $v) {
                        if (is_array($v) && is_string($v['title'] ?? null)) {
                            $title = $v['title'];
                            break;
                        }
                    }
                }
                $findings[] = "[{$severity}] {$name}: {$title}";
            }
        }

        return $findings;
    }

    private function sensitiveFileExists(string $pattern): bool
    {
        if (str_starts_with($pattern, '*')) {
            return glob($this->root . '/' . $pattern) !== [];
        }

        return file_exists($this->root . '/' . $pattern);
    }

    private function isInsideString(string $line, string $keyword): bool
    {
        $pos = mb_strpos($line, $keyword);
        if ($pos === false) {
            return false;
        }

        $before = mb_substr($line, 0, (int) $pos);
        $singleQuotes = mb_substr_count($before, "'");
        $doubleQuotes = mb_substr_count($before, '"');

        return ($singleQuotes % 2) !== 0 || ($doubleQuotes % 2) !== 0;
    }

    private function csrfMiddlewareRemovedFromWebInBootstrap(string $bootstrap): bool
    {
        if (!preg_match('/->web\s*\(\s*remove:\s*\[/s', $bootstrap, $m, PREG_OFFSET_CAPTURE)) {
            return false;
        }

        $from = (int) $m[0][1];
        $chunk = substr($bootstrap, $from, 3000);

        return (bool) preg_match('/PreventRequestForgery|VerifyCsrfToken|ValidateCsrfToken/i', $chunk);
    }
}
