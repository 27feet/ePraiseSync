<?php

namespace App\Filament\Resources\EpraiseInterventionCategoryResource\Pages;

use App\Filament\Resources\EpraiseInterventionCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditEpraiseInterventionCategory extends EditRecord
{
    protected static string $resource = EpraiseInterventionCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
