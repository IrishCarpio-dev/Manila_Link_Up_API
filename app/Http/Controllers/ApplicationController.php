<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Google\Cloud\Firestore\FieldValue;
use Google\Cloud\Core\Timestamp;
use Carbon\Carbon;
use Kreait\Laravel\Firebase\Facades\Firebase;
use App\Services\NotificationService;

class ApplicationController extends Controller
{
    protected $database;

    public function __construct()
    {
        $this->database = Firebase::firestore()->database();
    }

    public function apply(Request $request)
    {
        $uid = $request->authUid;

        if ($request->authRole !== 'seeker') {
            return response()->json(['error' => 'Only seekers can apply to jobs'], 403);
        }

        $seekerSnap = $this->database->collection('seekers')->document($uid)->snapshot();
        $seeker = $seekerSnap->data();

        if (!($seeker['isVerified'] ?? false)) {
            return response()->json(['error' => 'Account not verified'], 403);
        }

        if (!($seeker['isProfileSet'] ?? false)) {
            return response()->json(['error' => 'Profile not set up'], 403);
        }

        validator($request->all(), [
            'jobId' => ['required', 'string'],
        ])->validate();

        $jobSnap = $this->database->collection('jobs')->document($request->jobId)->snapshot();

        if (!$jobSnap->exists()) {
            return response()->json(['error' => 'Job not found'], 404);
        }

        $job = $jobSnap->data();

        if ($job['deletedAt'] !== null) {
            return response()->json(['error' => 'Job no longer available'], 410);
        }

        if (($job['filledAt'] ?? null) !== null) {
            return response()->json(['error' => 'Job has already been filled'], 410);
        }

        $nowTimestamp = new Timestamp(Carbon::now()->toDateTimeImmutable());
        if ($job['expiresAt'] < $nowTimestamp) {
            return response()->json(['error' => 'Job posting has expired'], 422);
        }

        $existing = $this->database
            ->collection('applications')
            ->where('seekerUid', '=', $uid)
            ->where('jobId', '=', $request->jobId)
            ->limit(1)
            ->documents();

        foreach ($existing as $doc) {
            if ($doc->exists()) {
                return response()->json(['error' => 'You have already applied to this job'], 409);
            }
        }

        $appData = [
            'seekerUid'            => $uid,
            'jobId'                => $request->jobId,
            'status'               => 1,
            'chatId'               => null,
            'autoRejected'         => false,
            'employerCompletedAt'  => null,
            'seekerCompletedAt'    => null,
            'createdAt'            => FieldValue::serverTimestamp(),
            'updatedAt'            => FieldValue::serverTimestamp(),
        ];

        $docRef  = $this->database->collection('applications')->add($appData);
        $snap    = $docRef->snapshot();
        $appData = array_merge($snap->data(), ['id' => $docRef->id()]);

        $seekerName = trim(($seeker['firstName'] ?? '') . ' ' . ($seeker['lastName'] ?? ''));
        $jobTitle   = $job['title'] ?? '';

        NotificationService::notify(
            $job['employer'],
            'new_applicant',
            'New Applicant',
            $seekerName . ' applied for ' . $jobTitle,
            [
                'jobId'         => $request->jobId,
                'applicationId' => $docRef->id(),
                'jobTitle'      => $jobTitle,
                'seekerName'    => $seekerName,
            ]
        );

        return response()->json([
            'message' => 'Application submitted successfully',
            'data'    => $this->formatDoc($appData),
        ], 201);
    }

    public function withdraw(Request $request)
    {
        $uid = $request->authUid;

        if ($request->authRole !== 'seeker') {
            return response()->json(['error' => 'Only seekers can withdraw applications'], 403);
        }

        validator($request->all(), [
            'applicationId' => ['required', 'string'],
        ])->validate();

        $appSnap = $this->database->collection('applications')->document($request->applicationId)->snapshot();

        if (!$appSnap->exists()) {
            return response()->json(['error' => 'Application not found'], 404);
        }

        $app = $appSnap->data();

        if ($app['seekerUid'] !== $uid) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $this->database->collection('applications')->document($request->applicationId)->delete();

        return response()->json(['message' => 'Application withdrawn successfully'], 200);
    }

