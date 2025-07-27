<?php

namespace App\Filament\Resources\EpraiseInterventionCategoryResource\Pages;

use App\Filament\Resources\EpraiseInterventionCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEpraiseInterventionCategories extends ListRecords
{
    protected static string $resource = EpraiseInterventionCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
