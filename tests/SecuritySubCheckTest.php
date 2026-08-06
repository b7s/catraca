<?php

declare(strict_types=1);

namespace B7S\Catraca\Tests;

use B7S\Catraca\Gate\SecuritySubCheck;
use PHPUnit\Framework\TestCase;

use function file_exists;
use function file_put_contents;
use function implode;
use function is_dir;
use function mkdir;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class SecuritySubCheckTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/catraca-laravel-owasp-' . uniqid('', true);
        mkdir($this->tmpDir . '/app/Models', 0755, true);
        mkdir($this->tmpDir . '/app/Http/Controllers', 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $this->removeTree($this->tmpDir);
        }
    }

    public function test_laravel_owasp_is_silent_on_a_clean_controller(): void
    {
        $this->write('app/Http/Controllers/CleanController.php', <<<'PHP'
            <?php
            namespace App\Http\Controllers;
            use App\Models\User;
            use Illuminate\Http\Request;
            final class CleanController
            {
                public function index(Request $request)
                {
                    return User::query()
                        ->where('email', $request->input('email'))
                        ->orderBy('created_at')
                        ->paginate();
                }
                public function store(Request $request)
                {
                    $user = new User();
                    $user->fill(['name' => $request->input('name'), 'email' => $request->input('email')]);
                    $user->save();
                    return $user;
                }
                public function forceFillExplicit(User $user)
                {
                    $user->forceFill(['read_at' => now()])->save();
                }
            }
            PHP);

        $findings = $this->runCheck();

        self::assertSame([], $findings, 'Clean Laravel code must not trip the laravel_owasp gate.');
    }

    public function test_flags_guarded_empty_array(): void
    {
        $this->write('app/Models/Risky.php', <<<'PHP'
            <?php
            namespace App\Models;
            use Illuminate\Database\Eloquent\Model;
            final class Risky extends Model
            {
                protected array $guarded = [];
            }
            PHP);

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertStringContainsString('mass assignment', implode(' ', $findings));
        self::assertStringContainsString('app/Models/Risky.php:6', $findings[0]);
    }

    public function test_flags_model_unguard_call(): void
    {
        $this->write('app/Console/Commands/ImportCommand.php', <<<'PHP'
            <?php
            namespace App\Console\Commands;
            use Illuminate\Database\Eloquent\Model;
            final class ImportCommand
            {
                public function handle(): void
                {
                    Model::unguard();
                    // seed...
                }
            }
            PHP);

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertStringContainsString('unguard()', $findings[0]);
    }

    public function test_flags_force_fill_with_request_all(): void
    {
        $this->write('app/Http/Controllers/BypassController.php', <<<'PHP'
            <?php
            namespace App\Http\Controllers;
            use App\Models\User;
            use Illuminate\Http\Request;
            final class BypassController
            {
                public function update(Request $request, User $user)
                {
                    $user->forceFill($request->all())->save();
                }
            }
            PHP);

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertStringContainsString('forceFill()', $findings[0]);
        self::assertStringContainsString('$fillable', $findings[0]);
    }

    public function test_does_not_flag_force_fill_with_explicit_literal_array(): void
    {
        $this->write('app/Http/Controllers/SafeForceFill.php', <<<'PHP'
            <?php
            namespace App\Http\Controllers;
            use App\Models\User;
            final class SafeForceFill
            {
                public function markRead(User $user)
                {
                    $user->forceFill(['read_at' => now()])->save();
                }
            }
            PHP);

        $findings = $this->runCheck();

        self::assertSame(
            [],
            $findings,
            'forceFill with a literal array is the documented escape hatch and must stay silent.',
        );
    }

    public function test_flags_dynamic_column_name_in_where_clause(): void
    {
        $this->write('app/Http/Controllers/SearchController.php', <<<'PHP'
            <?php
            namespace App\Http\Controllers;
            use App\Models\User;
            use Illuminate\Http\Request;
            final class SearchController
            {
                public function search(Request $request)
                {
                    return User::query()
                        ->where($request->input('column'), $request->input('value'))
                        ->get();
                }
            }
            PHP);

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertStringContainsString('where()', $findings[0]);
        self::assertStringContainsString('SQLi', $findings[0]);
    }

    public function test_flags_request_controlled_order_by(): void
    {
        $this->write('app/Http/Controllers/SortableController.php', <<<'PHP'
            <?php
            namespace App\Http\Controllers;
            use App\Models\User;
            use Illuminate\Http\Request;
            final class SortableController
            {
                public function index(Request $request)
                {
                    return User::query()
                        ->orderBy($request->input('sort', 'created_at'))
                        ->paginate();
                }
            }
            PHP);

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertStringContainsString('orderBy()', $findings[0]);
    }

    public function test_flags_db_raw_with_request_input(): void
    {
        $this->write('app/Http/Controllers/RawController.php', <<<'PHP'
            <?php
            namespace App\Http\Controllers;
            use Illuminate\Http\Request;
            use Illuminate\Support\Facades\DB;
            final class RawController
            {
                public function exec(Request $request)
                {
                    return DB::select($request->input('query'));
                }
            }
            PHP);

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        $message = implode(' ', $findings);
        self::assertTrue(
            str_contains($message, 'DB::select') || str_contains($message, 'DB::raw'),
            'The DB SQLi identifier flagged must name the dangerous DB facade method.',
        );
    }

    public function test_skips_comments_and_empty_lines(): void
    {
        $this->write('app/Models/DocOnly.php', <<<'PHP'
            <?php
            namespace App\Models;
            use Illuminate\Database\Eloquent\Model;
            // protected array $guarded = [];
            # Model::unguard();
            final class DocOnly extends Model
            {
                protected array $fillable = ['name'];
            }
            PHP);

        $findings = $this->runCheck();

        self::assertSame([], $findings, 'Commented-out risk patterns must not trip the gate.');
    }

    public function test_skips_multiline_docblock_illustrations(): void
    {
        $this->write('app/Models/DocblockIllustration.php', <<<'PHP'
            <?php
            namespace App\Models;
            use Illuminate\Database\Eloquent\Model;

            /**
             * Legacy illustration of a vulnerability, NOT real code.
             *
             *   1. Mass-assignment — `$guarded = []`, `Model::unguard()`,
             *      `forceFill($request->all()|only()|input())`,
             *      `forceCreate($request->all())`.
             *   2. Dynamic column-name SQLi — `->where($request->input('col'))`,
             *      `->orderBy($request->input('sort'))`, `->groupBy($request->input(...))`,
             *      `DB::raw($request->input(...))` and `DB::select($request->input(...))`.
             */
            final class DocblockIllustration extends Model
            {
                protected array $fillable = ['name'];
            }
            PHP);

        $findings = $this->runCheck();

        self::assertSame([], $findings, 'Docblock illustrations of the threat model must stay silent.');
    }

    public function test_skips_single_line_docblock_on_its_own_line(): void
    {
        $this->write('app/Models/SingleLineDocblock.php', <<<'PHP'
            <?php
            namespace App\Models;
            use Illuminate\Database\Eloquent\Model;
            /** Single-line docblock: Model::unguard() and ->where($request->input('col')) are illustrations, not code. */
            final class SingleLineDocblock extends Model
            {
                protected array $fillable = ['name'];
            }
            PHP);

        $findings = $this->runCheck();

        self::assertSame([], $findings, 'Single-line multi-line docblocks must not trip the gate.');
    }

    public function test_strips_inline_docblock_after_real_code_then_scans_the_rest(): void
    {
        // Real-code side is clean (literal array, fillable whitelisted), but a
        // trailing single-line docblock illustrates the threat model. The
        // inline docblock must be stripped before scanning.
        $this->write('app/Models/InlineDocblockOnCode.php', <<<'PHP'
            <?php
            namespace App\Models;
            use Illuminate\Database\Eloquent\Model;
            final class InlineDocblockOnCode extends Model
            {
                protected array $fillable = ['name']; /** legacy: Model::unguard(), ->forceFill($request->all()) */

                public function safe(): void
                {
                    $this->fill(['name' => 'x']); /** was: forceFill($request->all()) */
                }
            }
            PHP);

        $findings = $this->runCheck();

        self::assertSame([], $findings, 'Inline docblocks after safe code must be stripped before scanning.');
    }

    public function test_flags_real_code_when_inline_docblock_is_stripped_but_pattern_remains(): void
    {
        // The docblock is illustrative, but the executable real code itself
        // still calls forceFill with the request payload — the gate must flag
        // the real-code portion, not skip the whole line because of the
        // docblock that follows it.
        $this->write('app/Http/Controllers/RealRisk.php', <<<'PHP'
            <?php
            namespace App\Http\Controllers;
            use App\Models\User;
            use Illuminate\Http\Request;
            final class RealRisk
            {
                public function update(Request $request, User $user)
                {
                    $user->forceFill($request->all())->save(); /** legacy: was forceCreate($request->all()) */
                }
            }
            PHP);

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertStringContainsString('forceFill()', $findings[0]);
    }

    public function test_skips_tests_directory(): void
    {
        mkdir($this->tmpDir . '/tests/Feature', 0755, true);
        $this->write('tests/Feature/MassAssignmentTest.php', <<<'PHP'
            <?php
            test('mass assignment bug', function () {
                $model = new Risky();
                $model->forceFill(request()->all())->save();
            });
            PHP);

        $findings = $this->runCheck();

        self::assertSame([], $findings, 'The laravel_owasp gate scans app/, not tests/.');
    }

    public function test_reports_multiple_findings_in_one_file(): void
    {
        $this->write('app/Http/Controllers/KitchenSink.php', <<<'PHP'
            <?php
            namespace App\Http\Controllers;
            use App\Models\User;
            use Illuminate\Http\Request;
            use Illuminate\Support\Facades\DB;
            final class KitchenSink
            {
                public function index(Request $request)
                {
                    return User::query()
                        ->where($request->input('column'), 'value')
                        ->orderBy($request->input('sort'))
                        ->get();
                }
                public function store(Request $request)
                {
                    $u = new User();
                    $u->forceFill($request->all())->save();
                    DB::statement($request->input('ddl'));
                }
            }
            PHP);

        $findings = $this->runCheck();

        self::assertCount(4, $findings);
    }

    /**
     * @return array<int, string>
     */
    private function runCheck(): array
    {
        $sub = new SecuritySubCheck($this->tmpDir, [$this->tmpDir]);

        return $sub->checkLaravelOwasp();
    }

    public function test_gitleaks_returns_no_findings_on_a_clean_directory(): void
    {
        $sub = new SecuritySubCheck($this->tmpDir, [$this->tmpDir]);

        $findings = $sub->checkGitleaks();

        $this->assertSame([], $findings);
    }

    public function test_gitleaks_detects_a_hardcoded_aws_key_when_installed(): void
    {
        if (!$this->gitleaksAvailable()) {
            $this->markTestSkipped('gitleaks binary is not installed');
        }

        $this->write('app/Config/secrets.php', <<<'PHP'
            <?php
            return [
                'aws_key' => 'AKIAIOSFODNN7EXAMPLE',
            ];
            PHP);

        $sub = new SecuritySubCheck($this->tmpDir, [$this->tmpDir]);

        $findings = $sub->checkGitleaks();

        $this->assertNotEmpty($findings);
        $this->assertStringContainsString('secrets.php', $findings[0]);
        $this->assertStringContainsString('gitleaks:', $findings[0]);
    }

    public function test_gitleaks_filters_findings_under_vendor_paths(): void
    {
        if (!$this->gitleaksAvailable()) {
            $this->markTestSkipped('gitleaks binary is not installed');
        }

        $this->write('vendor/acme/package/Config.php', <<<'PHP'
            <?php
            return ['aws_key' => 'AKIAIOSFODNN7EXAMPLE'];
            PHP);

        $sub = new SecuritySubCheck($this->tmpDir, [$this->tmpDir]);

        $findings = $sub->checkGitleaks();

        $this->assertSame([], $findings);
    }

    private function gitleaksAvailable(): bool
    {
        $which = new \Symfony\Component\Process\Process(['which', 'gitleaks']);
        $which->run();

        return $which->isSuccessful() && trim($which->getOutput()) !== '';
    }

    private function write(string $relative, string $content): void
    {
        $path = $this->tmpDir . '/' . $relative;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $content);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeTree($path);
            } elseif (file_exists($path)) {
                unlink($path);
            }
        }

        if (is_dir($dir)) {
            rmdir($dir);
        }
    }
}
