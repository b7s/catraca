<?php

declare(strict_types=1);

namespace B7S\Catraca\Gate;

/**
 * Pattern table + human-readable labels for the {@see SecuritySubCheck::checkLaravelOwasp()}
 * OWASP gate. Lives in its own file so {@see SecuritySubCheck} stays under the
 * catraca file-size limit (max 1000 lines).
 *
 * Two OWASP gaps these patterns close (neither is caught by the existing
 * SQL_INJECTION_PATTERNS or the generic security greps):
 *
 *   1. Mass-assignment — `$guarded = []`, `Model::unguard()`,
 *      `forceFill($request->all()|only()|input())`,
 *      `forceCreate($request->all()|only()|input())`,
 *      `$model->fill($request->all())` / `update($request->all())`.
 *      These bypass the `$fillable` whitelist (or disable it globally) and
 *      let a request decide which columns get written — a classic OWASP
 *      A01:2021 mass-assignment vector. `forceFill([...explicit literal...])`
 *      is intentionally NOT flagged — that is the documented escape hatch
 *      for trusted internal mutations and the existing baseline already
 *      relies on it.
 *
 *   2. Dynamic column-name SQLi — `->where($request->input('col'), …)`,
 *      `->orderBy($request->input('sort'))`, `->orderByRaw($request->...)`,
 *      `->whereColumn($request->...)`, `->select($request->input(...))`,
 *      `->groupBy($request->input(...))`, plus `DB::raw($request->input(...))`
 *      and `DB::select($request->input(...))` — these let the request decide
 *      the SQL identifier rather than the value, which the existing
 *      SQL_INJECTION_PATTERNS structural greps do not cover (they only catch
 *      `$var` interpolated inside a quoted SQL fragment).
 */
final class LaravelOwaspPatterns
{
    /**
     * Ordered list of regex patterns paired 1:1 with {@see self::LABELS} by
     * dict key. Mass-assignment entries come first, then dynamic column-name
     * identifiers. The order is presentation-only; the loop short-circuits
     * after the first match per line, so the more specific pattern is listed
     * first when two could match the same line.
     */
    public const array PATTERNS = [
        // Mass-assignment — model exposes every column.
        '/protected\s+(?:readonly\s+)?(?:array\s+)?\$guarded\s*=\s*\[\s*\]/',
        '/public\s+(?:readonly\s+)?(?:array\s+)?\$guarded\s*=\s*\[\s*\]/',
        // Mass-assignment — global unguard() disables the fillable guard.
        '/\bModel::unguard\s*\(/',
        '/\bself::unguard\s*\(/',
        '/\bstatic::unguard\s*\(/',
        '/\bunguard\s*\(\s*true\s*\)/',
        // Mass-assignment — bypassing fillable with request payload.
        '/->forceFill\s*\(\s*\$request->(?:all|only|input|post|get|query)\s*\(/',
        '/->forceCreate\s*\(\s*\$request->(?:all|only|input|post|get|query)\s*\(/',
        '/->fill\s*\(\s*\$request->all\s*\(\s*\)\s*\)/',
        '/->update\s*\(\s*\$request->all\s*\(\s*\)\s*\)/',
        // Dynamic column-name SQLi — request controls the SQL identifier.
        '/->where\s*\(\s*\$request->(?:input|get|post|query)\s*\(/',
        '/->orWhere\s*\(\s*\$request->(?:input|get|post|query)\s*\(/',
        '/->whereColumn\s*\(\s*\$request->(?:input|get|post|query)\s*\(/',
        '/->orderBy\s*\(\s*\$request->(?:input|get|post|query)\s*\(/',
        '/->orderByRaw\s*\(\s*\$request->(?:input|get|post|query)\s*\(/',
        '/->groupBy\s*\(\s*\$request->(?:input|get|post|query)\s*\(/',
        '/->select\s*\(\s*\$request->(?:input|get|post|query)\s*\(/',
        '/DB::raw\s*\(\s*\$request->(?:input|get|post|query)\s*\(/',
        '/DB::select\s*\(\s*\$request->(?:input|get|post|query)\s*\(/',
        '/DB::statement\s*\(\s*\$request->(?:input|get|post|query)\s*\(/',
    ];

