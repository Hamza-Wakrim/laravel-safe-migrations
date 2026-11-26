<?php

namespace HamzaWakrim\SafeMigrations\Tests\Unit;

use HamzaWakrim\SafeMigrations\Analysis\Analyzer;
use HamzaWakrim\SafeMigrations\Analysis\Finding;
use HamzaWakrim\SafeMigrations\Analysis\SourceAnalyzer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SourceAnalyzerTest extends TestCase
{
    /** @return array<string, array{string, list<string>}> */
    public static function sources(): array
    {
        return [
            'then-branch skipped' => ["if (Schema::hasColumn('users', 'legacy')) { Schema::table('users', fn (\$t) => \$t->dropColumn('legacy')); }", ['schema-condition:hasColumn']],
            'negated without else is fully checked' => ["if (! Schema::hasColumn('users', 'nick')) { Schema::table('users', fn (\$t) => \$t->string('nick')->nullable()); }", []],
            'negated with else' => ["if (! Schema::hasTable('teams')) { Schema::create('teams', fn (\$t) => \$t->id()); } else { DB::statement('TRUNCATE teams'); }", ['schema-condition:hasTable']],
            'early return is fine' => ["if (Schema::hasTable('teams')) { return; } Schema::create('teams', fn (\$t) => \$t->id());", []],
            'complex condition' => ["if (Schema::hasTable('a') && \$x) { Schema::drop('a'); }", ['schema-condition:hasTable']],
            'schema connection' => ["if (Schema::connection('other')->hasIndex('users', 'i')) { Schema::drop('a'); }", ['schema-condition:hasIndex']],
            'ternary' => ["\$t = Schema::hasColumn('users', 'a') ? 'a' : 'b';", ['schema-condition:hasColumn']],
            'db table get' => ["foreach (DB::table('users')->where('active', 1)->get() as \$u) { DB::table('users')->where('id', \$u->id)->update(['x' => 1]); }", ['reads-data:users']],
            'db table chunk' => ["DB::table('orders')->orderBy('id')->chunkById(100, fn (\$rows) => null);", ['reads-data:orders']],
            'db select' => ["\$rows = DB::select('select * from users');", ['reads-data:raw query']],
            'db connection select' => ["\$rows = DB::connection('other')->select('select 1');", ['reads-data:raw query']],
            'builder select is not a read' => ["DB::table('users')->select('id')->update(['a' => 1]);", []],
            'update without read' => ["DB::table('users')->where('id', 1)->update(['a' => 1]);", []],
            'model all' => ['User::all()->each(fn ($u) => $u->touch());', ['reads-data:Illuminate\Foundation\Auth\User']],
            'model query get' => ["User::query()->where('a', 1)->get();", ['reads-data:Illuminate\Foundation\Auth\User']],
            'non-model static call' => ["Str::of('a')->value();", []],
            'plain schema change' => ["Schema::table('users', fn (\$t) => \$t->string('a')->nullable());", []],
        ];
    }

    /** @param  list<string>  $expected */
    #[DataProvider('sources')]
    public function test_it_reads_the_source(string $up, array $expected): void
    {
        $this->assertSame($expected, $this->findings($up));
    }

    public function test_only_up_is_read(): void
    {
        $this->assertSame([], $this->findings("Schema::table('users', fn (\$t) => \$t->string('a')->nullable());", "foreach (DB::table('users')->get() as \$u) {}"));
    }

    public function test_rules_can_be_turned_off(): void
    {
        $path = $this->write("if (Schema::hasTable('a')) { Schema::drop('a'); } DB::table('users')->get();", '');

        $this->assertSame([], (new SourceAnalyzer(Analyzer::withDefaultRules(['schema-condition' => false, 'reads-data' => false])))->analyze($path, 'm'));
    }

    public function test_unparseable_file_is_ignored(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sm').'.php';
        file_put_contents($path, '<?php return new class { public function up( {');

        $this->assertSame([], (new SourceAnalyzer(Analyzer::withDefaultRules()))->analyze($path, 'm'));
    }

    /** @return list<string> */
    private function findings(string $up, string $down = ''): array
    {
        $findings = (new SourceAnalyzer(Analyzer::withDefaultRules()))->analyze($this->write($up, $down), 'm');

        return array_map(fn (Finding $f) => $f->rule.':'.$f->subject, $findings);
    }

    private function write(string $up, string $down): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sm').'.php';

        file_put_contents($path, <<<PHP
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Foundation\Auth\User;
        use Illuminate\Support\Facades\DB;
        use Illuminate\Support\Facades\Schema;
        use Illuminate\Support\Str;

        return new class extends Migration
        {
            public function up(): void
            {
                {$up}
            }

            public function down(): void
            {
                {$down}
            }
        };
        PHP);

        return $path;
    }
}
