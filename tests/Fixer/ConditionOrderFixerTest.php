<?php

declare(strict_types=1);

namespace B7S\Catraca\Tests\Fixer;

use B7S\Catraca\Baseline;
use B7S\Catraca\Fixer\ConditionOrderFixer;
use B7S\Catraca\ToolResolver;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function mkdir;
use function sys_get_temp_dir;
use function unlink;

final class ConditionOrderFixerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/catraca_fixer_test_'.str_replace('.', '_', uniqid('', true));
        mkdir($this->tmpDir.'/src', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rmDir($this->tmpDir);
    }

    public function test_fixes_expensive_before_cheap(): void
    {
        $file = $this->tmpDir.'/src/Test.php';
        file_put_contents($file, '<?php
class Test {
    public function run() {
        if ($this->expensive() && $this->cheap) {}
    }
}
');

        $baseline = $this->createBaseline();
        $resolver = new ToolResolver($this->tmpDir);
        $fixer = new ConditionOrderFixer;

        $result = $fixer->fix($baseline, $resolver);

        self::assertTrue($result->fixed);
        self::assertStringContainsString('fixed condition order in 1 file', $result->message);

        $content = file_get_contents($file);
        self::assertStringContainsString('if ($this->cheap && $this->expensive())', $content);

        unlink($file);
    }

    public function test_skips_when_check_disabled(): void
    {
        $file = $this->tmpDir.'/src/Test.php';
        file_put_contents($file, '<?php
class Test {
    public function run() {
        if ($this->expensive() && $this->cheap) {}
    }
}
');

        $baseline = $this->createBaseline(['condition_order' => false]);
        $resolver = new ToolResolver($this->tmpDir);
        $fixer = new ConditionOrderFixer;

        $result = $fixer->fix($baseline, $resolver);

        self::assertTrue($result->skipped);
        self::assertStringContainsString('check disabled', $result->message);

        unlink($file);
    }

    public function test_skips_when_fix_disabled(): void
    {
        $file = $this->tmpDir.'/src/Test.php';
        file_put_contents($file, '<?php
class Test {
    public function run() {
        if ($this->expensive() && $this->cheap) {}
    }
}
');

        $baseline = $this->createBaseline(['condition_order' => true], ['condition_order' => false]);
        $resolver = new ToolResolver($this->tmpDir);
        $fixer = new ConditionOrderFixer;

        $result = $fixer->fix($baseline, $resolver);

        self::assertTrue($result->skipped);
        self::assertStringContainsString('fix disabled', $result->message);

        unlink($file);
    }

    public function test_skips_when_no_issues(): void
    {
        $file = $this->tmpDir.'/src/Test.php';
        file_put_contents($file, '<?php
class Test {
    public function run() {
        if ($this->cheap && $this->expensive()) {}
    }
}
');

        $baseline = $this->createBaseline();
        $resolver = new ToolResolver($this->tmpDir);
        $fixer = new ConditionOrderFixer;

        $result = $fixer->fix($baseline, $resolver);

        self::assertTrue($result->skipped);
        self::assertStringContainsString('no condition order issues', $result->message);

        unlink($file);
    }

    public function test_does_not_swap_side_effect_expressions(): void
    {
        $file = $this->tmpDir.'/src/Test.php';
        file_put_contents($file, '<?php
class Test {
    public function run() {
        if (mkdir("foo") && $this->cheap) {}
    }
}
');

        $baseline = $this->createBaseline();
        $resolver = new ToolResolver($this->tmpDir);
        $fixer = new ConditionOrderFixer;

        $result = $fixer->fix($baseline, $resolver);

        self::assertTrue($result->skipped);
        self::assertStringContainsString('no condition order issues', $result->message);

        $content = file_get_contents($file);
        self::assertStringContainsString('if (mkdir("foo") && $this->cheap)', $content);

        unlink($file);
    }

    public function test_fixes_or_expression(): void
    {
        $file = $this->tmpDir.'/src/Test.php';
        file_put_contents($file, '<?php
class Test {
    public function run() {
        if ($this->expensive() || $this->cheap) {}
    }
}
');

        $baseline = $this->createBaseline();
        $resolver = new ToolResolver($this->tmpDir);
        $fixer = new ConditionOrderFixer;

        $result = $fixer->fix($baseline, $resolver);

        self::assertTrue($result->fixed);

        $content = file_get_contents($file);
        self::assertStringContainsString('if ($this->cheap || $this->expensive())', $content);

        unlink($file);
    }

    public function test_preserves_formatting_blank_lines_and_spacing(): void
    {
        $original = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\nuse App\Service;\n\nclass Test {\n    public function run() {\n        // important comment\n        if (\$this->expensive() && \$x > 0) {\n            return true;\n        }\n\n        return false;\n    }\n}\n";
        $file = $this->tmpDir.'/src/Test.php';
        file_put_contents($file, $original);

        $baseline = $this->createBaseline();
        $resolver = new ToolResolver($this->tmpDir);
        $fixer = new ConditionOrderFixer;

        $result = $fixer->fix($baseline, $resolver);

        self::assertTrue($result->fixed);

        $content = file_get_contents($file);
        self::assertStringContainsString('// important comment', $content);
        self::assertStringContainsString('if ($x > 0 && $this->expensive())', $content);
        self::assertStringContainsString('declare(strict_types=1);', $content);
        self::assertStringContainsString('return false;', $content);

        unlink($file);
    }

    public function test_adds_parens_around_coalesce_when_swapped(): void
    {
        $original = "<?php\n\n\$file->getExtension() === 'php' && (tryIt()->data ?? false);\n";
        $file = $this->tmpDir.'/src/Test.php';
        file_put_contents($file, $original);

        $baseline = $this->createBaseline();
        $resolver = new ToolResolver($this->tmpDir);
        $fixer = new ConditionOrderFixer;

        $result = $fixer->fix($baseline, $resolver);

        self::assertTrue($result->fixed);

        $content = file_get_contents($file);
        self::assertStringContainsString('(tryIt()->data ?? false) && ($file->getExtension() === \'php\')', $content);

        unlink($file);
    }

    public function test_adds_parens_around_ternary_when_swapped(): void
    {
        $original = "<?php\n\n\$this->expensive() && (\$x ? \$y : \$z);\n";
        $file = $this->tmpDir.'/src/Test.php';
        file_put_contents($file, $original);

        $baseline = $this->createBaseline();
        $resolver = new ToolResolver($this->tmpDir);
        $fixer = new ConditionOrderFixer;

        $result = $fixer->fix($baseline, $resolver);

        self::assertTrue($result->fixed);

        $content = file_get_contents($file);
        self::assertStringContainsString('($x ? $y : $z) && ($this->expensive())', $content);

        unlink($file);
    }

    private function createBaseline(array $rules = ['condition_order' => true], array $fixers = ['condition_order' => true]): Baseline
    {
        $baseline = new Baseline($this->tmpDir);
        $baseline->write([
            'performance' => ['violations' => 0, 'rules' => $rules, 'fixers' => $fixers],
        ]);

        return $baseline;
    }

    private function rmDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }

        rmdir($dir);
    }
}
