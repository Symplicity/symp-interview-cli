<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class CheckEnvConfigsCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'housekeeping:check-env-configs';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Print the application\'s environment variables';

    private $runtimeErrorMessage;


    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        // Setup variables

        // Run the command
        $this->line($this->getApplication()->getName());

        $this->info('Fetching environment variables...');

        $this->table([
            'Variable',
            'Value'
        ], [
            ['WORKER_DB_HOST', env('WORKER_DB_HOST')],
            ['WORKER_DB_PORT', env('WORKER_DB_PORT')],
            ['WORKER_DB_SCHEMA', env('WORKER_DB_SCHEMA')],
            ['WORKER_DB_USER', env('WORKER_DB_USER')],
            ['WORKER_DB_PASS', env('WORKER_DB_PASS')],
            ['PUBLIC_URL', env('PUBLIC_URL')],
            ['PUBLIC_HTML_PATH', env('PUBLIC_HTML_PATH')],
            ['PUBLIC_HTML_GROUP', env('PUBLIC_HTML_GROUP')],
            ['PUBLIC_HTML_OWNER', env('PUBLIC_HTML_OWNER')],
            ['CANDIDATE_FOLDER_PERMISSION', env('CANDIDATE_FOLDER_PERMISSION')],
            ['CANDIDATE_DEFAULT_MYSQL_PASSWORD', env('CANDIDATE_DEFAULT_MYSQL_PASSWORD')],
            ['PHPMYADMIN_URL', env('PHPMYADMIN_URL')],
            ['CODE_SERVER_HOST', env('CODE_SERVER_HOST')],
            ['CODE_SERVER_PORT', env('CODE_SERVER_PORT')],
            ['CODE_SERVER_ENABLE_SSL', env('CODE_SERVER_ENABLE_SSL')],
            ['CODE_SERVER_SSL_CERT_PATH', env('CODE_SERVER_SSL_CERT_PATH')],
            ['CODE_SERVER_SSL_KEY_PATH', env('CODE_SERVER_SSL_KEY_PATH')],
            ['MYSQLDUMP_PATH', env('MYSQLDUMP_PATH')],
            ['CODING_TEST_REPO', env('CODING_TEST_REPO')]
        ]);

    }


}