    public function seekerApplications(Request $request)
    {
        $uid = $request->authUid;

        if ($request->authRole !== 'seeker') {
            return response()->json(['error' => 'Only seekers can view their applications'], 403);
        }

        validator($request->all(), [
            'limit'      => ['sometimes', 'integer', 'min:1', 'max:50'],
            'startAfter' => ['sometimes', 'string'],
            'status'     => ['sometimes', 'integer', 'in:1,2,3,5'],
        ])->validate();

        $query = $this->database->collection('applications')->where('seekerUid', '=', $uid);

        if ($request->filled('status')) {
            $query = $query->where('status', '=', (int) $request->status);
        } else {
            $query = $query->where('status', 'in', [1, 2, 3, 5]);
        }

        $query = $query->orderBy('createdAt', 'DESC');

        if ($request->filled('startAfter')) {
            $cursorSnap = $this->database->collection('applications')->document($request->startAfter)->snapshot();
            if ($cursorSnap->exists()) {
                $query = $query->startAfter([$cursorSnap->get('createdAt')]);
            }
        }

        $perPage = (int) $request->input('limit', 10);
        $documents = $query->limit($perPage + 1)->documents();

        $applications = [];
        foreach ($documents as $doc) {
            if ($doc->exists()) {
                $data = $doc->data();
                $data['id'] = $doc->id();
                $applications[] = $data;
            }
        }

        $hasMore = count($applications) > $perPage;
        if ($hasMore) {
            $applications = array_slice($applications, 0, $perPage);
        }
        $nextCursor = $hasMore ? end($applications)['id'] : null;

        // Batch-fetch jobs
        $jobIds = array_unique(array_column($applications, 'jobId'));
        $jobs = [];
        foreach ($jobIds as $jobId) {
            $snap = $this->database->collection('jobs')->document($jobId)->snapshot();
            $jobs[$jobId] = $snap->exists() ? array_merge($snap->data(), ['id' => $snap->id()]) : null;
        }

        // Batch-fetch employers
        $employerUids = array_unique(array_filter(array_map(fn($j) => $j['employer'] ?? null, $jobs)));
        $employers = [];
        foreach ($employerUids as $employerUid) {
            $snap = $this->database->collection('employers')->document($employerUid)->snapshot();
            $employers[$employerUid] = $snap->exists() ? $snap->data() : null;
        }

        // Build response
        foreach ($applications as &$app) {
            $job = $jobs[$app['jobId']] ?? null;
            if ($job) {
                $employerUid  = $job['employer'];
                $employerData = $employers[$employerUid] ?? null;
                $app['job'] = [
                    'id'          => $job['id'],
                    'title'       => $job['title'],
                    'description' => $job['description'],
                    'salary'      => $job['salary'],
                    'location'    => $job['location'],
                    'duration'    => $job['duration'],
                    'expiresAt'   => $job['expiresAt'],
                    'tags'        => $job['tags'] ?? [],
                    'employer'    => $employerData ? [
                        'uid'      => $employerUid,
                        'fullName' => $employerData['fullName'] ?? null,
                    ] : null,
                ];
            } else {
                $app['job'] = null;
            }
        }
        unset($app);

        $applications = array_map([$this, 'formatDoc'], $applications);

        return response()->json([
            'message'    => 'Applications retrieved successfully',
            'data'       => $applications,
            'hasMore'    => $hasMore,
            'nextCursor' => $nextCursor,
        ], 200);
    }

