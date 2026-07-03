<?php

namespace Database\Seeders;

use App\Models\ExportTemplate;
use App\Services\Export\TemplateDefinitions;
use Illuminate\Database\Seeder;

class MappingSeeder extends Seeder
{
    public function run()
    {
        foreach (TemplateDefinitions::all() as $name => $definition) {
            ExportTemplate::updateOrCreate(['name' => $name], ['definition' => $definition]);
        }
    }
}
