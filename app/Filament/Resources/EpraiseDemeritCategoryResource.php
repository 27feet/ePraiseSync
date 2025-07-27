<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use App\Models\ArborPointCategory;
use App\Models\EpraiseDemeritCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\EpraiseDemeritCategoryResource\Pages;
use App\Filament\Resources\EpraiseDemeritCategoryResource\RelationManagers;
use App\Models\ArborBehaviourCategory;

class EpraiseDemeritCategoryResource extends Resource
{
    protected static ?string $model = EpraiseDemeritCategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('epraise_name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('arbor_point_category_id')
                    ->numeric()
                    ->default(null),
                Forms\Components\TextInput::make('arbor_behaviour_category_id')
                    ->numeric()
                    ->default(null),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('epraise_name')
                    ->searchable(),
                Tables\Columns\SelectColumn::make('arbor_point_category_id')
                    ->label('Arbor Point Category')
                    ->options(ArborPointCategory::all()->pluck('arbor_display_name', 'id')->sort())
                    ->sortable(),
                Tables\Columns\SelectColumn::make('arbor_behaviour_category_id')
                    ->label('Arbor Behaviour Category')
                    ->options(ArborBehaviourCategory::all()->pluck('arbor_display_name', 'id')->sort())
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEpraiseDemeritCategories::route('/'),
            'create' => Pages\CreateEpraiseDemeritCategory::route('/create'),
            'edit' => Pages\EditEpraiseDemeritCategory::route('/{record}/edit'),
        ];
    }
}
