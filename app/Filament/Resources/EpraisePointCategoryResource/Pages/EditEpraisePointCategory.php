<?php

namespace App\Filament\Resources\EpraisePointCategoryResource\Pages;

use App\Filament\Resources\EpraisePointCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditEpraisePointCategory extends EditRecord
{
    protected static string $resource = EpraisePointCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
