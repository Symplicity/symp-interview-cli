<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Cocur\BackgroundProcess\BackgroundProcess;

class TestCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'testCode {name : The candidate name (required)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Create a new interview instance for a candidate';

    private $candidateName;
    private $codeServerPassword;

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $codeServerPassword = 'SympInterview@'.rand(1000, 9999);

        $command = 'export PASSWORD='.$codeServerPassword.';';
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
        var_dump($command);
        $process = new BackgroundProcess($command);
        $process->run();
        $pid = $process->getPid();
        if($process->isRunning()){
            echo 'OK!';
        }else{
            echo 'FAILED!';
        }
    }

}
