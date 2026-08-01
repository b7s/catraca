<div align="center">
    <img src="logo.webp" width="256" alt="Catraca Logo">
</div>

# Catraca

PHP Quality Guardian that enforces the **Catraca (ratchet) principle**: quality metrics can only improve or stay the same, never regress.

_Last reviewed: 2026-08-01._

> **Catraca** (Portuguese for "turnstile" / "ratchet") — like a turnstile at a subway station, quality can only move forward.

## Install

```bash
composer require b7s/catraca --dev
```

## Quality Gates

Gates run in order. A failure blocks the PR.

| # | Gate | Default tool order | Default threshold |
|---|------|--------------------|-------------------|
| 1 | Security Audit | Composer audit + built-in checks | 0 critical/high advisories, 0 findings |
| 2 | Code Style | Mago format -> Pint -> PHP CS Fixer | 0 violations |
| 3 | Static Analysis | Mago analyze -> PHPStan -> Psalm | 0 errors |
| 4 | Test Coverage | Pest -> PHPUnit | 85% minimum |
| 5 | Duplication | PHPCPD | 0% maximum |
| 6 | File Size | Built-in | 1000 lines per file |
| 7 | Cyclomatic Complexity | PHP Metrics | Block at 50, warn at 20 |
| 8 | Performance | Mago lint -> PHP CS Fixer, plus built-in checks | 0 violations |

## Dependencies

Catraca wraps your existing PHP quality tools. Install the ones you need. Mago 1.45.0 or newer is the preferred v2 backend:

```bash
# Preferred formatter, analyzer, and linter
composer require --dev "carthage-software/mago:>=1.45.0"
vendor/bin/mago init

# Code style fallbacks
composer require --dev laravel/pint
# or
composer require --dev friendsofphp/php-cs-fixer

# Static analysis
composer require --dev phpstan/phpstan
# or
composer require --dev vimeo/psalm

# Test coverage
composer require --dev phpunit/phpunit
# or
composer require --dev pestphp/pest

# Duplication detection
# PHP 8.3
composer require --dev systemsdk/phpcpd:^8.0
# PHP 8.4+
composer require --dev systemsdk/phpcpd:^9.0

# Cyclomatic complexity
composer require --dev phpmetrics/phpmetrics
```

With `tool: auto`, Catraca tries the centrally defined fallback order and skips a gate only when none of its candidates is installed. An explicitly selected missing tool is reported without silently switching tools. Security audit uses `composer audit` (built-in) and 14 source-code checks.

## Usage

### `catraca init` — Initialize baseline

Creates `catraca_baseline.json` in your project root with default thresholds:

```bash
vendor/bin/catraca init
```

Default baseline:

| Setting | Default |
|---------|---------|
| Source Dirs | `["src", "app", "lib"]` |
| Security | 0 advisories, 14 checks all enabled |
| Code Style | 0 violations |
| Static Analysis | Current Mago, PHPStan, or Psalm error count captured by `init` |
| Test Coverage | 85% minimum |
| Duplication | 0% maximum, min 3 lines, min 30 tokens |
| File Size | 1000 lines maximum per file |
| Complexity | Block at CCN 50, warn at CCN 20 |
| Performance | 0 violations |

You can edit `catraca_baseline.json` directly to adjust thresholds.

### Configuration — `catraca_baseline.json`

Configuration and measured results are stored separately:

