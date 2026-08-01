<?php

declare(strict_types=1);

namespace B7S\Catraca\Fixer;

use B7S\Catraca\Analyzer\ConditionOrderAnalyzer;
use B7S\Catraca\Baseline;
use B7S\Catraca\SourcePathResolver;
use B7S\Catraca\ToolResolver;
use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
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
        private SourcePathResolver $pathResolver = new SourcePathResolver(),
    ) {}

    public function getLabel(): string
    {
        return 'Condition Order';
    }

    private function clearOrigNode(Node $node): void
    {
        $node->setAttribute('origNode', null);
        /** @var array<string, string> $subNodeNames */
        $subNodeNames = $node->getSubNodeNames();
        foreach ($subNodeNames as $name) {
            /** @var mixed $sub */
            $sub = $node->$name;
            if (is_array($sub)) {
                foreach ($sub as $item) {
                    if ($item instanceof Node) {
                        $this->clearOrigNode($item);
                    }
                }
            } elseif ($sub instanceof Node) {
                $this->clearOrigNode($sub);
            }
        }
    }

    public function fix(Baseline $baseline, ToolResolver $resolver): FixerResult
    {
        $enabledRules = $baseline->getConfig('performance', 'rules', []);
        if (!is_array($enabledRules) || !($enabledRules['condition_order'] ?? true)) {
            return new FixerResult(label: $this->getLabel(), skipped: true, message: 'condition_order check disabled');
        }

        $fixers = $baseline->getConfig('performance', 'fixers', []);
        if (!is_array($fixers) || !($fixers['condition_order'] ?? false)) {
            return new FixerResult(
                label: $this->getLabel(),
                skipped: true,
                message: 'condition_order fix disabled [experimental] — set performance.fixers.condition_order to true to enable',
            );
        }

        $paths = $this->pathResolver->resolve($resolver->getProjectRoot(), $baseline->getSourceDirs());
        $analyzer = new ConditionOrderAnalyzer();
        $violations = $analyzer->analyze($paths);

        if ($violations === []) {
            return new FixerResult(label: $this->getLabel(), skipped: true, message: 'no condition order issues found');
        }

        $filesByPath = [];
        foreach ($violations as $v) {
            $filesByPath[$v['file']][] = $v;
        }

        $fixedCount = 0;
        $parser = (new ParserFactory())->createForHostVersion();
        $nodeFinder = new NodeFinder();
        $printer = new Standard();

        foreach ($filesByPath as $file => $fileViolations) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            try {
                $origAst = $parser->parse($content);
            } catch (Throwable) {
                continue;
            }

            if ($origAst === null) {
                continue;
            }

            $origTokens = $parser->getTokens();

            $traverser = new NodeTraverser(new CloningVisitor());
            $ast = $traverser->traverse($origAst);

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
                    [$op->left, $op->right] = [$op->right, $op->left];
                    $this->clearOrigNode($op->left);
                    $this->clearOrigNode($op->right);
                    $swapped = true;
                }
            }

            if (!$swapped) {
                continue;
            }

            $newContent = $printer->printFormatPreserving($ast, $origAst, $origTokens);
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
