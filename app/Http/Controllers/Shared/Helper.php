<?php


namespace App\Http\Controllers\Shared;

use DB;

class Helper
{
    /**
     * Random alphabetic string
     *
     * @param $table_name
     * @param $prefix
     * @param $length_of_string
     * @return string
     *
     * Will generate codes for the given table until a unique one is found
     */
    public function randomAlphabeticString($table_name, $prefix, $length_of_string): string
    {
        // String of all alphanumeric character
        $originator = 'ABCDEFGHKMNPRSTWXYZ';

        // Shuffle the originator
        $random_string = substr(str_shuffle($originator), 0, $length_of_string);
        $final_string = $prefix . $random_string;

        // Check if the result exists already
        $exists = DB::table($table_name)->where('code', $final_string)->exists();

        // If it exists, shuffle again, else return what we got
        if ($exists == true) {
            $this->randomAlphabeticString($table_name, $prefix, $length_of_string);
        }
        return $final_string;
    }

    public static function import_CSV($filename, $delimiter = ',')
    {
        if (!file_exists($filename) || !is_readable($filename))
            return false;
        $header = null;
        $data = array();
        if (($handle = fopen($filename, 'r')) !== false) {
            while (($row = fgetcsv($handle, 1000, $delimiter)) !== false) {
                if (!$header)
                    $header = $row;
                else
                    $data[] = array_combine($header, $row);
            }
            fclose($handle);
        }
        return $data;
    }


}
