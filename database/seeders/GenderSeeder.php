<?php

namespace Database\Seeders;

use App\Http\Controllers\Shared\Helper;
use App\Models\Gender;
use Illuminate\Database\Seeder;

class GenderSeeder extends Seeder
{
    public function run()
    {
        $table = 'genders';
        $file = base_path("database/data/$table" . ".csv");

        printf("------------------------------------------");
        printf($file);

        $records = Helper::import_CSV($file);

        foreach ($records as $key => $record) {
            Gender::updateOrCreate(
                ['name' => $record['name']],  // columns to search for
                ['id' => $record['id'], 'name' => $record['name'], 'status' => $record['status']]  // data to update or insert
            );
        }
    }

}
