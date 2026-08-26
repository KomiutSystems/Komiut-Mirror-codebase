<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for all database work. Of course
    | you may use many connections at once using the Database library.
    |
    */

    'default' => env('DB_CONNECTION', 'mysql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Here are each of the database connections setup for your application.
    | Of course, examples of configuring each database platform that is
    | supported by Laravel is shown below to make development simple.
    |
    |
    | All database work in Laravel is done through the PHP PDO facilities
    | so make sure you have the driver for your particular database of
    | choice installed on your machine before you begin development.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DATABASE_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DATABASE_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            //specify master (write) and multiple slaves (read)
            'write'=> [
                'host'=>env("DB_HOST")
            ],
            'read'=>[
                'host'=>[
                   // env("DB_HOST_SLAVE_1"),
                    env("DB_HOST_SLAVE_2")
                ],
            ],
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                // PHP 8.5 moved the driver-specific constants onto Pdo\Mysql and
                // deprecated the PDO::MYSQL_* aliases, which become errors in 9.0.
                // The ternary short-circuits, so the deprecated constant is never
                // evaluated on 8.5+, and Pdo\Mysql is never referenced below it.
                (class_exists(\Pdo\Mysql::class)
                    ? \Pdo\Mysql::ATTR_SSL_CA
                    : \PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
                \PDO::ATTR_TIMEOUT => 60,
            ]) : [],

        ],

        /*
        |----------------------------------------------------------------------
        | legacy_mysql — a READ-ONLY window onto the old komiut_latest_app
        |----------------------------------------------------------------------
        |
        | One caller: `payments:reconcile-legacy`, which answers the only
        | question that makes this migration measurable — is THIS system missing
        | a payment the legacy system has? Nothing may ever write through it.
        | App\Services\Super\Money\MysqlLegacyPaymentSource is the sole consumer
        | and it issues SELECTs and nothing else.
        |
        | DELIBERATELY NO DEFAULTS for host / username / password. Every other
        | connection in this file falls back to 127.0.0.1 + forge. Those defaults
        | on THIS connection would be actively dangerous: the reconciler would
        | quietly point at whatever database happens to be local, find the same
        | rows on both sides, and report a perfectly reconciled zero deficit
        | forever. A check that cannot fail is worse than no check, because it
        | also stops anyone from looking. Unset therefore means null, and the
        | command aborts on a null host instead of connecting to something it was
        | not told to connect to.
        |
        | THE DATABASE IS komiut_latest_app, NOT komiut_payments. Roughly 900
        | payments a day on shortcodes 880100 / 6624890 / 6624891 never transit
        | payments server 2, so a check aimed at komiut_payments reports green
        | while permanently blind to that whole class. Measured read-only on
        | 2026-08-26: in the 08:00-09:00 EAT hour those three shortcodes carried
        | 35 of the 76 payments this system was missing — 46% of the count and
        | KES 4,140 of the KES 7,050.
        |
        | Grant the MySQL user SELECT on this database and nothing else. The
        | application-level guard above is real, but it is one careless edit away
        | from being wrong; a SELECT-only grant is enforced by the server and is
        | not.
        */
        'legacy_mysql' => [
            'driver' => 'mysql',
            'host' => env('LEGACY_DB_HOST'),
            'port' => env('LEGACY_DB_PORT', '3306'),
            'database' => env('LEGACY_DB_DATABASE', 'komiut_latest_app'),
            'username' => env('LEGACY_DB_USERNAME'),
            'password' => env('LEGACY_DB_PASSWORD'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                // Same PHP 8.5 short-circuit as the connection above.
                (class_exists(\Pdo\Mysql::class)
                    ? \Pdo\Mysql::ATTR_SSL_CA
                    : \PDO::MYSQL_ATTR_SSL_CA) => env('LEGACY_MYSQL_ATTR_SSL_CA'),
                // Much shorter than the 60s above: this is a cross-region hop on
                // a scheduled task. A legacy box that has gone away must fail the
                // run quickly and loudly rather than hold a scheduler slot open.
                \PDO::ATTR_TIMEOUT => 10,
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DATABASE_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DATABASE_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run in the database.
    |
    */

    'migrations' => 'migrations',

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as APC or Memcached. Laravel makes it easy to dig right in.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_database_'),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],

    ],

];
