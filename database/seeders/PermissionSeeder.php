<?php

namespace Database\Seeders;

use App\Http\Controllers\Shared\Helper;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $table = 'permissions';
        $file = base_path("database/data/$table" . ".csv");
        $records = Helper::import_CSV($file);

        $role = Role::where('name', 'Super Admin')->first();
        if($role == null){
            $role = Role::create(['name' => 'User', ]);
            $role = Role::create(['name' => 'Super Admin']);
        }

        foreach ($records as $key => $record) {
            $permission = Permission::where('name', $record['name'])->first();
            if( $permission == null){
                $permission = new Permission;
            }
            $permission->name = $record['name'];
            if($permission->save()){
                $role->givePermissionTo($permission);
            }
        }

    }
}
