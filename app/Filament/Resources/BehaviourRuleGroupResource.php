<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BehaviourRuleGroupResource\Pages;
use App\Filament\Resources\BehaviourRuleGroupResource\RelationManagers\LevelsRelationManager;
use App\Models\BehaviourRuleGroup;
use App\Models\EpraiseDemeritCategory;
use App\Models\EpraiseInterventionCategory;
use App\Models\EpraisePointCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BehaviourRuleGroupResource extends Resource
{
    protected static ?string $model = BehaviourRuleGroup::class;
    protected static ?string $navigationIcon = 'heroicon-o-swatch';
    protected static ?string $navigationLabel = 'Behaviour Rule Groups';
    protected static ?string $navigationGroup = 'Maintenance';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Group')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')->required(),
                    Forms\Components\Select::make('source')
                        ->required()
                        ->options([
                            'demerit' => 'Demerit',
                            'intervention' => 'Intervention',
                            'point' => 'Point',
                            'points' => 'Point (alias)',
                        ]),
                    Forms\Components\Select::make('categories')
                        ->label('Categories')
                        ->multiple()
                        ->required()
                        ->options(fn (Forms\Get $get) => match ($get('source')) {
                            'demerit' => EpraiseDemeritCategory::pluck('epraise_name', 'epraise_name')->toArray(),
                            'intervention' => EpraiseInterventionCategory::pluck('epraise_name', 'epraise_name')->toArray(),
                            'point', 'points' => EpraisePointCategory::pluck('epraise_name', 'epraise_name')->toArray(),
                            default => [],
                        }),
                    Forms\Components\Select::make('timeframe')
                        ->required()
                        ->options([
                            'term' => 'Term',
                            'rolling_school_days' => 'Rolling school days',
                            'academic_year' => 'Academic year',
                            'today' => 'Today',
                        ])
                        ->default('rolling_school_days'),
                    Forms\Components\TextInput::make('rolling_days')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(200)
                        ->label('Rolling days'),
                    Forms\Components\Select::make('metric')
                        ->options([
                            'count' => 'Count',
                            'sum' => 'Sum of points',
                        ])
                        ->default('count')
                        ->required(),
                    Forms\Components\Select::make('counter_mode')
                        ->label('Counter mode')
                        ->options([
                            'cumulative' => 'Cumulative',
                            'rollover' => 'Rollover',
                        ])
                        ->default('cumulative')
                        ->required()
                        ->helperText('Rollover resets the effective count after the configured limit.'),
                    Forms\Components\TextInput::make('rollover_every')
                        ->label('Rollover limit')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(1000)
                        ->visible(fn (Forms\Get $get) => $get('counter_mode') === 'rollover')
                        ->required(fn (Forms\Get $get) => $get('counter_mode') === 'rollover')
                        ->helperText('Example: 3 means 4th event counts as 1 in the next cycle.'),
                    Forms\Components\Select::make('mode')
                        ->options([
                            'daily_batch' => 'Daily batch',
                            'realtime_today' => 'Realtime (today)',
                            'digest' => 'Digest',
                        ])
                        ->default('daily_batch')
                        ->required(),
                    Forms\Components\Toggle::make('reset_on_term_change')->default(true),
                    Forms\Components\Select::make('year_groups')
                        ->label('Year groups')
                        ->multiple()
                        ->options([
                            'Year 7' => 'Year 7',
                            'Year 8' => 'Year 8',
                            'Year 9' => 'Year 9',
                            'Year 10' => 'Year 10',
                            'Year 11' => 'Year 11',
                            'Year 12' => 'Year 12',
                            'Year 13' => 'Year 13',
                        ])
                        ->helperText('Optional; limits to selected year groups.'),
                    Forms\Components\Toggle::make('mute_lower_on_first_run')
                        ->label('First-run: highest-only')
                        ->helperText('On first evaluation, only log highest threshold per student.'),
                    Forms\Components\Toggle::make('digest_attach_csv')
                        ->label('Attach CSV (digest)')
                        ->visible(fn (Forms\Get $get) => $get('mode') === 'digest'),
                    Forms\Components\Select::make('digest_fields')
                        ->label('Digest fields')
                        ->multiple()
                        ->options([
                            'name' => 'Name',
                            'year_group' => 'Year Group',
                            'tutor_group' => 'Tutor Group',
                            'category' => 'Category',
                            'value' => 'Value',
                            'threshold' => 'Threshold',
                        ])
                        ->visible(fn (Forms\Get $get) => $get('mode') === 'digest'),
                    Forms\Components\Toggle::make('recipient_staff')->default(true),
                    Forms\Components\Toggle::make('recipient_parents')
                        ->default(false)
                        ->disabled(fn (Forms\Get $get) => $get('mode') === 'digest'),
                    Forms\Components\Select::make('staff_roles')
                        ->label('Staff roles')
                        ->multiple()
                        ->options([
                            'tutor' => 'Tutor',
                            'head_of_year' => 'Head of Year',
                        ]),
                    Forms\Components\TagsInput::make('extra_emails')->label('Extra staff emails'),
                    Forms\Components\Toggle::make('is_active')->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('source'),
                Tables\Columns\TagsColumn::make('categories'),
                Tables\Columns\TextColumn::make('timeframe'),
                Tables\Columns\TextColumn::make('counter_mode')->label('Counter'),
                Tables\Columns\TextColumn::make('mode'),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
                Tables\Columns\TextColumn::make('updated_at')->dateTime(),
            ])
            ->filters([
                Tables\Filters\Filter::make('active')->query(fn ($q) => $q->where('is_active', true)),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            LevelsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBehaviourRuleGroups::route('/'),
            'create' => Pages\CreateBehaviourRuleGroup::route('/create'),
            'edit' => Pages\EditBehaviourRuleGroup::route('/{record}/edit'),
        ];
    }
}
