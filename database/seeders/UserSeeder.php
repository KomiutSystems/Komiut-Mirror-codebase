<?php

namespace Database\Seeders;

use App\Http\Controllers\Shared\Helper;
use App\Models\Gender;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class UserSeeder extends Seeder
{
    public function run()
    {
        $table = 'users';
        $file = base_path("database/data/$table" . ".csv");
        $records = Helper::import_CSV($file);

        $role = Role::where('name', 'Super Admin')->first();
        if($role == null){
            $role = Role::create(['name' => 'User']);
            $role = Role::create(['name' => 'Super Admin']);
        }

        foreach ($records as $key => $record) {
           $user = User::updateOrCreate(
                ['email' => $record['email']],  // columns to search for
                [   // data to update or insert
                    'firstname' => $record['firstname'],
                    'lastname'=> $record['lastname'],
                    'phone'=> $record['phone'],
                    'email'=> $record['email'],
                    'dob'=> $record['dob'],
                    'password'=> $record['password'],
                    'gender_id'=> $record['gender_id'],
                    'sacco_id'=> $record['sacco_id'] > 0?$record['sacco_id']:null,
                    'status'=> $record['status'],
                ]
            );
            $user->assignRole($role);
        }
    }

}
