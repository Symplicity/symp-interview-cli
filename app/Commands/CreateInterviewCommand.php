<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateInterviewCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'interview:create {name : The candidate name (required)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Create a new interview instance for a candidate';

    private $candidateName;
    private $databaseInfo = [
        'host' => 'localhost',
        'database' => '',
        'username' => '',
        'password' => '',
        'port' => '3306',
    ];

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->line($this->getApplication()->getName());

        $candidateName = $this->argument('name');
        $candidateName = str_replace(' ', '_', $candidateName);
        $this->info('Creating interview environment for ' . $candidateName);
        $this->candidateName = $candidateName;

        if ($this->task('Checking if interview workspace is writeable...', function () {
            if (!is_writeable('/var/www/interviewWorkspace')) {
                return false;
            }
        }, 'checking') === false) {
            $this->displayError('The /var/www/interviewWorkspace is not writeable.');
        }

        if ($this->task('Checking if the name is not already used', function () {
            return $this->checkCandidateDirExists();
        }) == false) {
            $this->displayError('There\'s another candidate with the same name with an ongoing interview.');
        }

        if($this->task('Creating directory...', function () {
            return $this->createCandidateDirectory();
        }) === false){
            $this->displayError('There was an error creating the candidate directory.');
        }

        if($this->task('Creating database...', function () {
            return $this->createCandidateDatabase();
        }) === false){
            $this->displayError('There was an error creating the candidate database.');
        }

        if($this->task('Creating database user...', function () {
            return $this->createCandidateDatabaseUser();
        }) === false){
            $this->displayError('There was an error creating the candidate database credentials.');
        }

        if($this->task('Populating test folder with Skeleton files...', function () {
            return $this->populateCandidateFolder();
        }) === false){
            $this->displayError('There was an error populating the candidate folder.');
        }

        // TODO: Create user to allow candidate to access using Code Server

        $this->info('Environment created successfully. All the information the candidate will need is in a file called INSTRUCTIONS.MD into the directory.');
    }

    private function createCandidateDirectory()
    {
        return Storage::disk('www_dir')->makeDirectory($this->candidateName);
        //return true; // DEBUG
    }

    private function checkCandidateDirExists()
    {
        $dirs = Storage::disk('www_dir')->directories();
        if(in_array($this->candidateName, $dirs)){
            return false;
        }else{
            return true;
        }
        //return true; // DEBUG
    }

    private function displayError($errorMessage, $halt = true)
    {
        $this->newLine(2);
        $this->error($errorMessage);
        $this->newLine(2);
        if ($halt == true) {
            die();
        }
    }

    private function createCandidateDatabase()
    {
        // Check if the database exists
        $dbName = $this->candidateName . '_interview';
        $this->databaseInfo['database'] = $dbName;
        $dbExists = DB::connection('mysql')->select('SHOW DATABASES LIKE "?"', [$dbName]);
        if (count($dbExists) > 0) {
            return false;
            //return true; // DEBUG
        }
        $response = DB::connection('mysql')->statement("CREATE DATABASE IF NOT EXISTS $dbName");
        return $response;
        //return true; // DEBUG
    }

    private function createCandidateDatabaseUser()
    {
        // Check if the database user exists
        $dbUser = $this->candidateName . '_interview';
        $dbPassword = 'SympTest@123';
        $dbName = $this->databaseInfo['database'];

        $this->databaseInfo['username'] = $dbUser;
        $this->databaseInfo['password'] = $dbPassword;

        $dbUserExists = DB::connection('mysql')->select('SELECT * FROM mysql.user WHERE user = ?', [$dbUser]);
        if (count($dbUserExists) > 0) {
            return false;
            //return true; // DEBUG
        }
        $createUserQuery = DB::connection('mysql')->statement("CREATE USER '$dbUser'@'localhost' IDENTIFIED BY '$dbUser'");
        $grantUserQuery = DB::connection('mysql')->statement("GRANT ALL PRIVILEGES ON $dbName.* TO '$dbUser'@'localhost'");
        $setPasswordQuery = DB::connection('mysql')->statement("ALTER USER '$dbUser'@'localhost' IDENTIFIED BY '$dbPassword'");
    

        if($createUserQuery && $grantUserQuery && $setPasswordQuery){
            return true;
        }else{
            return false;
        }
    }

    private function populateCandidateFolder()
    {
        $destination = '/var/www/interviewWorkspace/' . $this->candidateName;

        $copyResponse = File::copyDirectory('storage/candidateFolderStub', $destination, true);

        // Replace the database info in the instruction file
        $instructionStub = $destination . '/INSTRUCTIONS.md';
        $content = File::get($instructionStub);
        $search = [
            '{{dbHost}}',
            '{{dbUsername}}',
            '{{dbPassword}}',
            '{{dbName}}',
            '{{dbPort}}',
            '{{pmaUrl}}',
            '{{baseUrl}}',
        ];
        $replace = [
            $this->databaseInfo['host'],
            $this->databaseInfo['username'],
            $this->databaseInfo['password'],
            $this->databaseInfo['database'],
            $this->databaseInfo['port'],
            'http://' . $this->candidateName . '.interview.giovanne.dev/phpmyadmin/',
            'http://' . $this->candidateName . '.interview.giovanne.dev/',
        ];

        $writeFileResponse = File::put(
            $instructionStub,
            Str::replace(
                $search,
                $replace,
                $content
            )
        );

        if($copyResponse && $writeFileResponse){
            return true;
        }else{
            return false;
        }
    }
}
