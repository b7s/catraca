<?php

declare(strict_types=1);

namespace B7S\Catraca\Fixer;

use B7S\Catraca\Analyzer\ConditionOrderAnalyzer;
use B7S\Catraca\Baseline;
use B7S\Catraca\SourcePathResolver;
use B7S\Catraca\ToolResolver;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Throwable;

use function array_merge;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function sprintf;

readonly class ConditionOrderFixer implements FixerInterface
{
    public function __construct(
        private SourcePathResolver $pathResolver = new SourcePathResolver,
    ) {}

    public function getLabel(): string
    {
        return 'Condition Order';
    }

    public function fix(Baseline $baseline, ToolResolver $resolver): FixerResult
    {
        $enabledRules = $baseline->get('performance', 'rules', []);
        if (! is_array($enabledRules) || ! ($enabledRules['condition_order'] ?? true)) {
            return new FixerResult(
                label: $this->getLabel(),
                skipped: true,
                message: 'condition_order rule disabled',
            );
        }

        $paths = $this->pathResolver->resolve($resolver->getProjectRoot(), $baseline->getSourceDirs());
        $analyzer = new ConditionOrderAnalyzer;
        $violations = $analyzer->analyze($paths);

        if ($violations === []) {
            return new FixerResult(
                label: $this->getLabel(),
                skipped: true,
                message: 'no condition order issues found',
            );
        }

        $filesByPath = [];
        foreach ($violations as $v) {
            $filesByPath[$v['file']][] = $v;
        }

        $fixedCount = 0;
        $parser = (new ParserFactory)->createForHostVersion();
        $nodeFinder = new NodeFinder;
        $printer = new Standard;

        foreach ($filesByPath as $file => $fileViolations) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            try {
                $ast = $parser->parse($content);
            } catch (Throwable) {
                continue;
            }

            if ($ast === null) {
                continue;
            }

            /** @var array<int, BooleanAnd|BooleanOr> $booleanOps */
            $booleanOps = array_merge(
                $nodeFinder->findInstanceOf($ast, BooleanAnd::class),
                $nodeFinder->findInstanceOf($ast, BooleanOr::class),
            );

            $swapped = false;
            foreach ($booleanOps as $op) {
                if ($analyzer->hasSideEffects($op)) {
                    continue;
                }

                $leftCost = $analyzer->computeCost($op->left);
                $rightCost = $analyzer->computeCost($op->right);

                if ($leftCost > $rightCost) {
                    $tmp = $op->left;
                    $op->left = $op->right;
                    $op->right = $tmp;
                    $swapped = true;
                }
            }

            if (! $swapped) {
                continue;
            }

            $newContent = $printer->prettyPrintFile($ast);
            if ($newContent !== $content) {
                file_put_contents($file, $newContent);
                $fixedCount++;
            }
        }

        if ($fixedCount === 0) {
            return new FixerResult(
                label: $this->getLabel(),
                skipped: true,
                message: 'no fixable condition order issues',
            );
        }

        return new FixerResult(
            label: $this->getLabel(),
            fixed: true,
            message: sprintf('fixed condition order in %d file(s)', $fixedCount),
        );
    }
}