```json
{
    "schema": "catraca/v2",
    "config": {
        "source_dirs": {
            "paths": ["src", "app", "lib"],
            "exclude": ["vendor", ".git", "node_modules"]
        },
        "policy": {
            "missing_tool": "skip",
            "unavailable_metric": "warn",
            "internal_error": "fail"
        },
        "process": {
            "timeout_seconds": 1200
        },
        "tools": {
            "format": "auto",
            "analyze": "auto",
            "coverage": "auto",
            "lint": "auto",
            "options": {
                "mago": {
                    "threads": 0,
                    "minimum_report_level": "error",
                    "minimum_version": "1.45.0"
                }
            }
        },
        "history": {
            "enabled": false,
            "retention": 50
        },
        "security": {
            "mode": "no_regression",
            "rules": {
                "hardcoded_secrets": true,
                "sql_injection": true,
                "command_injection": true
            },
            "released_days": 3
        },
        "duplication": {
            "mode": "no_regression",
            "max_percentage": 0.0,
            "min_lines": 3,
            "min_tokens": 30
        },
        "file_size": {
            "mode": "no_regression",
            "max_lines": 1000
        },
        "complexity": {
            "mode": "no_regression",
            "block_at": 50,
            "warn_at": 20
        },
        "performance": {
            "mode": "no_regression",
            "rules": {
                "global_namespace_import": true,
                "no_unused_imports": true,
                "fully_qualified_strict_types": true,
                "condition_order": true
            },
            "fixers": {
                "condition_order": false
            }
        },
        "style": { "mode": "no_regression" },
        "static_analysis": { "mode": "no_regression" },
        "coverage": { "mode": "no_regression", "floor": 85.0 },
        "parallel": {
            "enabled": true,
            "max_processes": 4
        }
    },
    "results": {
        "security": { "advisories": 0, "findings": 0, "critical": 0 },
        "style": { "violations": 0 },
        "static_analysis": { "errors": 0 },
        "coverage": { "percentage": 85.0 },
        "duplication": { "percentage": 0.0, "clones": 0 },
        "file_size": { "over_limit": 0 },
        "complexity": { "max_ccn": 0, "violations": 0, "warnings": 0 },
        "performance": { "violations": 0 }
    }
}
```

**`source_dirs.paths`** — which directories Catraca scans for PHP files. Only directories that exist on disk are used. If none of the configured directories exist, the project root is used as fallback. Defaults to `["src", "app", "lib"]`.

**`config` and `results`** — `catraca init` updates only `results`, so thresholds, rule toggles, source paths, and parallel settings are never overwritten by a run. Existing `catraca/v1` files are migrated automatically on the next `init` or `check`. During the v1 → v2 migration, each gate is configured to use the project's currently installed packages (for example Pint, PHPStan, or PHP CS Fixer) instead of Mago, so upgrading does not silently swap your existing toolchain. Mago stays the default only for new projects or when none of the other supported packages are installed.

**`parallel.max_processes`** — maximum number of concurrent gate workers. The default is `4`; `128` is only a safety ceiling, not a machine requirement. Catraca starts at most one worker per gate, and machines with fewer CPU cores remain supported because the operating system schedules those workers. Gate workers do not create nested workers, preventing process fan-out on CI runners.

### Choosing a tool for each validation

For gates with interchangeable backends, set the value under `config.tools` to `auto` or to one explicit tool. All fallback orders and valid values are defined centrally, so an invalid value fails with a message listing the correct choices.

| Validation | Key under `config.tools` | Valid values | What Catraca runs |
|------------|--------------------------|--------------|-------------------|
| Code Style | `format` | `auto`, `mago`, `pint`, `php-cs-fixer` | `mago format --check`, Pint, or PHP CS Fixer |
| Static Analysis | `analyze` | `auto`, `mago`, `phpstan`, `psalm` | `mago analyze`, PHPStan, or Psalm |
| Test Coverage | `coverage` | `auto`, `pest`, `phpunit` | Pest or PHPUnit with Clover output |
| Performance | `lint` | `auto`, `mago`, `php-cs-fixer` | `mago lint` or PHP CS Fixer, plus built-in autoload and condition-order checks |
| Security | Not selectable | — | Composer audit plus built-in source checks |
| Duplication | Not selectable | — | PHPCPD |
| File Size | Not selectable | — | Built-in scanner |
| Complexity | Not selectable | — | PHP Metrics |

`auto` is the v2 default. It selects the first installed tool in the order shown in the Quality Gates table; Mago is preferred for style, static analysis, and performance. An explicit choice does not silently switch to a different external tool if that executable is missing.

**Example — Switching from Mago to PHPStan + Pint + PHPUnit:**

```json
{
    "config": {
        "tools": {
            "format": "pint",
            "analyze": "phpstan",
            "coverage": "phpunit",
            "lint": "php-cs-fixer"
        }
    }
}
```

**Example — Mixed: Mago for analyze and lint, Pint for format, PHPUnit for coverage:**

