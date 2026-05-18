<?php

declare(strict_types=1);

namespace B7S\Catraca\Analyzer;

use FilesystemIterator;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use SplFileInfo;
use Throwable;

use function array_merge;
use function file_get_contents;
use function in_array;
use function is_dir;
use function max;
use function preg_match;
use function sprintf;

readonly class ConditionOrderAnalyzer
{
    private const array GUARD_FUNCTIONS = [
        'isset', 'empty', 'is_array', 'is_bool', 'is_callable', 'is_countable',
        'is_double', 'is_float', 'is_int', 'is_integer', 'is_iterable',
        'is_long', 'is_null', 'is_numeric', 'is_object', 'is_real',
        'is_resource', 'is_scalar', 'is_string', 'array_key_exists',
        'key_exists', 'defined', 'class_exists', 'interface_exists',
        'trait_exists', 'function_exists', 'method_exists', 'property_exists',
    ];

    private const array CHEAP_FUNCTIONS = [
        'count', 'strlen', 'mb_strlen', 'sizeof', 'in_array', 'array_search',
        'str_contains', 'str_starts_with', 'str_ends_with', 'preg_match',
        'preg_match_all', 'file_exists', 'is_file', 'is_dir', 'is_readable',
        'is_writable', 'is_link', 'ctype_digit', 'ctype_alpha', 'ctype_alnum',
    ];

    public const array SIDE_EFFECT_FUNCTIONS = [
        'mkdir', 'rmdir', 'unlink', 'rename', 'copy', 'touch',
        'file_put_contents', 'fwrite', 'fclose', 'chmod', 'chown', 'chgrp',
        'symlink', 'link', 'opendir', 'closedir',
    ];

    public function __construct(
        private ParserFactory $parserFactory = new ParserFactory,
    ) {}

    /**
     * @param  array<int, string>  $paths
     * @return array<int, array{file: string, line: int, message: string}>
     */
    public function analyze(array $paths): array
    {
        $parser = $this->parserFactory->createForHostVersion();
        $nodeFinder = new NodeFinder;
        $violations = [];

        foreach ($this->collectPhpFiles($paths) as $file) {
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

            foreach ($booleanOps as $op) {
                if ($this->hasSideEffects($op)) {
                    continue;
                }

                $leftCost = $this->computeCost($op->left);
                $rightCost = $this->computeCost($op->right);

                if ($leftCost > $rightCost) {
                    $operator = $op instanceof BooleanAnd ? '&&' : '||';
                    $violations[] = [
                        'file' => $file,
                        'line' => $op->getStartLine(),
                        'message' => sprintf(
                            'Condition order: cheaper condition should come first (%s at line %d: left side costs %d, right side costs %d)',
                            $operator,
                            $op->getStartLine(),
                            $leftCost,
                            $rightCost,
                        ),
                    ];
                }
            }
        }

        return $violations;
    }

    /**
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    private function collectPhpFiles(array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            if (! is_dir($path)) {
                if (preg_match('/\.php$/', $path) === 1) {
                    $files[] = $path;
                }

                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            );
            $regexIterator = new RegexIterator($iterator, '/\.php$/');

            foreach ($regexIterator as $file) {
                /** @var SplFileInfo $file */
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    public function computeCost(Node $node): int
    {
        return match (true) {
            $node instanceof Expr\Variable,
            $node instanceof Node\Scalar,
            $node instanceof Expr\ConstFetch,
            $node instanceof Expr\ClassConstFetch,
            $node instanceof Expr\Instanceof_,
            $node instanceof Expr\Isset_ => 0,
            $node instanceof Expr\Empty_ => $this->computeCost($node->expr),

            $node instanceof Expr\PropertyFetch => max(1, $this->computeCost($node->var)),
            $node instanceof Expr\NullsafePropertyFetch => max(1, $this->computeCost($node->var)),
            $node instanceof Expr\StaticPropertyFetch => max(1, $this->computeCost($node->class)),
            $node instanceof Expr\ArrayDimFetch => max(
                1,
                $this->computeCost($node->var),
                $node->dim !== null ? $this->computeCost($node->dim) : 0,
            ),
            $node instanceof Expr\Array_ => $this->arrayCost($node),

            $node instanceof Expr\BinaryOp\Greater,
            $node instanceof Expr\BinaryOp\GreaterOrEqual,
            $node instanceof Expr\BinaryOp\Smaller,
            $node instanceof Expr\BinaryOp\SmallerOrEqual,
            $node instanceof Expr\BinaryOp\Spaceship,
            $node instanceof Expr\BinaryOp\Equal,
            $node instanceof Expr\BinaryOp\NotEqual,
            $node instanceof Expr\BinaryOp\Identical,
            $node instanceof Expr\BinaryOp\NotIdentical,
            $node instanceof Expr\BinaryOp\Concat,
            $node instanceof BooleanAnd,
            $node instanceof BooleanOr => max(
                $this->computeCost($node->left),
                $this->computeCost($node->right),
            ),

            $node instanceof Expr\MethodCall,
            $node instanceof Expr\NullsafeMethodCall,
            $node instanceof Expr\Assign,
            $node instanceof Expr\AssignOp,
            $node instanceof Expr\StaticCall,
            $node instanceof Expr\New_ => 3,

            $node instanceof Expr\BooleanNot => $this->computeCost($node->expr),

            $node instanceof Expr\FuncCall => $this->functionCallCost($node),

            $node instanceof Expr\Ternary => max(
                $this->computeCost($node->cond),
                $this->computeCost($node->if ?? $node->cond),
                $this->computeCost($node->else),
            ),

            $node instanceof Expr\ErrorSuppress => $this->computeCost($node->expr),

            $node instanceof Expr\BinaryOp => max(
                $this->computeCost($node->left),
                $this->computeCost($node->right),
            ),

            default => 2,
        };
    }

    private function functionCallCost(Expr\FuncCall $node): int
    {
        $name = $this->resolveFunctionName($node);

        $baseCost = 3;
        if ($name !== null) {
            if (in_array($name, self::GUARD_FUNCTIONS, true)) {
                $baseCost = 0;
            } elseif (in_array($name, self::CHEAP_FUNCTIONS, true)) {
                $baseCost = 1;
            }
        }

        $argCosts = [];
        foreach ($node->args as $arg) {
            if ($arg instanceof Node\Arg) {
                $argCosts[] = $this->computeCost($arg->value);
            }
        }

        if ($argCosts === []) {
            return $baseCost;
        }

        return max($baseCost, max($argCosts));
    }

    private function arrayCost(Expr\Array_ $node): int
    {
        $costs = [];
        foreach ($node->items as $item) {
            $costs[] = $this->computeCost($item->value);
            if ($item->key !== null) {
                $costs[] = $this->computeCost($item->key);
            }
        }

        if ($costs === []) {
            return 0;
        }

        return max($costs);
    }

    public function resolveFunctionName(Expr\FuncCall $node): ?string
    {
        if (! $node->name instanceof Node\Name) {
            return null;
        }

        return $node->name->getLast();
    }

    public function hasSideEffects(BooleanAnd|BooleanOr $op): bool
    {
        $nodeFinder = new NodeFinder;

        /** @var array<int, Expr\FuncCall> $calls */
        $calls = $nodeFinder->findInstanceOf($op, Expr\FuncCall::class);

        foreach ($calls as $call) {
            $name = $this->resolveFunctionName($call);
            if ($name !== null && in_array($name, self::SIDE_EFFECT_FUNCTIONS, true)) {
                return true;
            }
        }

        return false;
    }
}
