<div align="center">
    <img src="logo.webp" width="256" alt="Catraca Logo">
</div>

# Catraca

PHP Quality Guardian that enforces the **Catraca (ratchet) principle**: quality metrics can only improve or stay the same, never regress.

> **Catraca** (Portuguese for "turnstile" / "ratchet") — like a turnstile at a subway station, quality can only move forward.

## Install

```bash
composer require --dev b7s/catraca
```

## Dependencies

Catraca wraps your existing PHP quality tools. Install the ones you need:

```bash
# Code style
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

Any tool not installed is **skipped** (not failed). Security audit uses `composer audit` (built-in).

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
| Code Style | 0 violations |
| Static Analysis | 0 errors (level 5 if no `phpstan.neon`) |
| Test Coverage | 85% minimum |
| Duplication | 2% maximum, min 3 lines, min 30 tokens |
| File Size | 1000 lines maximum per file |
| Complexity | Block at CCN 50, warn at CCN 20 |
| Performance | 0 violations |

You can edit `catraca_baseline.json` directly to adjust thresholds.

### Configuration — `catraca_baseline.json`

```json
{
    "source_dirs": {
        "paths": ["src", "app", "lib"]
    },
    "security": {
        "advisories": 0
    },
    "style": {
        "violations": 0
    },
    "static_analysis": {
        "errors": 0
    },
    "coverage": {
        "percentage": 85.0
    },
    "duplication": {
        "percentage": 2.0,
        "min_lines": 3,
        "min_tokens": 30
    },
    "file_size": {
        "max_lines": 1000
    },
    "complexity": {
        "max_ccn": 0
    },
    "performance": {
        "violations": 0,
        "rules": {
          "global_namespace_import": true,
          "no_unused_imports": true,
          "fully_qualified_strict_types": true,
          "lambda_not_used_import": true,
          "native_function_invocation": true,
          "no_redundant_readonly_property": true,
          "static_lambda": true,
          "array_push": true,
          "ereg_to_preg": true,
          "modernize_strpos": true,
          "pow_to_exponentiation": true,
          "random_api_migration": true,
          "set_type_to_cast": true,
          "autoload_optimization": true,
          "condition_order": true
        },
        "fixers": {
            "condition_order": false
        }
    }
}
```

**`source_dirs.paths`** — which directories Catraca scans for PHP files. Only directories that exist on disk are used. If none of the configured directories exist, the project root is used as fallback. Defaults to `["src", "app", "lib"]`.

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
  ✘ Static Analysis         FAIL     3 errors (baseline: 0)
  ✔ Test Coverage           PASS     85.00% (baseline: 85.00%)
  ✘ Duplication             FAIL     5.22% (baseline: 2.00%, 2 clones)
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
  [2] REFACTOR DUP — Duplication increased from 2.00% to 5.20%
      → src/A.php:10-50 <-> src/B.php:100-140 (40L)
```

### JSON (for AI agents)

Use `catraca check --format=json` to get structured JSON output for AI agents.
If you want it formatted, use `catraca check --format=json-pretty`. Note: Consumes more AI agent tokens.

Catraca auto-detects AI agents (Cursor, Claude Code, OpenCode, Gemini CLI, Codex, Augment, and others) and automatically uses JSON output — no flag needed.

```json
{
  "schema": "catraca/v1",
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

## Quality Gates

Gates run in order. A failure blocks the PR.

| # | Gate | Tool | Default Threshold |
|---|------|------|-------------------|
| 1 | Security Audit | `composer audit` | 0 critical/high advisories |
| 2 | Code Style | `pint` or `php-cs-fixer` | 0 violations |
| 3 | Static Analysis | `phpstan` or `psalm` | 0 errors (level 5 if no config) |
| 4 | Test Coverage | `phpunit` or `pest` | 85% minimum |
| 5 | Duplication | `phpcpd` | 2% maximum |
| 6 | File Size | Built-in | 1000 lines per file |
| 7 | Cyclomatic Complexity | `phpmetrics` | Block at 50, warn at 20 |
| 8 | Performance | `php-cs-fixer` | 0 violations |

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