```json
{
    "config": {
        "tools": {
            "format": "pint",
            "analyze": "mago",
            "coverage": "phpunit",
            "lint": "mago"
        }
    }
}
```

**Example — Mago with custom configuration:**

```json
{
    "config": {
        "tools": {
            "format": "mago",
            "analyze": "mago",
            "coverage": "auto",
            "lint": "mago",
            "options": {
                "mago": {
                    "threads": 0,
                    "minimum_report_level": "warning",
                    "minimum_version": "1.45.0"
                }
            }
        }
    }
}
```

`tools.options.mago.threads: 0` shares Catraca's worker budget automatically. With the default four gate workers, each Mago process uses one thread to avoid CPU oversubscription. Set a positive value up to 128 to override it. `minimum_report_level` accepts `help`, `note`, `warning`, or `error`.

The Mago mappings stay separate: formatter findings update `results.style`, analyzer findings update `results.static_analysis`, and linter findings contribute to `results.performance`. Mago does not replace PHPCPD duplication percentages or PHP Metrics complexity values.

### `catraca check` — Run quality gates

Runs all 8 gates and compares against baseline. If `catraca_baseline.json` doesn't exist, it is created automatically.

```bash
# Human-readable (default)
vendor/bin/catraca check

# Plain text (no colors)
vendor/bin/catraca check --plain

# JSON output for AI agents / CI
vendor/bin/catraca check --format=json
# or vendor/bin/catraca check --format=json-pretty

# GitHub Actions annotations
vendor/bin/catraca check --format=github

# Specify project path
vendor/bin/catraca check --path=/path/to/project

# Auto-fix issues if any gate fails, then verify
vendor/bin/catraca check --fix
```

In an interactive terminal, human output is a live Symfony Console table. Queued gates wait for a worker, active gates display an animated spinner, and each row changes immediately to `PASS`, `FAIL`, `WARN`, or `SKIP` with its measured description. Plain, JSON, and GitHub formats remain non-interactive.

> **AI Agent Detection:** When Catraca detects it is running inside an AI agent (Cursor, Claude Code, OpenCode, etc.), it automatically switches to `--format=json` for structured output. You can still override this by explicitly passing `--format`.

### `catraca fix` — Auto-fix issues

Runs auto-fixers for code style, performance, and autoload optimization.

```bash
vendor/bin/catraca fix

# Specify project path
vendor/bin/catraca fix --path=/path/to/project

# Skip the automatic check after fixing
vendor/bin/catraca fix --no-check
```

What it fixes:

| Fixer | Tool | What it does |
|-------|------|--------------|
| Condition Order | Built-in | Swaps expensive conditions to come after cheaper ones in `&&` / `||` expressions |
| Code Style | `pint` or `php-cs-fixer` | Fixes all code style violations |
| Performance | `php-cs-fixer` | Adds missing imports, removes unused imports, cleans FQCNs, optimizes native calls and more |
| Autoload | `composer` | Runs `composer dump-autoload -o` if not optimized |

### Exit Codes

| Code | Meaning |
|------|---------|
| 0 | All gates passed |
| 1 | One or more gates failed |

## Output Formats

### Human (default)

Terminal-friendly output with ANSI colors:

```
  ┌──────────────────────────────────────────────────┐
  │ CATRACA — PHP Quality Gate Report                │
  └──────────────────────────────────────────────────┘
  ────────────────────────────────────────────────────────────
  ✔ Security Audit          PASS     0 total advisories, 0 critical/high
  ✔ Code Style              PASS     0 violations (baseline: 0)
  🚫 Static Analysis         FAIL     3 errors (baseline: 0)
  ✔ Test Coverage           PASS     85.00% (baseline: 85.00%)
  🚫 Duplication             FAIL     5.22% (baseline: 0.00%, 2 clones)
  ✔ File Size               PASS     0 files exceed 1000 lines
  ✔ Cyclomatic Complexity   PASS     max CCN 8, 0 violations (>50), 1 warnings (>20)
  ✔ Performance             PASS     No performance improvements needed
  ────────────────────────────────────────────────────────────
  RESULT: FAIL — 6/8 gates passed

  ┌──────────────────────────────────┐
  │ Required Actions                 │
  └──────────────────────────────────┘
  [1] FIX SA — Fix 3 PHPStan errors
      → app/Service.php:42
      → app/Repository.php:15
      → app/Controller.php:88
  [2] REFACTOR DUP — Duplication increased from 0.00% to 5.20%
      → src/A.php:10-50 <-> src/B.php:100-140 (40L)
```

