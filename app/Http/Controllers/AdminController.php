<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Google\Cloud\Firestore\FieldValue;
use Google\Cloud\Core\Timestamp;
use Carbon\Carbon;
use Kreait\Laravel\Firebase\Facades\Firebase;
use App\Services\NotificationService;

class AdminController extends Controller
{
    protected $database;

    public function __construct()
    {
        $this->database = Firebase::firestore()->database();
    }

    private function assertAdmin(string $uid): bool
    {
        return $this->database->collection('admins')->document($uid)->snapshot()->exists();
    }

    public function verifyUser(Request $request)
    {
        $uid = $request->authUid;

        if (!$uid) {
            return response()->json(['error' => 'UID not found'], 400);
        }

        if (!$this->assertAdmin($uid)) {
            return response()->json(['error' => 'Unauthorized access'], 401);
        }

        $request->validate([
            'type'    => 'required|in:seeker,employer',
            'userUid' => 'required|string',
        ]);

        $collection = ($request->type === 'seeker') ? 'seekers' : 'employers';
        $userUid    = $request->userUid;

        $isExisting = $this->database
            ->collection($collection)
            ->document($userUid)
            ->snapshot()
            ->exists();

        if (!$isExisting) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $this->database
            ->collection($collection)
            ->document($userUid)
            ->update([
                ['path' => 'isVerified',  'value' => true],
                ['path' => 'rejectedAt',  'value' => null],
                ['path' => 'updatedAt',   'value' => FieldValue::serverTimestamp()],
            ]);

        NotificationService::notify(
            $userUid,
            'verified',
            'Account Verified',
            'Your ID and clearance have been approved. You can now use Manila Link Up!',
            ['userType' => $request->type]
        );

        return response()->json(['message' => "User {$userUid} verified."]);
    }

    public function rejectVerification(Request $request)
    {
        $uid = $request->authUid;

        if (!$uid) {
            return response()->json(['error' => 'UID not found'], 400);
        }

        if (!$this->assertAdmin($uid)) {
            return response()->json(['error' => 'Unauthorized access'], 401);
        }

        $request->validate([
            'type'    => 'required|in:seeker,employer',
            'userUid' => 'required|string',
        ]);

        $collection = ($request->type === 'seeker') ? 'seekers' : 'employers';
        $userUid    = $request->userUid;

        $docRef = $this->database->collection($collection)->document($userUid);

        if (!$docRef->snapshot()->exists()) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $docRef->update([
            ['path' => 'isVerified',  'value' => false],
            ['path' => 'rejectedAt',  'value' => FieldValue::serverTimestamp()],
            ['path' => 'updatedAt',   'value' => FieldValue::serverTimestamp()],
        ]);

        NotificationService::notify(
            $userUid,
            'verification_rejected',
            'Verification Unsuccessful',
            'Your submitted ID or clearance was not accepted. Please re-upload clear, valid documents.',
            ['userType' => $request->type]
        );

        return response()->json(['message' => "User {$userUid} verification rejected."]);
    }

