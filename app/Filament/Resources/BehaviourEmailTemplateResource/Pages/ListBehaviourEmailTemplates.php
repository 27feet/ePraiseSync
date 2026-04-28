<?php

namespace App\Filament\Resources\BehaviourEmailTemplateResource\Pages;

use App\Filament\Resources\BehaviourEmailTemplateResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions;

class ListBehaviourEmailTemplates extends ListRecords
{
    protected static string $resource = BehaviourEmailTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
