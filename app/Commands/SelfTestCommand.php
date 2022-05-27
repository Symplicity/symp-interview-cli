<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use Illuminate\Support\Facades\DB;

class SelfTestCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'housekeeping:self-test';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Application self-test';

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

        $this->info('Starting self-test');

        if($this->task('Testing MySQL Connection...', function () {
            return $this->mysqlTest();
        }) === false){
            $this->displayError($this->runtimeErrorMessage);
        }
        if($this->task('Testing MySQL Connection...', function () {
            return $this->mysqlTest();
        }) === false){
            $this->displayError($this->runtimeErrorMessage);
        }

    }

    private function mysqlTest(){

        // Check if the app can connect to MySQL
        $qryStatus = DB::select('SHOW STATUS');
        if(!$qryStatus){
            $this->runtimeErrorMessage = 'Could not connect to MySQL';
            return false;
        }

        // Check if the database exists
        $qryCheckDb = DB::select('SELECT * FROM migrations');
        if(!$qryCheckDb){
            $this->runtimeErrorMessage = 'The master database doesn\'t exist. Maybe the app is not installed?';
            return false;
        }
        
        // TODO: More Checks
        return true;
    }

    private function checkDirectories()
    {
        
    }
}