### JSON (for AI agents)

Use `catraca check --format=json` to get structured JSON output for AI agents.
If you want it formatted, use `catraca check --format=json-pretty`. Note: Consumes more AI agent tokens.

Catraca auto-detects AI agents (Cursor, Claude Code, OpenCode, Gemini CLI, Codex, Augment, and others) and automatically uses JSON output — no flag needed.

```json
{
  "schema": "catraca/v2",
  "result": "fail",
  "timestamp": "2025-05-08T10:30:00+00:00",
  "summary": {
    "total": 7,
    "passed": 5,
    "failed": 2,
    "skipped": 0
  },
  "gates": [
    {
      "name": "security",
      "label": "Security Audit",
      "status": "pass",
      "severity": "block",
      "message": "0 total advisories, 0 critical/high",
      "baseline": { "advisories": 0 },
      "current": { "advisories": 0, "critical": 0 }
    }
  ],
  "actions": [
    {
      "type": "FIX SA",
      "priority": 0,
      "message": "Fix 3 PHPStan errors",
      "files": ["app/Service.php:42", "app/Repository.php:15"]
    }
  ]
}
```

### GitHub Actions

Uses `::error::`, `::warning::`, and `::group::` annotations for native GitHub integration.

### Security Gate

The Security gate runs `composer audit` (always) plus 14 source-code checks (all enabled by default):

| Rule | What it detects |
  |------|-----------------|
| `hardcoded_secrets` | API keys, tokens, private keys, and other credentials in source code |
| `sql_injection` | Raw SQL with interpolated variables (`DB::select("...$var")`, `whereRaw`) |
| `command_injection` | `exec`/`shell_exec`/`system`/`passthru` with unsanitized variables |
| `csrf_protection` | Missing `@csrf` in Laravel forms with POST/PUT/DELETE methods |
| `path_traversal` | `file_get_contents`, `Storage::`, `include` with user-controlled paths |
| `insecure_deserialization` | `unserialize()` with dynamic input or `base64_decode` chains |
| `ssrf` | `Http::`, Guzzle, `curl_setopt(CURLOPT_URL)` with user-controlled URLs |
| `tls_verification` | `withoutVerifying()`, `'verify' => false`, disabled `CURLOPT_SSL_VERIFYPEER` |
| `insecure_rng` | `rand()`/`mt_rand()`/`uniqid()` used for tokens/secrets (should use `random_bytes`) |
| `gitignore_sensitive` | Missing `.env`, `*.key`, `*.pem` entries in `.gitignore` |
| `package_freshness` | Composer packages released less than 3 days ago (untested) |
| `weak_cryptography` | `mcrypt_*`, ECB mode, DES/3DES/RC4, `md5`/`sha1` in security contexts |
| `cors_config` | `Access-Control-Allow-Origin: *` with credentials in Laravel CORS config |
| `npm_audit` | Known vulnerabilities in npm packages (if `package-lock.json` exists) |

All rules are configurable in `catraca_baseline.json` under `security.rules`. Set any rule to `false` to disable it:

```json
{
  "security": {
    "advisories": 0,
    "rules": {
      "hardcoded_secrets": true,
      "sql_injection": true,
      "command_injection": true,
      "csrf_protection": false,
      "path_traversal": true,
      "insecure_deserialization": true,
      "ssrf": true,
      "tls_verification": true,
      "insecure_rng": true,
      "gitignore_sensitive": true,
      "package_freshness": true,
      "weak_cryptography": true,
      "cors_config": true,
      "npm_audit": false
    }
  }
}
```

CSRF and CORS checks only apply to Laravel projects — they are **skipped** (not failed) when no Laravel directory structure is detected.

## Performance Gate

The Performance gate runs `php-cs-fixer` with configurable rules (all enabled by default):

