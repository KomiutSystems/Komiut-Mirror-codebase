<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

class CreateMonthlyTransactionTables extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:create-monthly-transaction-tables';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Get the current month and year
        $currentMonth = now()->format('Y_m');

        // List of main tables
        $tables = ['transactions', 'mpesas', 'cashes'];

        foreach ($tables as $table) {
            $newTableName = $table . '_' . $currentMonth;

            // Check if the table already exists (to avoid duplicates)
            if (!Schema::hasTable($newTableName)) {
               // Get the SQL to create the original table
               $originalTableSQL = DB::select("SHOW CREATE TABLE $table")[0]->{'Create Table'};

               // Replace the original table name with the new table name
               $newTableSQL = str_replace("CREATE TABLE `$table`", "CREATE TABLE `$newTableName`", $originalTableSQL);

               // Execute the SQL to create the new table
               DB::statement($newTableSQL);
                $this->info("Created table: $newTableName");
            } else {
                $this->info("Table $newTableName already exists.");
            }
        }
        return 0;
    }
}
