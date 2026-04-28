<?php

namespace App\Filament\Resources\BehaviourRuleGroupResource\Pages;

use App\Filament\Resources\BehaviourRuleGroupResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions;

class ListBehaviourRuleGroups extends ListRecords
{
    protected static string $resource = BehaviourRuleGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
