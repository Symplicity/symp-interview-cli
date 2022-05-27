<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Libraries\Lock;
use Symfony\Component\Process\Process;
use PhpZip\ZipFile;
use Cocur\BackgroundProcess\BackgroundProcess;


class CloseInterviewCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'close {name : The candidate name (required)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Close an interview instance for a candidate';

    private $candidateName;

    private $removeCandidateData = false;

    private $backupCandidateFiles = true;

    private $hireRecommendation;

    private $indicatedPosition;

    private $error;

    private $mysqldumpPath = '/usr/bin/mysqldump';

    private $interviewId;

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        // Setup variables
        $this->mysqldumpPath = env('MYSQLDUMP_PATH', $this->mysqldumpPath);

        // Run the app

        $this->line($this->getApplication()->getName());
        $this->candidateName = str_replace(' ', '_', $this->argument('name'));

        if ($this->task('Running pre-checks...', function () {

            // Check if there's a interview ongoing
            if (!Lock::isLocked('interview')) {
                $this->error('No open interviews found.');
                return false;
            }

            // Check if the candidate is the lock holder
            if (Lock::getLockContent('interview') != $this->candidateName) {
                $this->error('There\'s an ongoing interview with another candidate.');
                return false;
            }

            // Check if there's an open session on code-server
            $qryCodeSession = DB::table('code_server_instances')->where('status', 1)->get();

            if (count($qryCodeSession) < 1) {
                $this->error('No open sessions found.');
                return false;
            }

            // Check if the candidate is the session holder on code-server
            if ($qryCodeSession[0]->candidate_name != $this->candidateName) {
                $this->error('There\'s an open session with another candidate.');
                return false;
            }

            // Good to go
            return true;
        }) === false) {
            $this->displayError($this->error);
        }

        $this->backupCandidateFiles = $this->confirm('Do you want to backup the candidate files and database?', true);
        $this->removeCandidateData = $this->confirm('Do you want to delete the candidate files and database?', true);

        $this->completionSteps = $this->menu('How many steps the candidate completed before the end of the test?', [
            '1 step', 
            '2 steps',
            '3 steps',
            '4 steps',
            '5 steps',
        ])->disableDefaultItems()->open();

        $this->hireRecomendation = $this->menu('What\'s your recommendation for this candidate?', ['strong no hire', 'not recommended', 'recommended', 'strong hire'], 'strong hire')->disableDefaultItems()->open();

        $this->indicatedPosition = $this->menu('What\'s the indicated position for this candidate?', ['junior', 'mid', 'senior', 'lead', 'other'], 'other')->disableDefaultItems()->open();


        $this->info('Closing interview environment for ' . $this->candidateName);

        // Close the session on code-server
        if ($this->task('Closing code-server session...', function () {
            return $this->closeCodeServerSession();
        }) === false) {
            $this->displayError($this->error);
        };

        if ($this->backupCandidateFiles) {
            if ($this->task('Dumping candidate\'s database...', function () {
                return $this->dumpCandidateDatabase();
            }) === false) {
                $this->displayError('Error while backing up candidate\'s database');
            }

            if ($this->task('Packing candidate\'s files...', function () {
                return $this->compressCandidateFolder();
            }) === false) {
                $this->displayError('Error while backing up candidate\'s files');
            }
        }

        if ($this->removeCandidateData) {
            if ($this->task('Dropping candidate\'s database...', function () {
                return $this->dropCandidateDatabase();
            }) === false) {
                $this->displayError('Error while removing candidate\'s database', false);
            }


            if ($this->task('Removing candidate\'s database user...', function () {
                return $this->dropCandidateDbUser();
            }) === false) {
                $this->displayError('Error while removing candidate\'s database', false);
            }


            if ($this->task('Removing candidate\'s files...', function () {
                return $this->deleteCandidateFolder();
            }) === false) {
                $this->displayError('Error while removing candidate\'s files');
            }
        }

        if ($this->task('Clearing locks...', function () {
            return Lock::unlock('interview');
        }) === false) {
            $this->displayError('Error while removing candidate\'s database');
        }

        if ($this->task('Running post-action hooks...', function () {
            return $this->postActionHooks();
        }) === false) {
            $this->displayError('Error while removing candidate\'s database');
        }

        $this->newLine(2);
        $this->info('Interview closed successfully.');
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

    private function candidateHasDatabase()
    {
        $dbName = $this->candidateName . '_interview';
        $this->databaseInfo['database'] = $dbName;
        if (count(DB::connection('mysql')->select('SHOW DATABASES LIKE "?"', [$dbName])) > 0) {
            return false;
            //return true; // DEBUG
        }
        return true;
    }

    private function dumpCandidateDatabase()
    {
        $fileName = 'database_' . $this->candidateName . '.sql';

        // Check if the file already exists
        if (Storage::disk('public_html')->exists($fileName)) {
            // If so, delete the old file
            Storage::disk('public_html')->delete($fileName);
        }


        $command = [
            $this->mysqldumpPath,
            '--add-drop-table',
            '--skip-comments',
            '--default-character-set=utf8mb4',
            '--user="${:USER}"',
            '--password="${:PASSWORD}"',
            '"${:DATABASE}"',
        ];

        $proc = Process::fromShellCommandline(implode(' ', $command));

        $parameters = [
            'USER' => config('database.connections.mysql.username'),
            'PASSWORD' => config('database.connections.mysql.password'),
            'DATABASE' => $this->candidateName . '_interview',
        ];

        $proc->run(null, $parameters);
        if ($proc->isSuccessful()) {
            Storage::disk('public_html')->put($this->candidateName . '/' . $fileName, $proc->getOutput());
            return true;
        } else {
            $this->error('Error while dumping candidate\'s database');
            return false;
        }
    }

    private function compressCandidateFolder($targetFolder = '')
    {
        $fileName = 'test_' . $this->candidateName . '_' . date('Y-m-d_H-i-s') . '.zip';

        if ($targetFolder == '' || $targetFolder == 0) {
            // By default, the script will create the zip file in your current directory
            $targetFolder = getCwd();
        }
        // Check if the user has write permission on the target directory
        if (!is_writable($targetFolder)) {
            $this->error('You don\'t have write permission on current directory.');
            return false;
        }

        $targetFile = $targetFolder . '/' . $fileName;
        try {

            $zip = new ZipFile();
            $zip->addDirRecursive(Storage::path($this->candidateName));
            $zip->saveAsFile(getcwd() . '/' . $fileName);
            $zip->close();
        } catch (\PhpZip\Exception\ZipException $e) {
            $this->error('Error while compressing candidate\'s files');
            return false;
        } finally {
            return true;
        }
    }

    private function checkCandidateDirExists()
    {
        $dirs = Storage::disk('public_html')->directories();
        return in_array($this->candidateName, $dirs);
        //return true; // DEBUG
    }

    private function closeCodeServerSession()
    {
        $candidateName = $this->candidateName;
        $meta = DB::select("SELECT * FROM code_server_instances WHERE status = 1 AND candidate_name = ?", [$candidateName]);
        if ($meta == null) {
            $this->error('No open sessions found.');
            return false;
        }

        // Set the interview ID
        $this->interviewId = $meta[0]->id;

        // Get list of all PIDs from /usr/lib/code-server process

        $cmdOutput = explode("\n", shell_exec("ps aux | grep /usr/lib/code-server | awk '{print $2}'"));
        if (!is_array($cmdOutput)) {
            // TODO: soft-execution adaptations here
            $this->error('Error while fetching PIDs from Code-server process');
            return false;
        }

        /*
            // TODO: This was disabled until I found a way to return if the process was already killed
            // or insert a command flag to allow the "soft-execution" of this script
            // It means: the process can continue running if one step fails
            // NOTE: PIDs often change, so, we can't rely on the PID stored on database.
        if (!$process->isRunning()) {
            $this->error('The session is already closed or process not found.');
            return false;
        }*/
        foreach ($cmdOutput as $pid) {
            if ($pid == '') {
                continue;
            }
            $pid = trim($pid);
            $process = BackgroundProcess::createFromPID($pid);
            if ($process->stop()) {
                DB::table('code_server_instances')->where('candidate_name', $candidateName)->update(['status' => 0]);
                return true;
            } else {
                $this->error('Error while closing code-server session.');
                return false;
            }
        }
    }

    private function dropCandidateDatabase()
    {
        $dbName = $this->candidateName . '_interview';

        $response = DB::connection('mysql')->statement("DROP DATABASE IF EXISTS $dbName");
        return $response;
    }

    private function dropCandidateDbUser()
    {
        $dbUser = $this->candidateName . '_interview@localhost';

        return DB::statement("DROP USER $dbUser");
    }

    private function deleteCandidateFolder()
    {
        return Storage::disk('public_html')->deleteDirectory($this->candidateName);
    }

    private function postActionHooks()
    {
        // Here we can insert some code to run after the candidate has been removed

        // Update the candidate's status and recommendations

        // TODO: Calculate the general score based on how many steps the candidate completed and how much time it took.
        // For now, we'll just set it to 0.
        $score = 0;
        DB::update(
            'UPDATE code_server_instances SET hire_recommendation_level = ?, position_level_recommendation = ?, general_score = ? WHERE id = ?',
            [$this->hireRecommendation, $this->indicatedPosition, $score, $this->interviewId]
        );


        // Write the end time for the interview
        // TODO: Write a better query for this
        DB::update("UPDATE code_server_instances SET finished_at = NOW() WHERE id = ?", [$this->interviewId]);
        return true;
    }
}
