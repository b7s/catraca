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

# Duplication detection (requires Node.js)
npm install --save-dev jscpd
# or
composer require --dev sebastian/phpcpd

# Cyclomatic complexity
composer require --dev phpmetrics/phpmetrics
```

Any tool not installed is **skipped** (not failed). Security audit uses `composer audit` (built-in).

## Usage

### `catraca init` — Initialize baseline

Creates `baseline.json` in your project root with default thresholds:

```bash
vendor/bin/catraca init
```

Default baseline:

| Gate | Default |
|------|---------|
| Code Style | 0 violations |
| Static Analysis | 0 errors (level 5 if no `phpstan.neon`) |
| Test Coverage | 85% minimum |
| Duplication | 2% maximum |
| File Size | 1000 lines maximum per file |
| Complexity | Block at CCN 50, warn at CCN 20 |

You can edit `baseline.json` directly to adjust thresholds.

### `catraca check` — Run quality gates

Runs all 7 gates and compares against baseline. If `baseline.json` doesn't exist, it is created automatically.

```bash
# Human-readable (default)
vendor/bin/catraca check

# Plain text (no colors)
vendor/bin/catraca check --plain

# JSON output for AI agents / CI
vendor/bin/catraca check --format=json

# GitHub Actions annotations
vendor/bin/catraca check --format=github

# Specify project path
vendor/bin/catraca check --path=/path/to/project
```

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
  ✘ Duplication             FAIL     5.20% (baseline: 2.00%, 2 clones)
  ✔ File Size               PASS     0 files exceed 1000 lines
  ✔ Cyclomatic Complexity   PASS     max CCN 8, 0 violations (>50), 1 warnings (>20)
  ────────────────────────────────────────────────────────────
  RESULT: FAIL — 5/7 gates passed

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
| 5 | Duplication | `jscpd` or `phpcpd` | 2% maximum |
| 6 | File Size | Built-in | 1000 lines per file |
| 7 | Cyclomatic Complexity | `phpmetrics` | Block at 50, warn at 20 |

### PHPStan Configuration

If your project has a `phpstan.neon`, `phpstan.neon.dist`, or `phpstan.dist.neon`, Catraca uses it as-is. If no config file exists, it defaults to **level 5**.

## GitHub Actions

```yaml
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
      - run: composer require --dev b7s/catraca
      - run: vendor/bin/catraca init --plain
        continue-on-error: true
      - run: vendor/bin/catraca check --format=github --plain
```

## GrumPHP Integration

```php
use GrumPHP\Runner\TaskResult;
use GrumPHP\Task\AbstractExternalTask;

class CatracaTask extends AbstractExternalTask
{
    public function run(): TaskResult
    {
        $process = $this->processBuilder->build(['vendor/bin/catraca', 'check', '--format=json']);
        $process->run();

        if (!$process->isSuccessful()) {
            return TaskResult::createFailed($this, $this->getContext(), $process->getOutput());
        }

        return TaskResult::createPassed($this, $this->getContext());
    }
}
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
