<?php

namespace App\Support;

/**
 * Maps legacy MySQL column definitions (parsed from the dump audit) onto
 * Laravel Blueprint column code, preserving the exact type signature.
 *
 * Integer FK columns are coerced to the exact unsigned signature of their
 * parent PK so the add-constraints step can attach foreign keys, and FK
 * columns are forced nullable (legacy sentinels '0'/'' are scrubbed to NULL
 * during the data transform).
 */
class LegacyTypeMapper
{
    /**
     * Render the literal Blueprint PHP code for one column so generated
     * migrations are self-contained.
     */
    public static function render(string $name, array $col, ?array $fkTarget = null): string
    {
        $type = strtolower(trim($col['type']));
        $unsigned = $fkTarget ? (bool) $fkTarget['unsigned'] : (bool) $col['unsigned'];
        $nullable = $fkTarget !== null ? true : (bool) $col['nullable'];

        if (preg_match('/^(tinyint|smallint|mediumint|bigint|int|integer)\b/', $type, $im)) {
            $method = match ($im[1]) {
                'tinyint' => 'tinyInteger',
                'smallint' => 'smallInteger',
                'mediumint' => 'mediumInteger',
                'bigint' => 'bigInteger',
                default => 'integer',
            };
            $args = [];
        } elseif (preg_match('/^(double|decimal|float)\s*\((\d+),\s*(\d+)\)/', $type, $dm)) {
            $method = 'decimal';
            $args = [(int) $dm[2], (int) $dm[3]];
        } elseif (preg_match('/^double\b/', $type)) {
            $method = 'double';
            $args = [];
        } elseif (preg_match('/^float\b/', $type)) {
            $method = 'float';
            $args = [];
        } elseif (preg_match('/^varchar\s*\((\d+)\)/', $type, $vm)) {
            $method = 'string';
            $args = [(int) $vm[1]];
        } elseif (preg_match('/^char\s*\((\d+)\)/', $type, $cm)) {
            $method = 'char';
            $args = [(int) $cm[1]];
        } elseif (preg_match('/^(tinytext|mediumtext|longtext|text)\b/', $type)) {
            $method = 'text';
            $args = [];
        } elseif (preg_match('/^date\b/', $type)) {
            $method = 'date';
            $args = [];
        } elseif (preg_match('/^datetime\b/', $type)) {
            $method = 'dateTime';
            $args = [];
        } elseif (preg_match('/^timestamp\b/', $type)) {
            $method = 'timestamp';
            $args = [];
        } elseif (preg_match('/^time\b/', $type)) {
            $method = 'time';
            $args = [];
        } elseif (preg_match('/^year\b/', $type)) {
            $method = 'year';
            $args = [];
        } elseif (preg_match('/(blob|binary)/', $type)) {
            $method = 'binary';
            $args = [];
        } else {
            $method = 'string';
            $args = [255];
        }

        $chain = '$table->'.$method."('".$name."'";
        foreach ($args as $arg) {
            $chain .= ', '.var_export($arg, true);
        }
        if ($method === 'string' && $args === [255]) {
            // Fallback flag: preserve the legacy type as a comment.
            $chain .= ", 'legacy type unmapped: ".addslashes($col['type'])."'";
        }
        $chain .= ')';

        if ($unsigned) {
            $chain .= '->unsigned()';
        }

        if (! empty($col['auto_increment'])) {
            $chain .= '->autoIncrement()->primary()';

            return "            {$chain};";
        }

        if ($nullable) {
            $chain .= '->nullable()';
        } elseif (! empty($col['has_default'])) {
            $chain .= '->default('.var_export($col['default'], true).')';
        }

        return "            {$chain};";
    }

    /**
     * Parent PK signature for FK coercion.
     *
     * @return array{unsigned: bool, type: string}|null
     */
    public static function parentSignature(array $schema, string $parentTable, string $parentColumn): ?array
    {
        if (! isset($schema[$parentTable]['columns'][$parentColumn])) {
            return null;
        }

        $p = $schema[$parentTable]['columns'][$parentColumn];

        return ['unsigned' => (bool) $p['unsigned'], 'type' => $p['type']];
    }
}
