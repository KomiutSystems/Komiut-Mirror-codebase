<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
      $this->call([
          GenderSeeder::class,
          PermissionSeeder::class,
          RoleSeeder::class,
          QueueStatusSeeder::class,
          // Without these two every driver WRITE path returns 400: no terminus
          // means no queue, and therefore no trip and no location broadcast;
          // no expense type means the expense form has an empty picker and
          // rejects every submission. Both are idempotent.
          TerminusSeeder::class,
          ExpenseFeeSeeder::class,
          UserSeeder::class
      ]);
    }
}
