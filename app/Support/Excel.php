<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Facades\Excel as ExcelFacade;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Thin wrapper over maatwebsite/excel for tabular exports. Row caps and
 * queued/chunked exports for very wide ranges are documented in the
 * migration blueprint; exports stream so memory stays bounded.
 */
class Excel
{
    /**
     * @param  array<int, string>  $headings
     * @param  iterable<int, array>  $rows
     */
    public static function stream(string $filename, array $headings, iterable $rows): StreamedResponse|BinaryFileResponse
    {
        $export = new class($headings, $rows) implements FromCollection, WithHeadings
        {
            public function __construct(private array $headings, private iterable $rows) {}

            public function collection(): Collection
            {
                $out = [];
                foreach ($this->rows as $row) {
                    $out[] = array_values(is_array($row) ? $row : (array) $row);
                }

                return collect($out);
            }

            public function headings(): array
            {
                return $this->headings;
            }
        };

        return ExcelFacade::download($export, $filename);
    }
}
