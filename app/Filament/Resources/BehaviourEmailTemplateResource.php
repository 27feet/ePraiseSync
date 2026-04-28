<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BehaviourEmailTemplateResource\Pages;
use App\Models\BehaviourEmailTemplate;
use App\Models\BehaviourNotificationRule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BehaviourEmailTemplateResource extends Resource
{
    protected static ?string $model = BehaviourEmailTemplate::class;
    protected static ?string $navigationIcon = 'heroicon-o-envelope';
    protected static ?string $navigationLabel = 'Behaviour Templates';
    protected static ?string $navigationGroup = 'Maintenance';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('key')
                ->required()
                ->maxLength(255)
                ->helperText('Changing key will break rules pointing to the old key.'),
            Forms\Components\TextInput::make('subject')
                ->required()
                ->maxLength(255),
            Forms\Components\RichEditor::make('body_html')
                ->label('Body (HTML)')
                ->columnSpanFull()
                ->toolbarButtons([
                    'bold', 'italic', 'underline', 'strike',
                    'bulletList', 'orderedList', 'link', 'undo', 'redo',
                ])
                ->helperText('Use the merge-field inserter below to add tokens.')
                ->required(),
            Forms\Components\Fieldset::make('merge_fields')
                ->label('Merge fields')
                ->schema([
                    Forms\Components\Select::make('merge_field')
                        ->options(self::mergeFieldOptions())
                        ->placeholder('Select a merge field')
                        ->reactive(),
                    Forms\Components\Actions::make([
                        Forms\Components\Actions\Action::make('insert_merge')
                            ->label('Insert into body')
                            ->action(function (Forms\Get $get, Forms\Set $set) {
                                $field = $get('merge_field');
                                if (!$field) return;
                                $body = (string) $get('body_html');
                                $set('body_html', $body . ' {{ ' . $field . ' }}');
                            })
                    ])->columnSpan(1),
                ])
                ->columns(2)
                ->columnSpanFull(),
            Forms\Components\Toggle::make('is_active')
                ->default(true),
            Forms\Components\Placeholder::make('used_by_rules')
                ->label('Rules using this template')
                ->content(function (?BehaviourEmailTemplate $record) {
                    if (!$record) return 'New template';
                    $rules = BehaviourNotificationRule::where('template_key', $record->key)->pluck('category', 'id')->toArray();
                    if (empty($rules)) return 'No rules currently reference this template.';
                    $list = collect($rules)->map(fn ($cat, $id) => "#{$id}: {$cat}")->implode(', ');
                    return $list;
                })
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('key')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('subject')->limit(50)->wrap(),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
                Tables\Columns\TextColumn::make('updated_at')->dateTime(),
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBehaviourEmailTemplates::route('/'),
            'create' => Pages\CreateBehaviourEmailTemplate::route('/create'),
            'edit' => Pages\EditBehaviourEmailTemplate::route('/{record}/edit'),
        ];
    }

    private static function mergeFieldOptions(): array
    {
        return [
            'student_name' => 'student_name',
            'category' => 'category',
            'value' => 'value',
            'raw_value' => 'raw_value',
            'cycle_value' => 'cycle_value',
            'cycle_index' => 'cycle_index',
            'rollover_every' => 'rollover_every',
            'counter_mode' => 'counter_mode',
            'threshold' => 'threshold',
            'window_start' => 'window_start',
            'window_end' => 'window_end',
            'staff_or_parent' => 'staff_or_parent',
            'as_of' => 'as_of',
        ];
    }
}
