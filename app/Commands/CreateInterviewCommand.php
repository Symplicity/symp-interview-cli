<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Origin\Filesystem\Folder as OriginDirFS;
use Cocur\BackgroundProcess\BackgroundProcess;
use App\Libraries\Lock;
use Symfony\Component\Process\Process;

class CreateInterviewCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'init {name : The candidate name (required)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Create a new interview instance for a candidate';

    private $runtimeErrorMessage;
    private $candidateName;
    private $databaseInfo = [
        'host' => 'localhost',
        'database' => '',
        'username' => '',
        'password' => '',
        'port' => '3306',
    ];

    private $codeServerPassword;
    private $codeServerHost;
    private $codeServerPort = 8090;
    private $codeServerSSL = false;

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        // Setup variables

        $this->codeServerHost = env('CODE_SERVER_HOST');
        $this->codeServerPort = env('CODE_SERVER_PORT');

        // Run the command
        $this->line($this->getApplication()->getName());

        $candidateName = $this->argument('name');
        $candidateName = str_replace(' ', '_', $candidateName);
        if(!$candidateName || empty($candidateName)){
            $candidateName = $this->ask('Please enter the candidate name');
        }
        
        $this->info('Creating interview environment for ' . $candidateName);
        $this->candidateName = $candidateName;

        if ($this->task('Checking if interview workspace is writeable...', function () {
            if (!is_writeable(env('PUBLIC_HTML_PATH', '/var/www/html'))) {
                return false;
            }
        }, 'checking') === false) {
            $this->displayError('The '.env('PUBLIC_HTML_PATH', '/var/www/html').' is not writeable.');
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

        if($this->task('Cloning test repository...', function () {
            return $this->cloneRepository();
        }) === false){
            $this->displayError('There was an error while cloning the test repository.');
        }

        if($this->task('Populating test folder with Skeleton files...', function () {
            return $this->populateCandidateFolder();
        }) === false){
            $this->displayError('There was an error populating the candidate folder.');
        }

        if($this->task('Fixing directory\'s permission...', function () {
            return $this->fixCandidateDirectoryPermissions();
        }) === false){
            $this->displayError('There was an error fixing the candidate directory\'s permissions.');
        }

        if($this->task('Initializing Visual Studio Code Server...', function () {
            return $this->launchCodeServerInstance();
        }) === false){
            $this->displayError('There was an error fixing the candidate directory\'s permissions.');
        }

        if($this->task('Running post-action hooks...', function () {
            return $this->postActionHooks();
        }) === false){
            $this->displayError('There was an error fixing the candidate directory\'s permissions.');
        }

        $this->info('Environment created successfully. All the information the candidate will need is in a file called INSTRUCTIONS.MD into the directory.');
        $this->newLine(2);
        $this->line('Everything is set to go. Your candidate must open the browser and paste the Live Coding URL provided below.');
        $this->line('Then, the candidate must type the password also provided below, to have access to the Live Coding environment.');
        $this->line('Once with vscode open in the browser, the candidate will find a INSTRUCTIONS.md file in the root of the project.');
        $this->line('This file contains all the information needed to run the interview, including database credentials, Base URL and PHPMyAdmin URL.');
        $this->newLine(2);
        $protocol = $this->codeServerSSL ? 'https://' : 'http://';
        $this->info("Live Coding URL: ". $protocol . $this->codeServerHost . ":" . $this->codeServerPort);
        $this->info("Password: ".$this->codeServerPassword);
    }


    private function postActionHooks()
    {
        // Create a lock
        Lock::lock('interview', $this->candidateName);
    }

    private function createCandidateDirectory()
    {
        return Storage::disk('public_html')->makeDirectory($this->candidateName);
        //return true; // DEBUG
    }

    private function fixCandidateDirectoryPermissions()
    {
        
        $chownResponse = OriginDirFS::chgrp(env('PUBLIC_HTML_PATH', '/var/www/html'). '/' . $this->candidateName, env('PUBLIC_HTML_GROUP', 'www-data'));
        $chmodResponse = OriginDirFS::chmod(env('PUBLIC_HTML_PATH', '/var/www/html'). '/' . $this->candidateName, env('CANDIDATE_FOLDER_PERMISSION', 0755));      

        $chmodProcess = new Process([
            'chmod',
            '-R',
            env('CANDIDATE_FOLDER_PERMISSION', 0755),
            env('PUBLIC_HTML_PATH', '/var/www/html'). '/' . $this->candidateName
        ]);

        $chownProcess = new Process([
            'chown',
            '-R',
            env('PUBLIC_HTML_USER', 'www-data'),
            env('PUBLIC_HTML_PATH', '/var/www/html'). '/' . $this->candidateName
        ]);

        $chownProcess->start();
        $chmodProcess->start();

        // TODO: Check if the process was successfull.
        /*if (!$chownProcess->isSuccessful() || !$chmodProcess->isSuccessful()) {
            return false;
        }else{
            return true;
        }*/
        return true;
    }

    private function checkCandidateDirExists()
    {
        $dirs = Storage::disk('public_html')->directories();
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
        $dbPassword = env('CANDIDATE_DEFAULT_MYSQL_PASSWORD', 'SympTest@123');
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
        $destination = env('PUBLIC_HTML_PATH', '/var/www/html') . '/' . $this->candidateName;

        $copyResponse = File::copyDirectory(storage_path('candidateFolderStub'), $destination, true);

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
            env('PUBLIC_URL').$this->candidateName,
            env('PHPMYADMIN_URL'),
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

    private function launchCodeServerInstance()
    {
        $this->codeServerPassword = 'SympInterview@'.rand(1000, 9999);

        $command = 'export PASSWORD='.$this->codeServerPassword.';';
        $command .= 'code-server';
        $command .= ' /var/www/html/'.$this->candidateName;
        $command .= ' --auth=password';
        $command .= ' --bind-addr 0.0.0.0:'.env('CODE_SERVER_PORT', '8090');

        if(env('CODE_SERVER_ENABLE_SSL', false) && env('CODE_SERVER_SSL_CERT_PATH', '') != ''){
            // SSL enabled for code server
            $command .= ' --cert='.env('CODE_SERVER_SSL_CERT_PATH'); 
            $command .= ' --cert-key='.env('CODE_SERVER_SSL_KEY_PATH'); 
            $this->codeServerSSL = true;
        }
        $process = new BackgroundProcess($command);
        $process->run();
        $pid = $process->getPid();
        if($process->isRunning()){
            return DB::insert('INSERT INTO code_server_instances (candidate_name, pid, password, status, started_at) VALUES (?, ?, ?, 1, NOW())', [$this->candidateName, $pid, $this->codeServerPassword]);
        }else{
            return false;
        }
    }

    private function cloneRepository()
    {
        if(env('CODING_TEST_REPO') == ''){
            return false;
        }
        $Process = new Process([
            'git',
            'clone',
            env('CODING_TEST_REPO'),
            './'
        ]);

        $Process->setWorkingDirectory(env('PUBLIC_HTML_PATH', '/var/www/html') . '/' . $this->candidateName);
        $Process->run();
        if($Process->isSuccessful()){
            return true;
        }else{
            return false;
        }
    }
}
