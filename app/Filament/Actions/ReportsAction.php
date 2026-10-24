<?php

namespace App\Filament\Actions;

use App\Reports\DateRanges;
use App\Reports\Report;
use App\Reports\ReportExport;
use App\Reports\ReportFilters;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * The Reports button on a module's screen: pick a report, set the standard date range (this month, this season,
 * the last 12 months...) and the report's own filters, choose Excel or PDF, and the file downloads. One action
 * for every module, so every report on the farm is asked for the same way.
 */
class ReportsAction
{
    /** @param  list<Report>  $reports */
    public static function make(array $reports): Action
    {
        $byKey = [];
        foreach ($reports as $report) {
            $byKey[$report->key()] = $report;
        }

        $groups = [];
        foreach ($byKey as $key => $report) {
            if ($report->filters() !== []) {
                $groups[] = Group::make($report->filters())
                    ->statePath('filters.'.self::slot($key))
                    ->visible(fn (Get $get) => $get('report') === $key);
            }
        }

        return Action::make('reports')
            ->label(__('plaashek.reports.button'))
            ->icon(Heroicon::OutlinedDocumentArrowDown)
            ->color('gray')
            ->modalHeading(__('plaashek.reports.button'))
            ->modalSubmitActionLabel(__('plaashek.reports.download'))
            ->schema([
                Select::make('report')->label(__('plaashek.reports.report'))
                    ->options(array_map(fn (Report $r) => $r->label(), $byKey))
                    ->placeholder(__('plaashek.reports.pick'))->required()->live()->native(false),
                Group::make([
                    Select::make('range')->label(__('plaashek.reports.range'))
                        ->options(DateRanges::options())->default('this_season')->required()->live()->native(),
                    Grid::make(2)->schema([
                        DatePicker::make('from')->label(__('plaashek.reports.from'))->required(),
                        DatePicker::make('to')->label(__('plaashek.reports.to'))->required()->afterOrEqual('from'),
                    ])->visible(fn (Get $get) => $get('range') === 'custom'),
                ])->visible(fn (Get $get) => isset($byKey[$get('report')]) && $byKey[$get('report')]->usesDateRange()),
                ...$groups,
                ToggleButtons::make('format')->label(__('plaashek.reports.format'))
                    ->options(['xlsx' => 'Excel', 'pdf' => 'PDF'])
                    ->icons(['xlsx' => Heroicon::OutlinedTableCells, 'pdf' => Heroicon::OutlinedDocumentText])
                    ->default('xlsx')->inline()->required(),
            ])
            ->action(function (array $data) use ($byKey) {
                $report = $byKey[$data['report']];
                [$from, $to] = $report->usesDateRange()
                    ? DateRanges::resolve($data['range'] ?? 'this_season', $data['from'] ?? null, $data['to'] ?? null)
                    : [null, null];
                $values = $data['filters'][self::slot($report->key())] ?? [];

                return app(ReportExport::class)->download($report, new ReportFilters($from, $to, $values), $data['format']);
            });
    }

    /** Form state paths cannot hold a dot: "kudde.events" is stored under "kudde__events". */
    private static function slot(string $key): string
    {
        return str_replace('.', '__', $key);
    }
}
