# AGENTS.md — PHP CLI Architecture Best Practices

This document captures architectural patterns, best practices, and design decisions for building maintainable, testable, and well-organized PHP command-line applications. These principles apply to any PHP project using Symfony Console, Laravel Artisan, Tempest Console, or similar CLI frameworks.

## Table of Contents

1. [Command Architecture](#command-architecture)
2. [Service Extraction](#service-extraction)
3. [Result Objects](#result-objects)
4. [Output Formatting](#output-formatting)
5. [Dependency Injection](#dependency-injection)
6. [Auto-Discovery](#auto-discovery)
7. [Code Quality Principles](#code-quality-principles)
8. [Error Handling](#error-handling)
9. [Framework-Specific Notes](#framework-specific-notes)
10. [Checklist for New Commands](#checklist-for-new-commands)

---

## Command Architecture

### Thin Command Pattern
Commands must be **ultra-thin orchestrators**. They should:
- Accept input and resolve context (project root, arguments, options)
- Delegate all business logic to dedicated services
- Format and output results
- Return exit codes

**Maximum recommended command length**: 60 lines. If a command exceeds this, extract logic into a service.

**Before (bad — business logic inside command):**
```php
class ImportCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // 150+ lines of parsing, validation, database operations...
        $file = $input->getArgument('file');
        $handle = fopen($file, 'r');
        while ($row = fgetcsv($handle)) {
            // validate, transform, insert...
        }
        // ...
    }
}
```

**After (good — delegates to services):**
```php
class ImportCommand extends Command
{
    public function __construct(
        private readonly ImportService $importer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = $input->getArgument('file');
        $result = $this->importer->import($file);
        
        $this->formatResult($input, $output, $result);
        
        return $result->isSuccess() ? Command::SUCCESS : Command::FAILURE;
    }
}
```

### Shared Command Concerns
Extract cross-cutting concerns into a trait or base class:
- `--path` / `--format` / `--plain` option definitions
- Project root resolution
- Result formatting

Never duplicate option definitions or path resolution logic across commands.

---

## Service Extraction

### When to Extract a Service
Extract a service when you encounter any of these smells:
1. A method does not use `$this` (can be static / standalone)
2. The same logic exists in 2+ locations
3. A class exceeds 150 lines
4. A method has more than 3 levels of indentation
5. Mixed levels of abstraction in one method

### Service Categories

| Category | Responsibility | Examples |
|----------|---------------|----------|
| **Resolvers** | Locate and validate external resources | `ProjectResolver`, `ConfigResolver` |
| **Executors** | Execute a specific operation | `ImportService`, `ExportService` |
| **Formatters** | Render results to output formats | `HumanFormatter`, `JsonFormatter` |
| **Runners** | Execute external processes | `ProcessRunner` |
| **Validators** | Run a single validation check | `SchemaValidator`, `FileValidator` |

### Interface Segregation
Services that are swappable should implement an interface:

```php
interface TaskInterface
{
    public function getLabel(): string;
    public function run(Context $context): TaskResult;
}
```

This enables:
- Polymorphic iteration (`foreach ($tasks as $task)`)
- Easy testing with mocks
- Future extensibility

---

## Result Objects

### Always Return Structured Results
Never echo output directly from services. Return structured result objects that the command layer formats.

**Pattern:**
```php
class TaskResult
{
    private array $items = [];

    public function add(ItemResult $result): void { /* ... */ }
    public function getSuccessCount(): int { /* ... */ }
    public function getFailureCount(): int { /* ... */ }
    public function toArray(): array { /* ... */ }
}
```

**Benefits:**
- Multiple output formats (human, JSON, GitHub Actions) from the same result
- Testable assertions on result data
- Composable — results can be merged, filtered, transformed

### Mirror Existing Patterns
When creating a new result type, mirror the structure of existing result types in the project. Maintain consistency in:
- Count methods (`get*Count()`)
- Status methods (`isSuccess()`, `isPass()`)
- Serialization (`toArray()`)

---

## Output Formatting

### Separate Formatting from Business Logic
Formatters are pure functions that take a result object and return a string. They must not:
- Execute processes
- Read files
- Access the database
- Produce side effects

### Support All Output Formats
Every command that produces structured output should support:
- `human` — terminal-friendly with ANSI colors (default)
- `human` + `--plain` — no ANSI colors
- `json` — compact JSON for piping
- `json-pretty` — formatted JSON for debugging
- `github` — `::error::`, `::warning::`, `::group::` annotations (if applicable)

### Shared Formatting Utilities
Extract common formatting utilities into traits:

```php
trait BoxDrawer
{
    private function box(string $text): string { /* ... */ }
    private function divider(int $width = 60): string { /* ... */ }
}
```

---

## Dependency Injection

### Prefer Constructor Injection
Services should receive dependencies via constructor, not instantiate them inside methods.

**Good:**
```php
class ImportService
{
    public function __construct(
        private readonly CsvParser $parser,
        private readonly DatabaseConnection $db,
    ) {}
}
```

**Acceptable fallback:** Default constructor arguments for simple, stateless services:
```php
public function __construct(
    private readonly LoggerInterface $logger = new NullLogger,
) {}
```

This allows instantiation without a DI container while still supporting injection.

### Avoid Service Locator Pattern
Do not pass around a "bag of services" or a container. Pass only the specific dependencies needed.

---

## Auto-Discovery

### Command Auto-Discovery
The application entry point should auto-discover commands from a designated directory instead of manually registering them.

**Requirements for auto-discovered commands:**
1. Located in the commands directory (e.g., `src/Command/`)
2. Class name ends with `Command`
3. Extends the framework's base Command class
4. Not abstract
5. Has the framework's command attribute (e.g., `#[AsCommand]`)

**Benefits:**
- Adding a new command requires creating one file — no entry point changes
- Eliminates merge conflicts in the entry point
- Self-documenting: all commands live in one place

---

## Code Quality Principles

### DRY (Don't Repeat Yourself)
Before extracting, identify duplication via tools like `phpcpd`. Common duplications to watch for:
- Path resolution logic
- Source directory iteration
- Box/divider rendering in formatters
- Error message formatting

### SRP (Single Responsibility Principle)
Each class should have one reason to change:
- **Commands** change when CLI options change
- **Services** change when business logic changes
- **Formatters** change when output format changes
- **Validators** change when validation criteria change

### Consistent Naming
Follow these conventions:
| Pattern | Example |
|---------|---------|
| Commands | `*Command` suffix, in `Command\` namespace |
| Services | `*Service` suffix, in `Service\` namespace |
| Formatters | `*Formatter` suffix, in `Output\` namespace |
| Interfaces | `*Interface` suffix |
| Traits | Descriptive noun, no suffix |

---

## Error Handling

### Graceful Degradation
When a dependency is not available, skip the operation rather than fail:

```php
$tool = $resolver->resolve('optional-tool');
if ($tool === null) {
    return new TaskResult(
        label: $this->getLabel(),
        skipped: true,
        message: 'skipped (install optional-tool)',
    );
}
```

### Return Meaningful Exit Codes
| Code | Meaning |
|------|---------|
| `0` | Success / all operations passed |
| `1` | Failure / one or more operations failed |

### Validate Early
Resolve and validate inputs before doing any work:

```php
$projectRoot = $this->resolveProjectRoot($input, $output);
if ($projectRoot === null) {
    return Command::FAILURE;
}
```

---

## Framework-Specific Notes

### Symfony Console
- Use `#[AsCommand]` attributes for routing
- Register commands via `Application::add()` or auto-discovery
- Leverage `SymfonyStyle` for interactive I/O
- Use `InputOption` and `InputArgument` for type-safe input

### Laravel Artisan
- Place commands in `app/Console/Commands/`
- Use `php artisan make:command` scaffolding
- Register in `$commands` array within `Console\Kernel` (or auto-discover)
- Leverage Laravel's service container for dependency injection
- Use `$this->info()`, `$this->error()`, `$this->table()` helpers

### Tempest Console
- Use `#[ConsoleCommand]` attributes
- Leverage `HasConsole` trait for semantic output (`success()`, `error()`)
- Use middleware for cross-cutting concerns
- Prefer constructor injection via the framework's container

### Generic PHP (no framework)
- Use `symfony/console` as the de-facto standard
- Build a minimal `Application` bootstrap in `bin/` or `cli`
- Implement your own auto-discovery or manually register commands
- Use composer autoloading (`vendor/autoload.php`)

---

## Directory Structure Examples

### Standalone / Symfony Console Project
```
src/
├── Command/              # CLI commands (orchestrators only)
│   ├── ImportCommand.php
│   ├── ExportCommand.php
│   └── CommandDiscovery.php
├── Service/              # Business logic services
│   ├── ImportService.php
│   └── ExportService.php
├── Output/               # Result formatters
│   ├── HumanFormatter.php
│   ├── JsonFormatter.php
│   └── GithubFormatter.php
├── Result.php            # Aggregate results
├── ProjectResolver.php
└── ProcessRunner.php
```

### Laravel Project
```
app/
├── Console/
│   ├── Commands/         # Artisan commands
│   │   ├── ImportCommand.php
│   │   └── ExportCommand.php
│   └── Kernel.php        # Command registration & scheduling
├── Services/             # Business logic
│   ├── ImportService.php
│   └── ExportService.php
├── Formatters/           # Output formatters
│   ├── HumanFormatter.php
│   └── JsonFormatter.php
└── Resolvers/            # Path/config resolution
    └── ProjectResolver.php
```

### Package / Library Structure
```
src/
├── Command/
│   └── YourCommand.php
├── Service/
│   └── YourService.php
└── Output/
    └── YourFormatter.php
```

---

## Checklist for New Commands

When adding a new command, verify:

- [ ] Command class is in the designated commands directory and has the framework's command attribute
- [ ] Uses a shared trait or base class for standard options
- [ ] Does not exceed 60 lines of logic (extract services if needed)
- [ ] Delegates all business logic to services
- [ ] Returns structured result objects (not raw strings)
- [ ] Supports `--format` option (human, json, json-pretty, github if applicable)
- [ ] Supports `--plain` option (no ANSI colors)
- [ ] Validates inputs early and returns `Command::FAILURE` on invalid input
- [ ] Will be auto-discovered by the application entry point (no manual registration needed)
- [ ] Has corresponding formatter(s) if producing new output types

---

## File Organization

```
src/ (or app/)
├── Command/          # CLI commands (orchestrators only)
│   ├── ImportCommand.php
│   ├── ExportCommand.php
│   └── CommandDiscovery.php
├── Service/          # Business logic services
│   ├── ServiceInterface.php
│   ├── ImportService.php
│   └── ExportService.php
├── Output/           # Result formatters
│   ├── BoxDrawer.php
│   ├── HumanFormatter.php
│   ├── JsonFormatter.php
│   └── GithubFormatter.php
├── Result.php        # Aggregate results
├── ProjectResolver.php
└── ProcessRunner.php
```