    public function jobApplicants(Request $request)
    {
        $uid = $request->authUid;

        if ($request->authRole !== 'employer') {
            return response()->json(['error' => 'Only employers can view job applicants'], 403);
        }

        validator($request->all(), [
            'jobId'      => ['required', 'string'],
            'limit'      => ['sometimes', 'integer', 'min:1', 'max:50'],
            'startAfter' => ['sometimes', 'string'],
            'status'     => ['sometimes', 'integer', 'in:1,2,3,4,5,6'],
        ])->validate();

        $jobSnap = $this->database->collection('jobs')->document($request->jobId)->snapshot();

        if (!$jobSnap->exists()) {
            return response()->json(['error' => 'Job not found'], 404);
        }

        $job = $jobSnap->data();

        if ($job['employer'] !== $uid) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $query = $this->database->collection('applications')->where('jobId', '=', $request->jobId);

        if ($request->filled('status')) {
            $query = $query->where('status', '=', (int) $request->status);
        }

        $query = $query->orderBy('createdAt', 'DESC');

        if ($request->filled('startAfter')) {
            $cursorSnap = $this->database->collection('applications')->document($request->startAfter)->snapshot();
            if ($cursorSnap->exists()) {
                $query = $query->startAfter([$cursorSnap->get('createdAt')]);
            }
        }

        $perPage = (int) $request->input('limit', 15);
        $documents = $query->limit($perPage + 1)->documents();

        $applications = [];
        foreach ($documents as $doc) {
            if ($doc->exists()) {
                $data = $doc->data();
                $data['id'] = $doc->id();
                $applications[] = $data;
            }
        }

        $hasMore = count($applications) > $perPage;
        if ($hasMore) {
            $applications = array_slice($applications, 0, $perPage);
        }
        $nextCursor = $hasMore ? end($applications)['id'] : null;

        // Batch-fetch seekers
        $seekerUids = array_unique(array_column($applications, 'seekerUid'));
        $seekers = [];
        foreach ($seekerUids as $seekerUid) {
            $snap = $this->database->collection('seekers')->document($seekerUid)->snapshot();
            $seekers[$seekerUid] = $snap->exists() ? $snap->data() : null;
        }
        foreach ($applications as &$app) {
            $seekerData = $seekers[$app['seekerUid']] ?? null;
            $app['seeker'] = $seekerData ? [
                'uid'           => $app['seekerUid'],
                'firstName'     => $seekerData['firstName'] ?? null,
                'lastName'      => $seekerData['lastName'] ?? null,
                'mobileNumber'  => $seekerData['mobileNumber'] ?? null,
                'isOpenForWork' => $seekerData['isOpenForWork'] ?? null,
                'isVerified'    => $seekerData['isVerified'] ?? null,
                'location'      => $seekerData['location'] ?? null,
                'ratingCount'   => $seekerData['ratingCount'] ?? null,
                'bayesianAvg'   => $seekerData['bayesianAvg'] ?? null,
            ] : null;
        }
        unset($app);

        $applications = array_map([$this, 'formatDoc'], $applications);

        return response()->json([
            'message'    => 'Applicants retrieved successfully',
            'data'       => $applications,
            'hasMore'    => $hasMore,
            'nextCursor' => $nextCursor,
        ], 200);
    }

