<?php

namespace App\Filament\Resources\BehaviourRuleGroupResource\RelationManagers;

use App\Models\BehaviourEmailTemplate;
use Filament\Forms;
use Filament\Forms\Set;
use Filament\Tables;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Forms\Form;
use Filament\Tables\Table;

class LevelsRelationManager extends RelationManager
{
    protected static string $relationship = 'levels';
    protected static ?string $recordTitleAttribute = 'threshold';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('threshold')->numeric()->required(),
            Forms\Components\Select::make('template_key')
                ->label('Template')
                ->options(fn () => BehaviourEmailTemplate::query()->pluck('key', 'key'))
                ->searchable()
                ->required(),
            Forms\Components\Actions::make([
                Forms\Components\Actions\Action::make('newTemplate')
                    ->label('Create new template')
                    ->slideOver()
                    ->form([
                        Forms\Components\TextInput::make('key')
                            ->required()
                            ->maxLength(255)
                            ->rule('unique:behaviour_email_templates,key'),
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
                            ->required(),
                        Forms\Components\Fieldset::make('merge_fields')
                            ->label('Merge fields')
                            ->schema([
                                Forms\Components\Select::make('merge_field')
                                    ->options($this->mergeFieldOptions())
                                    ->placeholder('Select a merge field')
                                    ->reactive(),
                                Forms\Components\Actions::make([
                                    Forms\Components\Actions\Action::make('insert_merge')
                                        ->label('Insert into body')
                                        ->action(function (Forms\Get $get, Set $set) {
                                            $field = $get('merge_field');
                                            if (!$field) return;
                                            $body = (string) $get('body_html');
                                            $set('body_html', $body . ' {{ ' . $field . ' }}');
                                        }),
                                ])->columnSpan(1),
                            ])
                            ->columns(2)
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('body_text')
                            ->label('Body (text)')
                            ->rows(6)
                            ->columnSpanFull(),
                        Forms\Components\Fieldset::make('merge_fields_text')
                            ->label('Merge fields (text)')
                            ->schema([
                                Forms\Components\Select::make('merge_field_text')
                                    ->options($this->mergeFieldOptions())
                                    ->placeholder('Select a merge field')
                                    ->reactive(),
                                Forms\Components\Actions::make([
                                    Forms\Components\Actions\Action::make('insert_merge_text')
                                        ->label('Insert into body text')
                                        ->action(function (Forms\Get $get, Set $set) {
                                            $field = $get('merge_field_text');
                                            if (!$field) return;
                                            $body = (string) $get('body_text');
                                            $set('body_text', $body . ' {{ ' . $field . ' }}');
                                        }),
                                ])->columnSpan(1),
                            ])
                            ->columns(2)
                            ->columnSpanFull(),
                        Forms\Components\Toggle::make('is_active')
                            ->default(true),
                    ])
                    ->action(function (array $data, Set $set): void {
                        $template = BehaviourEmailTemplate::create([
                            'key' => $data['key'],
                            'subject' => $data['subject'],
                            'body_html' => $data['body_html'],
                            'body_text' => $data['body_text'] ?? null,
                            'is_active' => (bool) ($data['is_active'] ?? true),
                        ]);
                        $set('template_key', $template->key);
                    }),
            ])->columnSpanFull(),
            Forms\Components\Toggle::make('recipient_staff')->default(true),
            Forms\Components\Toggle::make('recipient_parents')->default(false)
                ->helperText('Digest mode groups should keep this off.'),
            Forms\Components\Select::make('staff_roles')
                ->label('Staff roles')
                ->multiple()
                ->options([
                    'tutor' => 'Tutor',
                    'head_of_year' => 'Head of Year',
                ]),
            Forms\Components\TagsInput::make('extra_emails')->label('Extra staff emails'),
            Forms\Components\Toggle::make('is_active')->default(true),
            Forms\Components\TextInput::make('sort_order')->numeric()->default(0),
        ]);
    }

    private function mergeFieldOptions(): array
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

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('threshold')->sortable(),
                Tables\Columns\TextColumn::make('template_key'),
                Tables\Columns\IconColumn::make('recipient_staff')->boolean(),
                Tables\Columns\IconColumn::make('recipient_parents')->boolean(),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
                Tables\Columns\TextColumn::make('sort_order'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
