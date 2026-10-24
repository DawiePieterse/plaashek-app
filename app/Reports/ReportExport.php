<?php

namespace App\Reports;

use App\Support\Farm;
use Dompdf\Dompdf;
use Dompdf\Options;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A report as a file: Excel (an .xlsx anyone's spreadsheet opens, numbers as numbers so they sum) or PDF (A4, the
 * farm's name and the filters on top, to print or send to the vet, the bank or the auditor). Both are made on the
 * server with libraries in the zip: nothing leaves the farm's install.
 */
class ReportExport
{
    public function __construct(private Farm $farm) {}

    public function download(Report $report, ReportFilters $filters, string $format): StreamedResponse
    {
        $name = str_replace('.', '-', $report->key()).'-'.today()->toDateString();

        return $format === 'pdf'
            ? response()->streamDownload(fn () => print ($this->pdf($report, $filters)), $name.'.pdf', ['Content-Type' => 'application/pdf'])
            : response()->streamDownload(fn () => $this->xlsx($report, $filters), $name.'.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
    }

    /** Writes the workbook to the output: one sheet, a bold header row, then the rows. */
    public function xlsx(Report $report, ReportFilters $filters, string $to = 'php://output'): void
    {
        $writer = new Writer;
        $writer->openToFile($to);
        $writer->getCurrentSheet()->setName(mb_substr($report->label(), 0, 31));
        $writer->addRow(Row::fromValues(array_values($report->columns()), (new Style)->setFontBold()));

        $keys = array_keys($report->columns());
        foreach ($report->rows($filters) as $row) {
            $writer->addRow(Row::fromValues(array_map(fn (string $k) => $row[$k] ?? null, $keys)));
        }

        $writer->close();
    }

    public function pdf(Report $report, ReportFilters $filters): string
    {
        $rows = [];
        foreach ($report->rows($filters) as $row) {
            $rows[] = $row;
        }

        $html = view('reports.pdf', [
            'farm' => $this->farm->name(),
            'title' => $report->label(),
            'range' => $filters->rangeLabel(),
            'columns' => $report->columns(),
            'rows' => $rows,
            'generated' => now()->translatedFormat('j M Y H:i'),
        ])->render();

        $options = new Options;
        // Everything the page needs is in it; a PDF that fetches from the internet is a PDF that hangs offline.
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html);
        $pdf->setPaper('A4', $report->landscape() ? 'landscape' : 'portrait');
        $pdf->render();

        return (string) $pdf->output();
    }
}