    public function updateStatus(Request $request)
    {
        $uid = $request->authUid;

        if ($request->authRole !== 'employer') {
            return response()->json(['error' => 'Only employers can update application status'], 403);
        }

        validator($request->all(), [
            'applicationId' => ['required', 'string'],
            'status'        => ['required', 'integer', 'in:2,3,5'],
        ])->validate();

        $appRef  = $this->database->collection('applications')->document($request->applicationId);
        $appSnap = $appRef->snapshot();

        if (!$appSnap->exists()) {
            return response()->json(['error' => 'Application not found'], 404);
        }

        $app = $appSnap->data();

        $jobSnap = $this->database->collection('jobs')->document($app['jobId'])->snapshot();

        if (!$jobSnap->exists()) {
            return response()->json(['error' => 'Job not found'], 404);
        }

        $job = $jobSnap->data();

        if ($job['employer'] !== $uid) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $newStatus = (int) $request->status;

        if ($app['status'] === $newStatus) {
            return response()->json(['error' => 'Status is already set to this value'], 422);
        }

        $employerSnap = $this->database->collection('employers')->document($uid)->snapshot();
        $employer     = $employerSnap->exists() ? $employerSnap->data() : [];
        $employerName = trim(($employer['firstName'] ?? '') . ' ' . ($employer['lastName'] ?? ''));

        // INTERVIEW (2): create chat if not exists
        if ($newStatus === 2) {
            $chatId  = $app['jobId'] . '_' . $app['seekerUid'];
            $chatRef = $this->database->collection('chats')->document($chatId);

            if (!$chatRef->snapshot()->exists()) {
                $chatRef->set([
                    'jobId'           => $app['jobId'],
                    'seekerUid'       => $app['seekerUid'],
                    'employerUid'     => $uid,
                    'applicationId'   => $request->applicationId,
                    'lastMessage'     => null,
                    'lastMessageAt'   => null,
                    'unreadBySeeker'  => 0,
                    'unreadByEmployer' => 0,
                    'hiddenBy'        => [],
                    'createdAt'       => FieldValue::serverTimestamp(),
                    'updatedAt'       => FieldValue::serverTimestamp(),
                ]);
            }

            $appRef->update([
                ['path' => 'status',    'value' => $newStatus],
                ['path' => 'chatId',    'value' => $chatId],
                ['path' => 'updatedAt', 'value' => FieldValue::serverTimestamp()],
            ]);

            NotificationService::notify(
                $app['seekerUid'],
                'interview_offer',
                'Interview Offer',
                'An employer wants to interview you!',
                [
                    'applicationId' => $request->applicationId,
                    'jobId'         => $app['jobId'],
                    'employerName'  => $employerName,
                    'jobTitle'      => $job['title'] ?? '',
                ]
            );
        }

        // HIRED (5): cascade-reject others, archive job
        elseif ($newStatus === 5) {
            $jobRef = $this->database->collection('jobs')->document($app['jobId']);

            // Reject all other active applicants for this job
            $otherApps = $this->database
                ->collection('applications')
                ->where('jobId', '=', $app['jobId'])
                ->documents();

            $rejectedUids = [];

            foreach ($otherApps as $otherDoc) {
                if (!$otherDoc->exists() || $otherDoc->id() === $request->applicationId) {
                    continue;
                }
                $otherData = $otherDoc->data();
                if (in_array($otherData['status'], [1, 2], true)) {
                    $otherDoc->reference()->update([
                        ['path' => 'status',       'value' => 3],
                        ['path' => 'autoRejected',  'value' => true],
                        ['path' => 'updatedAt',     'value' => FieldValue::serverTimestamp()],
                    ]);
                    $rejectedUids[] = $otherData['seekerUid'];
                }
            }

            // Create chat if applicant skipped interview
            $chatId  = $app['jobId'] . '_' . $app['seekerUid'];
            $chatRef = $this->database->collection('chats')->document($chatId);

            if (!$chatRef->snapshot()->exists()) {
                $chatRef->set([
                    'jobId'            => $app['jobId'],
                    'seekerUid'        => $app['seekerUid'],
                    'employerUid'      => $uid,
                    'applicationId'    => $request->applicationId,
                    'lastMessage'      => null,
                    'lastMessageAt'    => null,
                    'unreadBySeeker'   => 0,
                    'unreadByEmployer' => 0,
                    'hiddenBy'         => [],
                    'createdAt'        => FieldValue::serverTimestamp(),
                    'updatedAt'        => FieldValue::serverTimestamp(),
                ]);
            }

            // Update hired application
            $appRef->update([
                ['path' => 'status',    'value' => $newStatus],
                ['path' => 'chatId',    'value' => $chatId],
                ['path' => 'updatedAt', 'value' => FieldValue::serverTimestamp()],
            ]);

            // Archive (fill) the job
            $jobRef->update([
                ['path' => 'filledAt',            'value' => FieldValue::serverTimestamp()],
                ['path' => 'hiredApplicationId',   'value' => $request->applicationId],
                ['path' => 'updatedAt',            'value' => FieldValue::serverTimestamp()],
            ]);

            // Notify hired seeker
            NotificationService::notify(
                $app['seekerUid'],
                'hired',
                'You got hired!',
                'Congratulations! You have been hired.',
                [
                    'applicationId' => $request->applicationId,
                    'jobId'         => $app['jobId'],
                    'jobTitle'      => $job['title'] ?? '',
                ]
            );

            // Notify auto-rejected seekers
            foreach (array_unique($rejectedUids) as $rejectedUid) {
                NotificationService::notify(
                    $rejectedUid,
                    'job_filled',
                    'Application Update',
                    'The position has been filled. Keep looking!',
                    [
                        'jobId'    => $app['jobId'],
                        'jobTitle' => $job['title'] ?? '',
                    ]
                );
            }
        }

        // REJECTED (3)
        else {
            $appRef->update([
                ['path' => 'status',    'value' => $newStatus],
                ['path' => 'updatedAt', 'value' => FieldValue::serverTimestamp()],
            ]);

            NotificationService::notify(
                $app['seekerUid'],
                'rejected',
                'Application Update',
                'Your application was not selected this time.',
                [
                    'applicationId' => $request->applicationId,
                    'jobId'         => $app['jobId'],
                    'jobTitle'      => $job['title'] ?? '',
                ]
            );
        }

        // Re-read to return resolved timestamps
        $updated = $appRef->snapshot()->data();
        $updated['id'] = $request->applicationId;

        return response()->json([
            'message' => 'Application status updated successfully',
            'data'    => $this->formatDoc($updated),
        ], 200);
    }

