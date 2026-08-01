<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ConditionReportResource\Pages;

use App\Filament\Admin\Resources\ConditionReportResource;
use App\Models\ConditionReport;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewConditionReport extends ViewRecord
{
    protected static string $resource = ConditionReportResource::class;

    /**
     * @var array<int, ConditionReport|null>
     */
    private array $pairedReports = [];

    /**
     * The paired report, resolved once per record: seven closures render the
     * comparison, and each would otherwise re-run the same look-up.
     */
    private function pairedReportFor(ConditionReport $record): ?ConditionReport
    {
        if (! array_key_exists($record->id, $this->pairedReports)) {
            $this->pairedReports[$record->id] = $record->pairedReport();
        }

        return $this->pairedReports[$record->id];
    }

    public function infolist(Schema $infolist): Schema
    {
        return $infolist
            ->schema([
                Section::make(__('condition_reports.sections.report'))
                    ->columns(3)
                    ->schema([
                        TextEntry::make('booking.reference')->label(__('condition_reports.fields.booking')),
                        TextEntry::make('booking.car.registration_number')->label(__('condition_reports.fields.car')),
                        TextEntry::make('type')->label(__('condition_reports.fields.type'))->badge(),
                        TextEntry::make('performed_at')->label(__('condition_reports.fields.performed_at'))->dateTime(),
                        TextEntry::make('performedBy.name')->label(__('condition_reports.fields.performed_by'))->placeholder('—'),
                    ]),
                Section::make(__('condition_reports.sections.readings'))
                    ->description(__('condition_reports.sections.readings_description'))
                    ->columns(2)
                    ->schema([
                        // Headings are dynamic, but a closure cannot be passed to
                        // Section::make() — the panel's Section::configureUsing()
                        // evaluates getHeading() at configure time, before the section
                        // has a container. Static make() + closure heading() is safe.
                        Section::make('condition_reports.sections.this_report')
                            ->heading(fn (ConditionReport $record): string => $record->type->getLabel())
                            ->schema([
                                TextEntry::make('odometer')->label(__('condition_reports.fields.odometer'))->suffix(' km')->placeholder('—'),
                                TextEntry::make('fuel_level')->label(__('condition_reports.fields.fuel_level'))->badge()->placeholder('—'),
                                IconEntry::make('is_clean')->label(__('condition_reports.fields.clean'))->boolean(),
                                TextEntry::make('damage_points')
                                    ->label(__('condition_reports.fields.damage_points'))
                                    ->state(fn (ConditionReport $record): array => self::damagePointLines($record->damage_points))
                                    ->listWithLineBreaks()
                                    ->placeholder(__('condition_reports.placeholders.no_damage')),
                                TextEntry::make('notes')
                                    ->label(__('condition_reports.fields.notes'))
                                    ->placeholder('—')
                                    ->columnSpanFull(),
                            ]),
                        Section::make('condition_reports.sections.paired_report')
                            ->heading(fn (ConditionReport $record): ?string => $this->pairedReportFor($record)?->type?->getLabel())
                            // The pair is the point of the screen: the out reading and
                            // the in reading side by side, because a closeout charge is
                            // argued from the difference. Nothing to compare until the
                            // booking has both reports.
                            ->visible(fn (ConditionReport $record): bool => $this->pairedReportFor($record) !== null)
                            ->schema([
                                TextEntry::make('paired_odometer')
                                    ->label(__('condition_reports.fields.odometer'))
                                    ->state(fn (ConditionReport $record): ?int => $this->pairedReportFor($record)?->odometer)
                                    ->suffix(' km')
                                    ->placeholder('—'),
                                TextEntry::make('paired_fuel_level')
                                    ->label(__('condition_reports.fields.fuel_level'))
                                    ->state(fn (ConditionReport $record) => $this->pairedReportFor($record)?->fuel_level)
                                    ->badge()
                                    ->placeholder('—'),
                                IconEntry::make('paired_is_clean')
                                    ->label(__('condition_reports.fields.clean'))
                                    ->state(fn (ConditionReport $record): ?bool => $this->pairedReportFor($record)?->is_clean)
                                    ->boolean(),
                                TextEntry::make('paired_damage_points')
                                    ->label(__('condition_reports.fields.damage_points'))
                                    ->state(fn (ConditionReport $record): array => self::damagePointLines($this->pairedReportFor($record)?->damage_points))
                                    ->listWithLineBreaks()
                                    ->placeholder(__('condition_reports.placeholders.no_damage')),
                                TextEntry::make('paired_notes')
                                    ->label(__('condition_reports.fields.notes'))
                                    ->state(fn (ConditionReport $record): ?string => $this->pairedReportFor($record)?->notes)
                                    ->placeholder('—')
                                    ->columnSpanFull(),
                            ]),
                    ]),
                Section::make(__('condition_reports.sections.photos'))
                    ->schema([
                        // Private disk (ADR-009): Filament serves these through
                        // signed URLs, never a public path.
                        SpatieMediaLibraryImageEntry::make('photos')
                            ->label(__('condition_reports.fields.photos'))
                            ->collection('photos')
                            ->placeholder(__('condition_reports.placeholders.no_photos'))
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),
            ]);
    }

    /**
     * Damage points are a jsonb array whose shape is flexible (plain strings, or
     * part/severity/description objects); render whatever is there as readable lines.
     *
     * @return list<string>
     */
    private static function damagePointLines(mixed $points): array
    {
        if (! is_array($points)) {
            return [];
        }

        return array_map(static function (mixed $point): string {
            if (is_string($point)) {
                return $point;
            }

            if (is_array($point)) {
                $parts = array_values(array_filter([
                    $point['part'] ?? null,
                    $point['severity'] ?? null,
                    $point['description'] ?? $point['note'] ?? null,
                ], static fn (mixed $value): bool => is_string($value) && $value !== ''));

                return $parts === [] ? '—' : implode(' — ', $parts);
            }

            return '—';
        }, $points);
    }
}
