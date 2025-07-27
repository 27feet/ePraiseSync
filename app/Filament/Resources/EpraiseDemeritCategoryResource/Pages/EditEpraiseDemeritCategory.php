<?php

namespace App\Filament\Resources\EpraiseDemeritCategoryResource\Pages;

use App\Filament\Resources\EpraiseDemeritCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditEpraiseDemeritCategory extends EditRecord
{
    protected static string $resource = EpraiseDemeritCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