| Rule                             | What it detects                                                         |
|----------------------------------|-------------------------------------------------------------------------|
| `global_namespace_import`        | Missing `use class/const` statements                                    |
| `no_unused_imports`              | Dead imports that slow parsing                                          |
| `fully_qualified_strict_types`   | `\Foo\Bar` when `use Foo\Bar` already exists                            |
| `lambda_not_used_import`         | Closures importing variables they don't use                             |
| `native_function_invocation`     | Native function calls without `\` prefix optimization                   |
| `no_redundant_readonly_property` | Redundant readonly property declarations                                |
| `static_lambda`                  | Lambdas not using `$this` that should be `static`                       |
| `array_push`                     | `array_push()` calls — use `$arr[] =` instead                           |
| `ereg_to_preg`                   | Deprecated `ereg` function calls                                        |
| `modernize_strpos`               | `strpos()` calls — use `str_contains`/`str_starts_with`/`str_ends_with` |
| `pow_to_exponentiation`          | `pow()` calls — use `**` operator instead                               |
| `random_api_migration`           | `rand()`/`mt_rand()` calls — use `random_int()` instead                 |
| `set_type_to_cast`               | `settype()` calls — use type casting instead                            |
| `autoload_optimization`          | Missing `composer dump-autoload -o`                                     |
| `condition_order`                | Expensive conditions placed before cheaper ones in `&&` / `||` (fixer is **experimental**) |

All rules are configurable in `catraca_baseline.json` under `performance.rules`. Set any rule to `false` to disable it, or `true` to enable it.

The `condition_order` check is **enabled by default** — it detects expensive conditions placed before cheaper ones. However, the auto-fix is **disabled by default** and marked as **experimental** because it modifies source code using AST transformations. Review all changes before committing. To enable automatic fixing, set `performance.fixers.condition_order` to `true`:

```json
{
    "performance": {
        "rules": {
            "condition_order": true
        },
        "fixers": {
            "condition_order": true
        }
    }
}
```

### PHPStan Configuration

If your project has a `phpstan.neon`, `phpstan.neon.dist`, or `phpstan.dist.neon`, Catraca uses it as-is. If no config file exists, it defaults to **level 5**.

## CI/CD Integration

### GitHub Actions

```yaml
# .github/workflows/catraca.yml
name: Catraca Quality Gate

on:
  pull_request:
    branches: [main]

jobs:
  quality-gate:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          coverage: pcov
          tools: composer, phpstan, pint, phpmetrics

      - run: composer install --no-interaction --prefer-dist
      - run: vendor/bin/catraca init --plain
        continue-on-error: true
      - run: vendor/bin/catraca check --format=github --plain
```

### GitLab CI

```yaml
# .gitlab-ci.yml
stages:
  - test

catraca:
  stage: test
  image: php:8.3-cli
  cache:
    key: ${CI_COMMIT_REF_SLUG}
    paths:
      - vendor/
  before_script:
    - apt-get update -qq && apt-get install -yqq unzip
    - curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
    - composer install --no-interaction --prefer-dist
  script:
    - vendor/bin/catraca init --plain || true
    - vendor/bin/catraca check --plain
```

### Forgejo Actions

Uses the same `--format=github` output — Forgejo Runner supports GitHub Actions workflow commands (`::error::`, `::warning::`, `::group::`).

```yaml
# .forgejo/workflows/catraca.yml
name: Catraca Quality Gate

on:
  pull_request:
    branches: [main]

jobs:
  quality-gate:
    runs-on: docker
    container:
      image: php:8.3-cli
    steps:
      - uses: https://code.forgejo.org/actions/checkout@v4

      - name: Install Composer
        run: |
          apt-get update -qq && apt-get install -yqq unzip
          curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

      - name: Install Dependencies
        run: composer install --no-interaction --prefer-dist

      - name: Init Baseline
        run: vendor/bin/catraca init --plain
        continue-on-error: true

      - name: Run Quality Gate
        run: vendor/bin/catraca check --format=github --plain
```

> **Note:** Adjust `runs-on` to match your runner's labels (e.g., `docker`, `ubuntu-latest`, `self-hosted`).

## GrumPHP Integration

Create a custom task in your project:

```php
// app/GrumPHP/CatracaTask.php
use GrumPHP\Runner\TaskResult;
use GrumPHP\Task\AbstractExternalTask;
use GrumPHP\Task\Config\EmptyTaskConfig;
use GrumPHP\Task\Config\TaskConfigInterface;

