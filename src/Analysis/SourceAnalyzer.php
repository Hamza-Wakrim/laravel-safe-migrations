<?php

namespace HamzaWakrim\SafeMigrations\Analysis;

use Illuminate\Database\Eloquent\Model;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Throwable;

/**
 * Reads the migration's PHP source to find what a dry run cannot see:
 *
 * - schema-condition: a branch on Schema::hasTable() / hasColumn() / ...
 *   During the dry run these always answer false, so one branch was never checked.
 * - reads-data: up() reads rows (DB::table()->get(), User::all(), ...).
 *   Pretend mode returns no rows, so whatever runs per row was never checked.
 */
final class SourceAnalyzer
{
    private const SCHEMA_FACADES = ['Illuminate\Support\Facades\Schema', 'Schema'];

    private const DB_FACADES = ['Illuminate\Support\Facades\DB', 'DB'];

    private const SCHEMA_CHECKS = ['hastable', 'hascolumn', 'hascolumns', 'hasindex', 'hasview'];

    /** Query builder / Eloquent methods that read rows. */
    private const BUILDER_READS = [
        'get', 'first', 'firstorfail', 'sole', 'find', 'findorfail', 'findmany', 'pluck', 'value', 'count',
        'exists', 'doesntexist', 'min', 'max', 'sum', 'avg', 'average', 'each', 'eachbyid', 'chunk',
        'chunkbyid', 'lazy', 'lazybyid', 'lazybyiddesc', 'cursor', 'paginate', 'simplepaginate', 'cursorpaginate',
    ];

    /** Connection methods that read rows (DB::select(), DB::connection('x')->select(), ...). */
    private const CONNECTION_READS = ['select', 'selectone', 'scalar', 'cursor', 'selectfromwriteconnection'];

    public function __construct(private readonly Analyzer $analyzer) {}

    /** @return list<Finding> */
    public function analyze(string $path, string $migration): array
    {
        $up = $this->upMethod($path);

        if ($up === null) {
            return [];
        }

        $findings = [];

        if (($severity = $this->analyzer->severityFor('schema-condition', Severity::Warning)) !== null) {
            foreach ($this->skippedSchemaBranches($up) as $check) {
                $findings[$check] ??= new Finding(
                    'schema-condition',
                    $severity,
                    "up() branches on Schema::{$check}(). During the check it always answers false, so the code on the other branch was never checked.",
                    'Review that branch by hand, or make the migration do the same thing on every database so there is nothing to branch on.',
                    $check,
                    migration: $migration,
                );
            }
        }

        if (($severity = $this->analyzer->severityFor('reads-data', Severity::Warning)) !== null) {
            foreach ($this->dataReads($up) as $source) {
                $findings['reads-data:'.$source] ??= new Finding(
                    'reads-data',
                    $severity,
                    "up() reads rows from [{$source}]. The dry run gets no rows back, so anything it does per row was never checked, and in production it runs for every row inside the migration.",
                    'Move the data change to a command or queued job that works in batches, and keep the migration for schema changes.',
                    $source,
                    migration: $migration,
                );
            }
        }

        return array_values($findings);
    }

    private function upMethod(string $path): ?Stmt\ClassMethod
    {
        try {
            $code = @file_get_contents($path);
            $ast = $code === false ? null : (new ParserFactory)->createForHostVersion()->parse($code);
        } catch (Throwable) {
            return null;
        }

        if ($ast === null) {
            return null;
        }

        $traverser = new NodeTraverser(new NameResolver);
        $ast = $traverser->traverse($ast);

        $up = (new NodeFinder)->findFirst($ast, fn (Node $node) => $node instanceof Stmt\ClassMethod && $node->name->toLowerString() === 'up');

        return $up instanceof Stmt\ClassMethod ? $up : null;
    }

    /**
     * The Schema::has*() checks whose skipped branch holds real code.
     *
     * @return list<string>
     */
    private function skippedSchemaBranches(Stmt\ClassMethod $up): array
    {
        $checks = [];
        $finder = new NodeFinder;

        foreach ($finder->find((array) $up->stmts, fn (Node $n) => $n instanceof Stmt\If_ || $n instanceof Stmt\ElseIf_ || $n instanceof Expr\Ternary || $n instanceof Expr\Match_) as $node) {
            $condition = match (true) {
                $node instanceof Stmt\If_, $node instanceof Stmt\ElseIf_ => $node->cond,
                $node instanceof Expr\Ternary => $node->cond,
                $node instanceof Expr\Match_ => $node->cond,
                default => null,
            };

            $check = $condition === null ? null : $this->schemaCheckIn($condition);

            if ($check === null) {
                continue;
            }

            if ($node instanceof Stmt\If_ && ! $this->branchIsSkipped($node, $condition)) {
                continue;
            }

            if ($node instanceof Stmt\ElseIf_ && $this->isTrivial($node->stmts)) {
                continue;
            }

            $checks[] = $check;
        }

        return $checks;
    }

