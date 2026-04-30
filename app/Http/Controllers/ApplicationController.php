<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Google\Cloud\Firestore\FieldValue;
use Google\Cloud\Core\Timestamp;
use Carbon\Carbon;
use Kreait\Laravel\Firebase\Facades\Firebase;
use App\Services\FcmNotifier;

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
            $cursor = new Timestamp(Carbon::parse($request->startAfter)->toDateTimeImmutable());
            $query = $query->startAfter([$cursor]);
        }

        $perPage = (int) $request->input('limit', 15);
        $documents = $query->limit($perPage)->documents();

        $applications = [];
        foreach ($documents as $doc) {
            if ($doc->exists()) {
                $data = $doc->data();
                $data['id'] = $doc->id();
                $applications[] = $data;
            }
        }

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

        // Batch-fetch profile photos
        $employerPhotos = [];
        foreach ($employerUids as $employerUid) {
            $snap = $this->database->collection('profilePhotos')->document($employerUid)->snapshot();
            $employerPhotos[$employerUid] = $snap->exists() ? ($snap->data()['base64'] ?? null) : null;
        }

        // Build response
        foreach ($applications as &$app) {
            $job = $jobs[$app['jobId']] ?? null;
            if ($job) {
                $employerData = $employers[$job['employer']] ?? null;
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
                        'fullName'     => $employerData['fullName'] ?? null,
                        'profilePhoto' => $employerPhotos[$job['employer']] ?? null,
                    ] : null,
                ];
            } else {
                $app['job'] = null;
            }
        }
        unset($app);

        $applications = array_map([$this, 'formatDoc'], $applications);

        return response()->json([
            'message' => 'Applications retrieved successfully',
            'data'    => $applications,
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
            $cursor = new Timestamp(Carbon::parse($request->startAfter)->toDateTimeImmutable());
            $query = $query->startAfter([$cursor]);
        }

        $perPage = (int) $request->input('limit', 15);
        $documents = $query->limit($perPage)->documents();

        $applications = [];
        foreach ($documents as $doc) {
            if ($doc->exists()) {
                $data = $doc->data();
                $data['id'] = $doc->id();
                $applications[] = $data;
            }
        }

        // Batch-fetch seekers
        $seekerUids = array_unique(array_column($applications, 'seekerUid'));
        $seekers = [];
        foreach ($seekerUids as $seekerUid) {
            $snap = $this->database->collection('seekers')->document($seekerUid)->snapshot();
            $seekers[$seekerUid] = $snap->exists() ? $snap->data() : null;
        }
        $seekerPhotos = [];
        foreach ($seekerUids as $seekerUid) {
            $snap = $this->database->collection('profilePhotos')->document($seekerUid)->snapshot();
            $seekerPhotos[$seekerUid] = $snap->exists() ? ($snap->data()['base64'] ?? null) : null;
        }

        foreach ($applications as &$app) {
            $seekerData = $seekers[$app['seekerUid']] ?? null;
            $app['seeker'] = $seekerData ? [
                'firstName'    => $seekerData['firstName'] ?? null,
                'lastName'     => $seekerData['lastName'] ?? null,
                'mobileNumber' => $seekerData['mobileNumber'] ?? null,
                'isOpenForWork' => $seekerData['isOpenForWork'] ?? null,
                'isVerified'   => $seekerData['isVerified'] ?? null,
                'profilePhoto' => $seekerPhotos[$app['seekerUid']] ?? null,
            ] : null;
        }
        unset($app);

        $applications = array_map([$this, 'formatDoc'], $applications);

        return response()->json([
            'message' => 'Applicants retrieved successfully',
            'data'    => $applications,
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

            FcmNotifier::sendToUser(
                $app['seekerUid'],
                'Interview Offer',
                'An employer wants to interview you!',
                ['type' => 'status_change', 'status' => '2', 'applicationId' => $request->applicationId]
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

            // Update hired application
            $appRef->update([
                ['path' => 'status',    'value' => $newStatus],
                ['path' => 'updatedAt', 'value' => FieldValue::serverTimestamp()],
            ]);

            // Archive (fill) the job
            $jobRef->update([
                ['path' => 'filledAt',            'value' => FieldValue::serverTimestamp()],
                ['path' => 'hiredApplicationId',   'value' => $request->applicationId],
                ['path' => 'updatedAt',            'value' => FieldValue::serverTimestamp()],
            ]);

            // Notify hired seeker
            FcmNotifier::sendToUser(
                $app['seekerUid'],
                'You got hired!',
                'Congratulations! You have been hired.',
                ['type' => 'status_change', 'status' => '5', 'applicationId' => $request->applicationId]
            );

            // Notify auto-rejected seekers
            foreach (array_unique($rejectedUids) as $rejectedUid) {
                FcmNotifier::sendToUser(
                    $rejectedUid,
                    'Application Update',
                    'The position has been filled.',
                    ['type' => 'status_change', 'status' => '3', 'applicationId' => $request->applicationId]
                );
            }
        }

        // REJECTED (3)
        else {
            $appRef->update([
                ['path' => 'status',    'value' => $newStatus],
                ['path' => 'updatedAt', 'value' => FieldValue::serverTimestamp()],
            ]);

            FcmNotifier::sendToUser(
                $app['seekerUid'],
                'Application Update',
                'Your application status has been updated.',
                ['type' => 'status_change', 'status' => (string) $newStatus, 'applicationId' => $request->applicationId]
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

            FcmNotifier::sendToUser(
                $app['seekerUid'],
                'Job Completed',
                'Your job is complete! Rate your experience.',
                ['type' => 'status_change', 'status' => '6', 'applicationId' => $request->applicationId]
            );
            FcmNotifier::sendToUser(
                $job['employer'],
                'Job Completed',
                'Your job is complete! Rate your worker.',
                ['type' => 'status_change', 'status' => '6', 'applicationId' => $request->applicationId]
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
        $uid = $request->authUid;

        if (!$uid) {
            return response()->json(['error' => 'UID not found'], 400);
        }

        if ($request->authRole !== 'seeker') {
            return response()->json(['error' => 'Only seekers can access this endpoint'], 403);
        }

        validator($request->all(), [
            'limit'      => ['sometimes', 'integer', 'min:1', 'max:50'],
            'startAfter' => ['sometimes', 'string'],
        ])->validate();

        $query = $this->database->collection('applications')
            ->where('seekerUid', '=', $uid)
            ->where('status', '=', 6)
            ->orderBy('updatedAt', 'desc');

        if ($request->filled('startAfter')) {
            $cursor = new Timestamp(Carbon::parse($request->startAfter)->toDateTimeImmutable());
            $query  = $query->startAfter([$cursor]);
        }

        $perPage   = (int) $request->input('limit', 15);
        $documents = $query->limit($perPage)->documents();

        $applications = [];
        foreach ($documents as $doc) {
            if ($doc->exists()) {
                $data       = $doc->data();
                $data['id'] = $doc->id();
                $applications[] = $data;
            }
        }

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

        $employerPhotos = [];
        foreach ($employerUids as $employerUid) {
            $snap                       = $this->database->collection('profilePhotos')->document($employerUid)->snapshot();
            $employerPhotos[$employerUid] = $snap->exists() ? ($snap->data()['base64'] ?? null) : null;
        }

        foreach ($applications as &$app) {
            $job = $jobs[$app['jobId']] ?? null;
            if ($job) {
                $employerData = $employers[$job['employer']] ?? null;
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
                        'fullName'     => $employerData['fullName'] ?? null,
                        'profilePhoto' => $employerPhotos[$job['employer']] ?? null,
                    ] : null,
                ];
            } else {
                $app['job'] = null;
            }
        }
        unset($app);

        $hasMore    = count($applications) >= $perPage;
        $lastApp    = !empty($applications) ? end($applications) : null;
        $nextCursor = ($hasMore && $lastApp)
            ? ($lastApp['updatedAt'] instanceof Timestamp
                ? $lastApp['updatedAt']->get()->format('c')
                : $lastApp['updatedAt'])
            : null;

        $applications = array_map([$this, 'formatDoc'], $applications);

        return response()->json([
            'message'    => 'Completed jobs retrieved successfully',
            'data'       => $applications,
            'hasMore'    => $hasMore,
            'nextCursor' => $nextCursor,
        ], 200);
    }
}