    /**
     * Pattern → human-readable label. Keys are verbatim regex strings from
     * {@see PATTERNS}; values are the finding description that surfaces in
     * the catraca report. PHP string-array keys make the lookup a single
     * `isset()` instead of a linear scan, and keep labels co-located with
     * their patterns.
     */
    public const array LABELS = [
        '/protected\s+(?:readonly\s+)?(?:array\s+)?\$guarded\s*=\s*\[\s*\]/' => 'Model::$guarded = [] exposes every column to mass assignment',
        '/public\s+(?:readonly\s+)?(?:array\s+)?\$guarded\s*=\s*\[\s*\]/' => 'Model::$guarded = [] exposes every column to mass assignment',
        '/\bModel::unguard\s*\(/' => 'Model::unguard() disables mass-assignment protection globally',
        '/\bself::unguard\s*\(/' => 'self::unguard() disables mass-assignment protection globally',
        '/\bstatic::unguard\s*\(/' => 'static::unguard() disables mass-assignment protection globally',
        '/\bunguard\s*\(\s*true\s*\)/' => 'unguard(true) disables mass-assignment protection globally',
        '/->forceFill\s*\(\s*\$request->(?:all|only|input|post|get|query)\s*\(/' => 'forceFill() with request payload bypasses $fillable',
        '/->forceCreate\s*\(\s*\$request->(?:all|only|input|post|get|query)\s*\(/' => 'forceCreate() with request payload bypasses $fillable',
        '/->fill\s*\(\s*\$request->all\s*\(\s*\)\s*\)/' => 'fill() with $request->all() bypasses $fillable',
        '/->update\s*\(\s*\$request->all\s*\(\s*\)\s*\)/' => 'update() with $request->all() bypasses $fillable',
        '/->where\s*\(\s*\$request->(?:input|get|post|query)\s*\(/' => 'where() with request-controlled column name (SQLi)',
        '/->orWhere\s*\(\s*\$request->(?:input|get|post|query)\s*\(/' => 'orWhere() with request-controlled column name (SQLi)',
        '/->whereColumn\s*\(\s*\$request->(?:input|get|post|query)\s*\(/' => 'whereColumn() with request-controlled column name (SQLi)',
        '/->orderBy\s*\(\s*\$request->(?:input|get|post|query)\s*\(/' => 'orderBy() with request-controlled column name (SQLi)',
        '/->orderByRaw\s*\(\s*\$request->(?:input|get|post|query)\s*\(/' => 'orderByRaw() with request-controlled column name (SQLi)',
        '/->groupBy\s*\(\s*\$request->(?:input|get|post|query)\s*\(/' => 'groupBy() with request-controlled column name (SQLi)',
        '/->select\s*\(\s*\$request->(?:input|get|post|query)\s*\(/' => 'select() with request-controlled column name (SQLi)',
        '/DB::raw\s*\(\s*\$request->(?:input|get|post|query)\s*\(/' => 'DB::raw() with request-controlled SQL fragment (SQLi)',
        '/DB::select\s*\(\s*\$request->(?:input|get|post|query)\s*\(/' => 'DB::select() with request-controlled SQL (SQLi)',
        '/DB::statement\s*\(\s*\$request->(?:input|get|post|query)\s*\(/' => 'DB::statement() with request-controlled SQL (SQLi)',
    ];

    /**
     * Label for a given pattern from {@see PATTERNS}. Falls back to a generic
     * "Laravel OWASP risk" if the caller passes a pattern not in the table.
     *
     * @return non-empty-string
     */
    public static function labelFor(string $pattern): string
    {
        return self::LABELS[$pattern] ?? 'Laravel OWASP risk';
    }

    private function __construct()
    {
        // Pure pattern-table utility — never instantiated.
    }
}
