<?php

namespace App\Console\Commands;

use App\Support\LegacyTypeMapper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Generates one migration per legacy table plus the Eloquent model, from the
 * machine-readable audit (storage/app/legacy-schema.json) and the mapping
 * configs. Legacy PKs and column names are preserved verbatim.
 */
class LegacyGenerateSchema extends Command
{
    protected $signature = 'legacy:generate-schema {--force : Overwrite existing generated files}';

    protected $description = 'Generate migrations + models from the legacy schema audit';

    private const MIGRATION_TEMPLATE = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `{{LEGACY}}` as `{{NEW}}`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{{NEW}}', function (Blueprint $table) {
            $table->engine = 'InnoDB';
{{COLUMNS}}{{INDEXES}}        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{{NEW}}');
    }
};

PHP;

    private const MODEL_TEMPLATE = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;{{USES}}

/**
 * Legacy source: {{LEGACY}} (storesd).
 * Column names and primary key preserved verbatim.
 */
class {{CLASS}} extends Model
{
    protected $table = '{{NEW}}';

    protected $primaryKey = '{{PK}}';
    public $incrementing = {{INCREMENTING}};
    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];
{{RELATIONS}}}

PHP;

    public function handle(): int
    {
        $auditPath = storage_path('app/legacy-schema.json');
        if (! is_file($auditPath)) {
            $this->error('Audit missing. Run legacy:schema-audit first.');

            return self::FAILURE;
        }

        $schema = json_decode(File::get($auditPath), true);
        $tableMap = config('legacy-map-tables');
        $relationMap = config('legacy-map-relations');

        // FK lookup: child table + column -> parent PK signature
        $fkByChild = [];
        foreach ($relationMap['eligible'] as $rel) {
            $sig = LegacyTypeMapper::parentSignature($schema, $rel['parent'], $rel['parentKey']);
            if ($sig === null) {
                $this->warn("FK parent missing in audit: {$rel['parent']}.{$rel['parentKey']} (no coercion for {$rel['child']}.{$rel['column']})");

                continue;
            }
            $fkByChild[$rel['child'].'.'.$rel['column']] = $sig;
        }

        $migrationsDir = database_path('migrations');
        $modelsDir = app_path('Models');
        $timestamp = time();
        $seq = 0;

        $order = $this->topologicalOrder(array_keys($tableMap), $relationMap['eligible']);

        foreach ($order as $legacyName) {
            $map = $tableMap[$legacyName];
            if (! empty($map['ignore']) || ! empty($map['consolidate'])) {
                continue;
            }

            $newName = $map['new_name'];
            $def = $schema[$legacyName] ?? null;
            if ($def === null) {
                $this->warn("Table {$legacyName} in map but not in audit — skipped.");

                continue;
            }

            $seq++;
            // Deterministic stamp: re-runs overwrite the same files instead of
            // piling up duplicate timestamped migrations.
            $stamp = sprintf('2020_01_01_%06d', $seq);
            $file = "{$stamp}_create_{$newName}_table.php";

            $migration = str_replace(
                ['{{LEGACY}}', '{{NEW}}', '{{COLUMNS}}', '{{INDEXES}}'],
                [
                    $legacyName,
                    $newName,
                    $this->columnsCode($legacyName, $def, $fkByChild),
                    $this->indexesCode($newName, $def, $relationMap),
                ],
                self::MIGRATION_TEMPLATE
            );
            File::put("{$migrationsDir}/{$file}", $migration);

            $modelClass = Str::studly(Str::singular($newName));
            $pk = $def['primary'][0] ?? 'id';
            $hasAi = false;
            foreach ($def['columns'] as $c) {
                if (! empty($c['auto_increment'])) {
                    $hasAi = true;
                }
            }
            $relations = $this->relationsCode($newName, $relationMap);
            $uses = str_contains($relations, 'BelongsTo')
                ? "\nuse Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;"
                : '';
            $model = str_replace(
                ['{{LEGACY}}', '{{CLASS}}', '{{NEW}}', '{{PK}}', '{{INCREMENTING}}', '{{RELATIONS}}', '{{USES}}'],
                [$legacyName, $modelClass, $newName, $pk, $hasAi ? 'true' : 'false', $relations, $uses],
                self::MODEL_TEMPLATE
            );
            File::put("{$modelsDir}/{$modelClass}.php", $model);

            $this->line("  + {$newName} ({$legacyName})");
        }

        $this->info('Generated migrations and models.');

        return self::SUCCESS;
    }

    private function columnsCode(string $legacyName, array $def, array $fkByChild): string
    {
        $lines = '';
        foreach ($def['columns'] as $name => $col) {
            $fkTarget = $fkByChild[$legacyName.'.'.$name] ?? null;
            $lines .= LegacyTypeMapper::render($name, $col, $fkTarget)."\n";
        }

        return $lines;
    }

    private function indexesCode(string $newName, array $def, array $relationMap): string
    {
        $lines = '';
        $n = 0;
        foreach ($def['keys'] as $key) {
            $n++;
            $cols = implode("', '", $key['columns']);
            $unique = $key['unique'] ? 'unique' : 'index';
            $lines .= "            \$table->{$unique}(['{$cols}'], 'ix_{$newName}_{$n}');\n";
        }

        // Indexes on ineligible relation columns (polymorphic/text FKs).
        foreach ($relationMap['ineligible'] as $rel) {
            if ($rel['table'] === $newName) {
                $lines .= "            \$table->index('{$rel['column']}');\n";
            }
        }

        return $lines;
    }

    /**
     * belongsTo relations derived from the eligible relation map. Type-
     * discriminated ('when') pairs are excluded per the documented decision:
     * polymorphic sloc rows relate conditionally in service code.
     */
    private function relationsCode(string $newName, array $relationMap): string
    {
        $lines = "\n";
        foreach ($relationMap['eligible'] as $rel) {
            if ($rel['child'] !== $newName || isset($rel['when'])) {
                continue;
            }

            $method = Str::camel(Str::singular($rel['parent']));
            $class = Str::studly(Str::singular($rel['parent']));
            $lines .= str_replace(
                ['{{METHOD}}', '{{CLASS}}', '{{FK}}', '{{PK}}'],
                [$method, $class, $rel['column'], $rel['parentKey']],
                self::RELATION_TEMPLATE
            );
        }

        return $lines === "\n" ? '' : $lines;
    }

    private const RELATION_TEMPLATE = <<<'PHP'
    public function {{METHOD}}(): BelongsTo
    {
        return $this->belongsTo({{CLASS}}::class, '{{FK}}', '{{PK}}');
    }

PHP;

    /**
     * Orders tables so FK parents come before children (Kahn sort).
     */
    private function topologicalOrder(array $tables, array $relations): array
    {
        $edges = [];
        foreach ($relations as $rel) {
            if (in_array($rel['parent'], $tables, true) && in_array($rel['child'], $tables, true) && $rel['parent'] !== $rel['child']) {
                $edges[$rel['child']][] = $rel['parent'];
            }
        }

        $ordered = [];
        $remaining = $tables;
        while ($remaining !== []) {
            $ready = array_values(array_filter(
                $remaining,
                fn ($t) => empty(array_diff($edges[$t] ?? [], $ordered))
            ));
            if ($ready === []) {
                $ordered = array_merge($ordered, array_values($remaining));
                break;
            }
            foreach ($ready as $t) {
                $ordered[] = $t;
                $remaining = array_diff($remaining, [$t]);
            }
        }

        return $ordered;
    }
}