    public function analyticsOverview(Request $request)
    {
        if (!$this->assertAdmin($request->authUid)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $jobs         = $this->allDocs('jobs');
        $applications = $this->allDocs('applications');

        $totalJobs    = count($jobs);
        $now          = Carbon::now();
        $activeJobs   = 0;
        $filledJobs   = 0;
        $archivedJobs = 0;

        foreach ($jobs as $job) {
            if (($job['deletedAt'] ?? null) !== null) {
                $archivedJobs++;
            } elseif (($job['filledAt'] ?? null) !== null) {
                $filledJobs++;
            } else {
                $expiresAt = $job['expiresAt'] ?? null;
                $expired   = $expiresAt instanceof Timestamp
                    && Carbon::createFromTimestamp($expiresAt->get()->getTimestamp())->isPast();
                if (!$expired) {
                    $activeJobs++;
                } else {
                    $archivedJobs++;
                }
            }
        }

        $totalApplications = count($applications);
        $totalHires        = count(array_filter($applications, fn($a) => in_array($a['status'] ?? 0, [5, 6])));
        $avgApplicants     = $totalJobs > 0 ? round($totalApplications / $totalJobs, 2) : 0;

        return response()->json([
            'message' => 'Overview analytics retrieved',
            'data'    => [
                'totalJobs'         => $totalJobs,
                'activeJobs'        => $activeJobs,
                'filledJobs'        => $filledJobs,
                'archivedJobs'      => $archivedJobs,
                'totalApplications' => $totalApplications,
                'totalHires'        => $totalHires,
                'avgApplicantsPerJob' => $avgApplicants,
            ],
        ], 200);
    }

    public function analyticsTags(Request $request)
    {
        if (!$this->assertAdmin($request->authUid)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $jobs         = $this->allDocs('jobs');
        $applications = $this->allDocs('applications');

        // Index applications by jobId
        $appsByJob = [];
        foreach ($applications as $app) {
            $appsByJob[$app['jobId'] ?? ''][] = $app;
        }

        $tagStats = [];

        foreach ($jobs as $job) {
            $tags = $job['tags'] ?? [];
            foreach ($tags as $tag) {
                if (!isset($tagStats[$tag])) {
                    $tagStats[$tag] = ['jobCount' => 0, 'totalSalary' => 0, 'totalApplicants' => 0, 'hires' => 0];
                }
                $jobId   = $job['id'] ?? null;
                $jobApps = $jobId ? ($appsByJob[$jobId] ?? []) : [];

                $tagStats[$tag]['jobCount']++;
                $tagStats[$tag]['totalSalary']     += $job['salary'] ?? 0;
                $tagStats[$tag]['totalApplicants'] += count($jobApps);
                $tagStats[$tag]['hires']           += count(array_filter($jobApps, fn($a) => in_array($a['status'] ?? 0, [5, 6])));
            }
        }

        $result = [];
        foreach ($tagStats as $tag => $stats) {
            $result[] = [
                'tag'             => $tag,
                'jobCount'        => $stats['jobCount'],
                'avgSalary'       => $stats['jobCount'] > 0 ? round($stats['totalSalary'] / $stats['jobCount'], 2) : 0,
                'avgApplicants'   => $stats['jobCount'] > 0 ? round($stats['totalApplicants'] / $stats['jobCount'], 2) : 0,
                'hireRate'        => $stats['totalApplicants'] > 0 ? round($stats['hires'] / $stats['totalApplicants'], 4) : 0,
            ];
        }

        usort($result, fn($a, $b) => $b['jobCount'] <=> $a['jobCount']);

        return response()->json([
            'message' => 'Tags analytics retrieved',
            'data'    => $result,
        ], 200);
    }

    public function analyticsFunnel(Request $request)
    {
        if (!$this->assertAdmin($request->authUid)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $applications = $this->allDocs('applications');

        $totalApps       = count($applications);
        $interviews      = 0;
        $hires           = 0;
        $sumDaysToInterview = 0;
        $countDaysToInterview = 0;

        foreach ($applications as $app) {
            $status = $app['status'] ?? 1;
            if (in_array($status, [2, 5, 6])) {
                $interviews++;
            }
            if (in_array($status, [5, 6])) {
                $hires++;
            }
        }

        return response()->json([
            'message' => 'Funnel analytics retrieved',
            'data'    => [
                'totalApplications'        => $totalApps,
                'totalInterviews'          => $interviews,
                'totalHires'               => $hires,
                'pendingToInterviewRate'   => $totalApps > 0 ? round($interviews / $totalApps, 4) : 0,
                'interviewToHireRate'      => $interviews > 0 ? round($hires / $interviews, 4) : 0,
                'overallConversionRate'    => $totalApps > 0 ? round($hires / $totalApps, 4) : 0,
            ],
        ], 200);
    }

    public function analyticsTimeseries(Request $request)
    {
        if (!$this->assertAdmin($request->authUid)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $granularity = $request->input('granularity', 'day');
        if (!in_array($granularity, ['day', 'week', 'month'])) {
            return response()->json(['error' => 'granularity must be day, week, or month'], 422);
        }

        $jobs         = $this->allDocs('jobs');
        $applications = $this->allDocs('applications');

        $bucketJobs = [];
        $bucketApps = [];
        $bucketHires = [];

        foreach ($jobs as $job) {
            $ts = $job['createdAt'] ?? null;
            if ($ts instanceof Timestamp) {
                $key = $this->bucketKey(Carbon::createFromTimestamp($ts->get()->getTimestamp()), $granularity);
                $bucketJobs[$key] = ($bucketJobs[$key] ?? 0) + 1;
            }
        }

        foreach ($applications as $app) {
            $ts = $app['createdAt'] ?? null;
            if ($ts instanceof Timestamp) {
                $key = $this->bucketKey(Carbon::createFromTimestamp($ts->get()->getTimestamp()), $granularity);
                $bucketApps[$key] = ($bucketApps[$key] ?? 0) + 1;
                if (in_array($app['status'] ?? 0, [5, 6])) {
                    $bucketHires[$key] = ($bucketHires[$key] ?? 0) + 1;
                }
            }
        }

        $allKeys = array_unique(array_merge(array_keys($bucketJobs), array_keys($bucketApps)));
        sort($allKeys);

        $result = array_map(fn($k) => [
            'period'       => $k,
            'jobsPosted'   => $bucketJobs[$k] ?? 0,
            'applications' => $bucketApps[$k] ?? 0,
            'hires'        => $bucketHires[$k] ?? 0,
        ], $allKeys);

        return response()->json([
            'message' => 'Timeseries analytics retrieved',
            'data'    => $result,
        ], 200);
    }

    public function analyticsUsers(Request $request)
    {
        if (!$this->assertAdmin($request->authUid)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $seekers   = $this->allDocs('seekers');
        $employers = $this->allDocs('employers');

        $verifiedSeekers   = count(array_filter($seekers,   fn($s) => $s['isVerified'] ?? false));
        $verifiedEmployers = count(array_filter($employers, fn($e) => $e['isVerified'] ?? false));

        // New signups last 30 days
        $cutoff = new Timestamp(Carbon::now()->subDays(30)->toDateTimeImmutable());

        $newSeekers = count(array_filter($seekers, function ($s) use ($cutoff) {
            $ts = $s['createdAt'] ?? null;
            return $ts instanceof Timestamp && $ts > $cutoff;
        }));
        $newEmployers = count(array_filter($employers, function ($e) use ($cutoff) {
            $ts = $e['createdAt'] ?? null;
            return $ts instanceof Timestamp && $ts > $cutoff;
        }));

        return response()->json([
            'message' => 'User analytics retrieved',
            'data'    => [
                'totalSeekers'       => count($seekers),
                'totalEmployers'     => count($employers),
                'verifiedSeekers'    => $verifiedSeekers,
                'verifiedEmployers'  => $verifiedEmployers,
                'newSeekersLast30d'  => $newSeekers,
                'newEmployersLast30d' => $newEmployers,
            ],
        ], 200);
    }

    public function analyticsRatings(Request $request)
    {
        if (!$this->assertAdmin($request->authUid)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $ratings = $this->allDocs('ratings');

        $seekerScores   = [];
        $employerScores = [];
        $distribution   = ['1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0];

        foreach ($ratings as $r) {
            $score = (int) ($r['score'] ?? 0);
            if ($score >= 1 && $score <= 5) {
                $distribution[(string) $score]++;
            }
            if (($r['rateeRole'] ?? '') === 'seeker') {
                $seekerScores[] = $score;
            } else {
                $employerScores[] = $score;
            }
        }

        $avg = fn(array $arr) => count($arr) > 0 ? round(array_sum($arr) / count($arr), 2) : null;

        return response()->json([
            'message' => 'Ratings analytics retrieved',
            'data'    => [
                'totalRatings'          => count($ratings),
                'avgSeekerRating'       => $avg($seekerScores),
                'avgEmployerRating'     => $avg($employerScores),
                'scoreDistribution'     => $distribution,
            ],
        ], 200);
    }

    // Reads all docs from a collection — suitable for current data volume.
    // Future: consider cached materialization for large collections.
    private function allDocs(string $collection): array
    {
        $docs   = $this->database->collection($collection)->documents();
        $result = [];
        foreach ($docs as $doc) {
            if ($doc->exists()) {
                $data       = $doc->data();
                $data['id'] = $doc->id();
                $result[]   = $data;
            }
        }
        return $result;
    }

    private function bucketKey(Carbon $date, string $granularity): string
    {
        return match ($granularity) {
            'week'  => $date->startOfWeek()->format('Y-m-d'),
            'month' => $date->format('Y-m'),
            default => $date->format('Y-m-d'),
        };
    }
}