    public function markComplete(Request $request)
    {
        $uid = $request->authUid;

        validator($request->all(), [
            'applicationId' => ['required', 'string'],
        ])->validate();

        $appRef  = $this->database->collection('applications')->document($request->applicationId);
        $appSnap = $appRef->snapshot();

        if (!$appSnap->exists()) {
            return response()->json(['error' => 'Application not found'], 404);
        }

        $app = $appSnap->data();

        if ($app['status'] !== 5) {
            return response()->json(['error' => 'Only hired applications can be marked complete'], 422);
        }

        $jobSnap = $this->database->collection('jobs')->document($app['jobId'])->snapshot();

        if (!$jobSnap->exists()) {
            return response()->json(['error' => 'Job not found'], 404);
        }

        $job = $jobSnap->data();

        $isSeeker   = $app['seekerUid'] === $uid;
        $isEmployer = $job['employer'] === $uid;

        if (!$isSeeker && !$isEmployer) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $updates = [['path' => 'updatedAt', 'value' => FieldValue::serverTimestamp()]];

        if ($isSeeker && ($app['seekerCompletedAt'] ?? null) === null) {
            $updates[] = ['path' => 'seekerCompletedAt', 'value' => FieldValue::serverTimestamp()];
        } elseif ($isEmployer && ($app['employerCompletedAt'] ?? null) === null) {
            $updates[] = ['path' => 'employerCompletedAt', 'value' => FieldValue::serverTimestamp()];
        } else {
            return response()->json(['error' => 'Already marked as complete'], 422);
        }

        $appRef->update($updates);

        // Re-check if both sides have now completed
        $fresh = $appRef->snapshot()->data();
        $bothDone = ($fresh['seekerCompletedAt'] ?? null) !== null
                 && ($fresh['employerCompletedAt'] ?? null) !== null;

        if ($bothDone) {
            $appRef->update([
                ['path' => 'status',    'value' => 6],
                ['path' => 'updatedAt', 'value' => FieldValue::serverTimestamp()],
            ]);

            $this->database->collection('jobs')->document($app['jobId'])->update([
                ['path' => 'completedAt', 'value' => FieldValue::serverTimestamp()],
                ['path' => 'updatedAt',   'value' => FieldValue::serverTimestamp()],
            ]);

            $seekerSnap = $this->database->collection('seekers')->document($app['seekerUid'])->snapshot();
            $seekerData = $seekerSnap->exists() ? $seekerSnap->data() : [];
            $seekerName = trim(($seekerData['firstName'] ?? '') . ' ' . ($seekerData['lastName'] ?? ''));

            NotificationService::notify(
                $app['seekerUid'],
                'job_completed',
                'Job Completed',
                'Your job is complete! Rate your experience.',
                [
                    'applicationId' => $request->applicationId,
                    'jobId'         => $app['jobId'],
                    'jobTitle'      => $job['title'] ?? '',
                ]
            );
            NotificationService::notify(
                $job['employer'],
                'job_completed',
                'Job Completed',
                'Your job is complete! Rate your worker.',
                [
                    'applicationId' => $request->applicationId,
                    'jobId'         => $app['jobId'],
                    'jobTitle'      => $job['title'] ?? '',
                    'seekerName'    => $seekerName,
                ]
            );
        }

        $result = $appRef->snapshot()->data();
        $result['id'] = $request->applicationId;

        return response()->json([
            'message' => 'Marked as complete',
            'data'    => $this->formatDoc($result),
        ], 200);
    }