class CatracaTask extends AbstractExternalTask
{
    public function getConfig(): TaskConfigInterface
    {
        return new EmptyTaskConfig;
    }

    public function run(): TaskResult
    {
        $process = $this->processBuilder->build(['vendor/bin/catraca', 'check', '--plain']);
        $process->run();

        if (!$process->isSuccessful()) {
            return TaskResult::createFailed($this, $this->getContext(), [
                $process->getOutput(),
            ]);
        }

        return TaskResult::createPassed($this, $this->getContext());
    }
}
```

Register it in `grumphp.yml`:

```yaml
# grumphp.yml
grumphp:
  tasks:
    catraca: ~

services:
  CatracaTask:
    class: App\GrumPHP\CatracaTask
    arguments:
      - '@process_builder'
      - '@formatter.raw_process'
    tags:
      - { name: grumphp.task, task: catraca }
```

## Programmatic Usage

```php
use B7S\Catraca\Catraca;
use B7S\Catraca\Output\JsonFormatter;
use B7S\Catraca\Output\HumanFormatter;

$catraca = new Catraca('/path/to/project');
$result = $catraca->check();

if ($result->isPass()) {
    echo "All quality gates passed!\n";
} else {
    foreach ($result->getActions() as $action) {
        echo sprintf("[%s] %s\n", $action->type->value, $action->message);
    }
}

// Get structured JSON for AI agents
echo json_encode($result->toArray(), JSON_PRETTY_PRINT);
```

## Tool Resolution

Each tool is resolved in this order:

1. **Local** — `vendor/bin/<tool>` (project-level)
2. **Global** — `<tool>` in `$PATH`
3. **Composer global** — `~/.composer/vendor/bin/<tool>`
4. **Skip** — gate is skipped if tool not found

## License

MIT

## Advanced workflows

### Ratchet policies

Every gate accepts a `mode` under `config.<gate>`:

- `no_regression` (default) compares the primary metric with the stored result.
- `absolute` uses the gate's fixed threshold.
- `informational` reports findings as warnings without blocking.

Unavailable dependencies and metrics are controlled centrally:

```json
{
    "config": {
        "policy": {
            "missing_tool": "skip",
            "unavailable_metric": "warn",
            "internal_error": "fail"
        },
        "process": {
            "timeout_seconds": 1200
        }
    }
}
```

Each policy accepts `fail`, `warn`, or `skip`. A gate can override the process timeout with `config.<gate>.timeout_seconds`.

### Source scope and pull requests

Source paths support directories and globs. Exclusions apply to every built-in source scanner:

```json
{
    "config": {
        "source_dirs": {
            "paths": ["src", "packages/*/src"],
            "exclude": ["vendor", ".git", "node_modules", "generated"]
        }
    }
}
```

For fast pull-request feedback, analyze the PHP files changed from a Git reference:

```bash
vendor/bin/catraca check --changed-from=origin/main
```

Run the complete check on the main branch or scheduled CI build.

### Profiles

Named profiles keep measured results isolated while inheriting the default configuration:

```bash
vendor/bin/catraca init --profile=api
vendor/bin/catraca check --profile=api
```

Profile overrides live under `profiles.<name>.config`; their metrics live under `profiles.<name>.results`.

### CI artifacts and history

```bash
vendor/bin/catraca check --format=sarif --output=catraca.sarif
vendor/bin/catraca check --format=junit --output=catraca.xml
vendor/bin/catraca check --format=json-pretty --output=catraca.json
vendor/bin/catraca check --save-run
```

Saved runs are separate from the baseline under `.catraca/runs`. Enable this permanently with `config.history.enabled` and control cleanup with `config.history.retention`.

### Diagnostics

```bash
vendor/bin/catraca doctor
vendor/bin/catraca config:validate
vendor/bin/catraca explain coverage
```

`doctor` reports tool availability, coverage drivers, parallel fallback, and resolved source paths. `explain` shows the effective mode, policies, configuration, and stored baseline for one gate.

Pressing Ctrl+C or receiving SIGTERM stops active workers, marks unfinished live-table rows as `CANCELLED`, and returns a failing exit code.