    /**
     * Has*() answers false in the dry run. For `if (has())` the then-branch is
     * skipped; for `if (! has())` the else-branch is. Any other condition may
     * skip either one.
     */
    private function branchIsSkipped(Stmt\If_ $if, Expr $condition): bool
    {
        $else = $if->else === null ? [] : $if->else->stmts;

        if ($this->isSchemaCheck($condition)) {
            return ! $this->isTrivial($if->stmts);
        }

        if ($condition instanceof Expr\BooleanNot && $this->isSchemaCheck($condition->expr)) {
            return ! $this->isTrivial($else) || $if->elseifs !== [];
        }

        return ! $this->isTrivial($if->stmts) || ! $this->isTrivial($else);
    }

    /** @param  array<Stmt>  $stmts  A branch with nothing but `return;` changes nothing. */
    private function isTrivial(array $stmts): bool
    {
        foreach ($stmts as $stmt) {
            if (! $stmt instanceof Stmt\Return_ && ! $stmt instanceof Stmt\Nop) {
                return false;
            }
        }

        return true;
    }

    private function schemaCheckIn(Expr $expr): ?string
    {
        if ($this->isSchemaCheck($expr)) {
            /** @var Expr\StaticCall|Expr\MethodCall $expr */
            return $this->methodName($expr);
        }

        $call = (new NodeFinder)->findFirst($expr, fn (Node $n) => $n instanceof Expr && $this->isSchemaCheck($n));

        return $call instanceof Expr\StaticCall || $call instanceof Expr\MethodCall ? $this->methodName($call) : null;
    }

    private function isSchemaCheck(Expr $expr): bool
    {
        if (! $expr instanceof Expr\StaticCall && ! $expr instanceof Expr\MethodCall) {
            return false;
        }

        if (! in_array(strtolower((string) $this->methodName($expr)), self::SCHEMA_CHECKS, true)) {
            return false;
        }

        $root = $this->root($expr);

        return $root !== null && in_array($this->className($root), self::SCHEMA_FACADES, true);
    }

    /**
     * Where up() reads rows: table names for DB::table(), class names for models.
     *
     * @return list<string>
     */
    private function dataReads(Stmt\ClassMethod $up): array
    {
        $sources = [];
        $calls = (new NodeFinder)->find((array) $up->stmts, fn (Node $n) => $n instanceof Expr\MethodCall || $n instanceof Expr\StaticCall);

        foreach ($calls as $call) {
            /** @var Expr\MethodCall|Expr\StaticCall $call */
            $method = strtolower((string) $this->methodName($call));
            $root = $this->root($call);

            if ($root === null) {
                continue;
            }

            $class = $this->className($root);

            if (in_array($class, self::DB_FACADES, true)) {
                $table = $this->tableIn($call);

                if ($table !== null && in_array($method, self::BUILDER_READS, true)) {
                    $sources[] = $table;
                } elseif ($table === null && in_array($method, self::CONNECTION_READS, true)) {
                    $sources[] = 'raw query';
                }

                continue;
            }

            if ($class !== null && $this->isModel($class) && in_array($method, [...self::BUILDER_READS, 'all'], true)) {
                $sources[] = $class;
            }
        }

        return array_values(array_unique($sources));
    }

    /** The table of a DB::table('x')->...->read() chain, or null when it is not a builder chain. */
    private function tableIn(Expr\MethodCall|Expr\StaticCall $call): ?string
    {
        $node = $call;

        while (true) {
            if (strtolower((string) $this->methodName($node)) === 'table' && $node !== $call) {
                $arg = $node->getArgs()[0]->value ?? null;

                return $arg instanceof Node\Scalar\String_ ? $arg->value : 'query';
            }

            if (! $node instanceof Expr\MethodCall || ! ($node->var instanceof Expr\MethodCall || $node->var instanceof Expr\StaticCall)) {
                return null;
            }

            $node = $node->var;
        }
    }

    /** The static call a method chain starts with: DB::table(...)->where(...)->get() starts with DB::table(). */
    private function root(Expr $expr): ?Expr\StaticCall
    {
        while ($expr instanceof Expr\MethodCall) {
            $expr = $expr->var;
        }

        return $expr instanceof Expr\StaticCall ? $expr : null;
    }

    private function className(Expr\StaticCall $call): ?string
    {
        if (! $call->class instanceof Node\Name) {
            return null;
        }

        $name = $call->class->getAttribute('resolvedName');

        return ($name instanceof Node\Name ? $name : $call->class)->toString();
    }

    private function methodName(Expr\MethodCall|Expr\StaticCall $call): ?string
    {
        return $call->name instanceof Node\Identifier ? $call->name->toString() : null;
    }

    private function isModel(string $class): bool
    {
        try {
            return class_exists($class) && is_subclass_of($class, Model::class);
        } catch (Throwable) {
            return false;
        }
    }
}