    public function seekerCompletedJobs(Request $request)
    {
        $authUid  = $request->authUid;
        $authRole = $request->authRole;

        if (!$authUid) {
            return response()->json(['error' => 'UID not found'], 400);
        }

        if (!in_array($authRole, ['seeker', 'employer'])) {
            return response()->json(['error' => 'Unauthorized role'], 403);
        }

        validator($request->all(), [
            'uid'        => ['sometimes', 'string'],
            'limit'      => ['sometimes', 'integer', 'min:1', 'max:50'],
            'startAfter' => ['sometimes', 'string'],
        ])->validate();

        if ($authRole === 'seeker') {
            $uid = $authUid;
        } else {
            if (!$request->filled('uid')) {
                return response()->json(['error' => 'uid is required for employers'], 422);
            }
            $uid = $request->input('uid');
        }

        $query = $this->database->collection('applications')
            ->where('seekerUid', '=', $uid)
            ->where('status', '=', 6)
            ->orderBy('updatedAt', 'desc');

        if ($request->filled('startAfter')) {
            $cursorSnap = $this->database->collection('applications')->document($request->startAfter)->snapshot();
            if ($cursorSnap->exists()) {
                $query = $query->startAfter([$cursorSnap->get('updatedAt')]);
            }
        }

        $perPage   = (int) $request->input('limit', 15);
        $documents = $query->limit($perPage + 1)->documents();

        $applications = [];
        foreach ($documents as $doc) {
            if ($doc->exists()) {
                $data       = $doc->data();
                $data['id'] = $doc->id();
                $applications[] = $data;
            }
        }

        $hasMore = count($applications) > $perPage;
        if ($hasMore) {
            $applications = array_slice($applications, 0, $perPage);
        }
        $nextCursor = $hasMore ? end($applications)['id'] : null;

        $jobIds = array_unique(array_column($applications, 'jobId'));
        $jobs   = [];
        foreach ($jobIds as $jobId) {
            $snap        = $this->database->collection('jobs')->document($jobId)->snapshot();
            $jobs[$jobId] = $snap->exists() ? array_merge($snap->data(), ['id' => $snap->id()]) : null;
        }

        $employerUids = array_unique(array_filter(array_map(fn($j) => $j['employer'] ?? null, $jobs)));
        $employers    = [];
        foreach ($employerUids as $employerUid) {
            $snap                    = $this->database->collection('employers')->document($employerUid)->snapshot();
            $employers[$employerUid] = $snap->exists() ? $snap->data() : null;
        }

        $raterRole    = $authRole === 'employer' ? 'employer' : 'seeker';
        $seekerRatings = [];
        foreach (array_chunk(array_column($applications, 'id'), 30) as $chunk) {
            $ratingDocs = $this->database->collection('ratings')
                ->where('applicationId', 'in', $chunk)
                ->where('raterRole', '=', $raterRole)
                ->documents();
            foreach ($ratingDocs as $doc) {
                if ($doc->exists()) {
                    $data = $doc->data();
                    $seekerRatings[$data['applicationId']] = $data;
                }
            }
        }

        foreach ($applications as &$app) {
            $job = $jobs[$app['jobId']] ?? null;
            if ($job) {
                $employerUid  = $job['employer'];
                $employerData = $employers[$employerUid] ?? null;
                $app['job']   = [
                    'id'          => $job['id'],
                    'title'       => $job['title'],
                    'description' => $job['description'],
                    'salary'      => $job['salary'],
                    'location'    => $job['location'],
                    'duration'    => $job['duration'],
                    'expiresAt'   => $job['expiresAt'],
                    'tags'        => $job['tags'] ?? [],
                    'employer'    => $employerData ? [
                        'uid'      => $employerUid,
                        'fullName' => $employerData['fullName'] ?? null,
                    ] : null,
                ];
            } else {
                $app['job'] = null;
            }

            $rating = $seekerRatings[$app['id']] ?? null;
            if ($rating === null) {
                $app['isRateEnabled'] = true;
            } else {
                $createdAt     = $rating['createdAt'];
                $createdAtTime = $createdAt instanceof Timestamp
                    ? Carbon::createFromTimestamp($createdAt->get()->getTimestamp())
                    : Carbon::parse($createdAt);
                $app['isRateEnabled'] = $createdAtTime->diffInHours(Carbon::now()) < 24;
            }
        }
        unset($app);

        $applications = array_map([$this, 'formatDoc'], $applications);

        return response()->json([
            'message'    => 'Completed jobs retrieved successfully',
            'data'       => $applications,
            'hasMore'    => $hasMore,
            'nextCursor' => $nextCursor,
        ], 200);
    }
}
