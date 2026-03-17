<?php

namespace App\Http\Controllers\Api; // Added '\Api' to match folder

use App\Http\Controllers\Controller; // tells Laravel where the Parent file is
use Illuminate\Http\Request;
use Kreait\Firebase\Contract\Database;

class JobController extends Controller
{
    protected $database;

    public function __construct(Database $database)
    {
        // connects to existing Firebase Database
        $this->database = $database;
    }

    public function getAllJobs()
    {
        // Fetches the 'jobs' node already created in Firebase
        $reference = $this->database->getReference('jobs');
        $snapshot = $reference->getSnapshot();

        return response()->json($snapshot->getValue());
    }

    public function createJob(Request $request)
    {
        // Pushes new job data directly to Firebase
        $newPostKey = $this->database->getReference('jobs')->push()->getKey();
        
        $jobData = [
            'job_title' => $request->job_title,
            'salary' => $request->salary,
            'location' => $request->location,
            'employer_id' => $request->employer_id
        ];

        $this->database->getReference('jobs/' . $newPostKey)->set($jobData);

        return response()->json(['status' => 'Successfully saved to Firebase!']);
    }
}