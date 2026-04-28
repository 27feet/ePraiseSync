<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use App\Models\ArborPointCategory;
use App\Models\EpraisePointCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\EpraisePointCategoryResource\Pages;
use App\Filament\Resources\EpraisePointCategoryResource\RelationManagers;

class EpraisePointCategoryResource extends Resource
{
    protected static ?string $model = EpraisePointCategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationGroup = 'Maintenance';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('epraise_name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('arbor_point_category_id')
                    ->options(ArborPointCategory::all()->pluck('arbor_display_name', 'id'))
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
            'index' => Pages\ListEpraisePointCategories::route('/'),
            'create' => Pages\CreateEpraisePointCategory::route('/create'),
            'edit' => Pages\EditEpraisePointCategory::route('/{record}/edit'),
        ];
    }
}
