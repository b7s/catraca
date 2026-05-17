<?php

declare(strict_types=1);

namespace B7S\Catraca\Tests\Analyzer;

use B7S\Catraca\Analyzer\ConditionOrderAnalyzer;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class ConditionOrderAnalyzerTest extends TestCase
{
    private ConditionOrderAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new ConditionOrderAnalyzer;
    }

    public function test_detects_expensive_before_cheap_in_and(): void
    {
        $file = $this->createTempFile('
            <?php
            class Test {
                public function run() {
                    if ($this->expensive() && $this->cheap) {}
                }
            }
        ');

        $violations = $this->analyzer->analyze([$file]);

        self::assertCount(1, $violations);
        self::assertSame($file, $violations[0]['file']);
        self::assertStringContainsString('&&', $violations[0]['message']);
        self::assertStringContainsString('left side costs 3', $violations[0]['message']);
        self::assertStringContainsString('right side costs 1', $violations[0]['message']);

        unlink($file);
    }

    public function test_does_not_flag_cheap_before_expensive_in_and(): void
    {
        $file = $this->createTempFile('
            <?php
            class Test {
                public function run() {
                    if ($this->cheap && $this->expensive()) {}
                }
            }
        ');

        $violations = $this->analyzer->analyze([$file]);

        self::assertCount(0, $violations);

        unlink($file);
    }

    public function test_detects_expensive_before_cheap_in_or(): void
    {
        $file = $this->createTempFile('
            <?php
            class Test {
                public function run() {
                    if ($this->expensive() || $this->cheap) {}
                }
            }
        ');

        $violations = $this->analyzer->analyze([$file]);

        self::assertCount(1, $violations);
        self::assertStringContainsString('||', $violations[0]['message']);

        unlink($file);
    }

    public function test_does_not_flag_same_cost_operands(): void
    {
        $file = $this->createTempFile('
            <?php
            class Test {
                public function run() {
                    if ($a && $b) {}
                    if ($this->foo() && $this->bar()) {}
                }
            }
        ');

        $violations = $this->analyzer->analyze([$file]);

        self::assertCount(0, $violations);

        unlink($file);
    }

    public function test_detects_method_call_before_variable(): void
    {
        $file = $this->createTempFile('
            <?php
            class Test {
                public function run() {
                    if ($this->method() && $var) {}
                }
            }
        ');

        $violations = $this->analyzer->analyze([$file]);

        self::assertCount(1, $violations);
        self::assertStringContainsString('left side costs 3', $violations[0]['message']);
        self::assertStringContainsString('right side costs 0', $violations[0]['message']);

        unlink($file);
    }

    public function test_does_not_flag_variable_before_method_call(): void
    {
        $file = $this->createTempFile('
            <?php
            class Test {
                public function run() {
                    if ($var && $this->method()) {}
                }
            }
        ');

        $violations = $this->analyzer->analyze([$file]);

        self::assertCount(0, $violations);

        unlink($file);
    }

    public function test_detects_new_before_variable(): void
    {
        $file = $this->createTempFile('
            <?php
            class Test {
                public function run() {
                    if (new self() && $var) {}
                }
            }
        ');

        $violations = $this->analyzer->analyze([$file]);

        self::assertCount(1, $violations);

        unlink($file);
    }

    public function test_detects_function_call_before_isset(): void
    {
        $file = $this->createTempFile('
            <?php
            class Test {
                public function run() {
                    if (someFunc() && isset($x)) {}
                }
            }
        ');

        $violations = $this->analyzer->analyze([$file]);

        self::assertCount(1, $violations);
        self::assertStringContainsString('left side costs 3', $violations[0]['message']);
        self::assertStringContainsString('right side costs 0', $violations[0]['message']);

        unlink($file);
    }

    public function test_does_not_flag_isset_before_function_call(): void
    {
        $file = $this->createTempFile('
            <?php
            class Test {
                public function run() {
                    if (isset($x) && someFunc()) {}
                }
            }
        ');

        $violations = $this->analyzer->analyze([$file]);

        self::assertCount(0, $violations);

        unlink($file);
    }

    public function test_detects_function_call_before_empty(): void
    {
        $file = $this->createTempFile('
            <?php
            class Test {
                public function run() {
                    if (someFunc() && empty($x)) {}
                }
            }
        ');

        $violations = $this->analyzer->analyze([$file]);

        self::assertCount(1, $violations);

        unlink($file);
    }

    public function test_handles_nested_boolean_expressions(): void
    {
        $file = $this->createTempFile('
            <?php
            class Test {
                public function run() {
                    if ($this->expensive() && $a && $this->medium()) {}
                }
            }
        ');

        $violations = $this->analyzer->analyze([$file]);

        self::assertGreaterThanOrEqual(1, count($violations));

        unlink($file);
    }

    public function test_handles_multiple_files(): void
    {
        $file1 = $this->createTempFile('
            <?php
            if ($this->expensive() && $a) {}
        ');

        $file2 = $this->createTempFile('
            <?php
            if ($b && $this->cheap) {}
        ');

        $violations = $this->analyzer->analyze([$file1, $file2]);

        self::assertCount(1, $violations);
        self::assertSame($file1, $violations[0]['file']);

        unlink($file1);
        unlink($file2);
    }

    public function test_skips_non_php_files(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'catraca_test_').'.txt';
        file_put_contents($file, 'not php');

        $violations = $this->analyzer->analyze([$file]);

        self::assertCount(0, $violations);

        unlink($file);
    }

    public function test_skips_invalid_php_files(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'catraca_test_').'.php';
        file_put_contents($file, '<?php this is not valid php {{');

        $violations = $this->analyzer->analyze([$file]);

        self::assertCount(0, $violations);

        unlink($file);
    }

    private function createTempFile(string $content): string
    {
        $file = tempnam(sys_get_temp_dir(), 'catraca_test_').'.php';
        file_put_contents($file, $content);

        return $file;
    }
}
