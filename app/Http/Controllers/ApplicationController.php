<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Google\Cloud\Firestore\FieldValue;
use Google\Cloud\Core\Timestamp;
use Carbon\Carbon;
use Kreait\Laravel\Firebase\Facades\Firebase;

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

        $seekerSnap = $this->database->collection('seekers')->document($uid)->snapshot();

        if (!$seekerSnap->exists()) {
            return response()->json(['error' => 'Only seekers can apply to jobs'], 403);
        }

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
            'seekerUid' => $uid,
            'jobId'     => $request->jobId,
            'status'    => 1,
            'createdAt' => FieldValue::serverTimestamp(),
            'updatedAt' => FieldValue::serverTimestamp(),
        ];

        $docRef = $this->database->collection('applications')->add($appData);
        $appData['id'] = $docRef->id();

        return response()->json([
            'message' => 'Application submitted successfully',
            'data'    => $appData,
        ], 201);
    }

    public function withdraw(Request $request)
    {
        $uid = $request->authUid;

        $seekerSnap = $this->database->collection('seekers')->document($uid)->snapshot();

        if (!$seekerSnap->exists()) {
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

        $seekerSnap = $this->database->collection('seekers')->document($uid)->snapshot();

        if (!$seekerSnap->exists()) {
            return response()->json(['error' => 'Only seekers can view their applications'], 403);
        }

        validator($request->all(), [
            'limit'      => ['sometimes', 'integer', 'min:1', 'max:50'],
            'startAfter' => ['sometimes', 'string'],
            'status'     => ['sometimes', 'integer', 'in:1,2,3,4'],
        ])->validate();

        $query = $this->database->collection('applications')->where('seekerUid', '=', $uid);

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
                $employerData = $employers[$job['employer']] ?? null;
                $app['job'] = [
                    'id'          => $job['id'],
                    'title'       => $job['title'],
                    'description' => $job['description'],
                    'salary'      => $job['salary'],
                    'location'    => $job['location'],
                    'duration'    => $job['duration'],
                    'expiresAt'   => $job['expiresAt'],
                    'employer'    => $employerData ? [
                        'fullName'        => $employerData['fullName'] ?? null,
                        'profilePhotoUrl' => $employerData['profilePhotoUrl'] ?? null,
                    ] : null,
                ];
            } else {
                $app['job'] = null;
            }
        }
        unset($app);

        return response()->json([
            'message' => 'Applications retrieved successfully',
            'data'    => $applications,
        ], 200);
    }

    public function jobApplicants(Request $request)
    {
        $uid = $request->authUid;

        $employerSnap = $this->database->collection('employers')->document($uid)->snapshot();

        if (!$employerSnap->exists()) {
            return response()->json(['error' => 'Only employers can view job applicants'], 403);
        }

        validator($request->all(), [
            'jobId'      => ['required', 'string'],
            'limit'      => ['sometimes', 'integer', 'min:1', 'max:50'],
            'startAfter' => ['sometimes', 'string'],
            'status'     => ['sometimes', 'integer', 'in:1,2,3,4'],
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

        foreach ($applications as &$app) {
            $seekerData = $seekers[$app['seekerUid']] ?? null;
            $app['seeker'] = $seekerData ? [
                'firstName'       => $seekerData['firstName'] ?? null,
                'lastName'        => $seekerData['lastName'] ?? null,
                'mobileNumber'    => $seekerData['mobileNumber'] ?? null,
                'profilePhotoUrl' => $seekerData['profilePhotoUrl'] ?? null,
                'isOpenForWork'   => $seekerData['isOpenForWork'] ?? null,
                'isVerified'      => $seekerData['isVerified'] ?? null,
            ] : null;
        }
        unset($app);

        return response()->json([
            'message' => 'Applicants retrieved successfully',
            'data'    => $applications,
        ], 200);
    }

    public function updateStatus(Request $request)
    {
        $uid = $request->authUid;

        $employerSnap = $this->database->collection('employers')->document($uid)->snapshot();

        if (!$employerSnap->exists()) {
            return response()->json(['error' => 'Only employers can update application status'], 403);
        }

        validator($request->all(), [
            'applicationId' => ['required', 'string'],
            'status'        => ['required', 'integer', 'in:2,3,4'],
        ])->validate();

        $appSnap = $this->database->collection('applications')->document($request->applicationId)->snapshot();

        if (!$appSnap->exists()) {
            return response()->json(['error' => 'Application not found'], 404);
        }

        $app = $appSnap->data();

        $jobSnap = $this->database->collection('jobs')->document($app['jobId'])->snapshot();
        $job = $jobSnap->data();

        if ($job['employer'] !== $uid) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $newStatus = (int) $request->status;

        if ($app['status'] === $newStatus) {
            return response()->json(['error' => 'Status is already set to this value'], 422);
        }

        $this->database->collection('applications')->document($request->applicationId)->update([
            ['path' => 'status',    'value' => $newStatus],
            ['path' => 'updatedAt', 'value' => FieldValue::serverTimestamp()],
        ]);

        return response()->json([
            'message' => 'Application status updated successfully',
            'data'    => [
                'id'        => $request->applicationId,
                'status'    => $newStatus,
                'updatedAt' => FieldValue::serverTimestamp(),
            ],
        ], 200);
    }
}
