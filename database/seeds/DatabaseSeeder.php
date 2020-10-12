<?php

use Database\TruncateTable;
use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;

class DatabaseSeeder extends Seeder
{
    use TruncateTable;

    /**
     * Seed the application's database.
     */
    public function run()
    {
        Model::unguard();

        $this->truncateMultiple([
            'cache',
            'failed_jobs',
            'ledgers',
            'jobs',
            'sessions',
        ]);

        $this->call(AuthTableSeeder::class);
        $this->call(PagesTableSeeder::class);
        $this->call(FaqTableSeeder::class);
        $this->call(EmailTemplateSeeder::class);

        Model::reguard();
    }
}
