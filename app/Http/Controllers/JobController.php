<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Google\Cloud\Firestore\FieldValue;
use Google\Cloud\Core\Timestamp;
use Carbon\Carbon;
use Kreait\Laravel\Firebase\Facades\Firebase;

class JobController extends Controller
{
    protected $database;

    public function __construct()
    {
        $this->database = Firebase::firestore()->database();
    }

    public function store(Request $request)
    {
        $uid = $request->authUid;

        if (!$uid) {
            return response()->json(['error' => 'UID not found'], 400);
        }

        $isEmployer = $this->database
            ->collection('employers')
            ->document($uid)
            ->snapshot()
            ->exists();

        if (!$isEmployer) {
            return response()->json(['error' => 'Only employers can create jobs'], 403);
        }

        validator($request->all(), [
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'expiresAt'      => ['required', 'date'],
            'duration'    => ['required', 'string', 'max:255'],
            'salary'      => ['required', 'numeric', 'min:0'],
            'location'    => ['required', 'string', 'max:255'],
        ])->validate();

        $expiryDate = Carbon::parse($request->expiresAt)->toDateTimeImmutable();
        $expiryTimestamp = new Timestamp($expiryDate);

        $jobData = [
            'title'       => $request->title,
            'description' => $request->description,
            'employer'    => $uid,
            'expiresAt'   => $expiryTimestamp,
            'duration'    => $request->duration,
            'salary'      => (float) $request->salary,
            'location'    => $request->location,
            'deletedAt'   => null,
            'createdAt'   => FieldValue::serverTimestamp(),
            'updatedAt'   => FieldValue::serverTimestamp(),
        ];

        $docRef = $this->database
            ->collection('jobs')
            ->add($jobData);

        $jobData['id'] = $docRef->id();

        return response()->json([
            'message' => 'Job created successfully',
            'data'    => $jobData
        ], 201);
    }

    public function destroy(Request $request, string $id)
    {
        $uid = $request->authUid;

        if (!$uid) {
            return response()->json(['error' => 'UID not found'], 400);
        }

        $docRef = $this->database->collection('jobs')->document($id);
        $snapshot = $docRef->snapshot();

        if (!$snapshot->exists()) {
            return response()->json(['error' => 'Job not found'], 404);
        }

        $data = $snapshot->data();

        if ($data['deletedAt'] !== null) {
            return response()->json(['error' => 'Job already deleted'], 410);
        }

        if ($data['employer'] !== $uid) {
            return response()->json(['error' => 'Only the job creator can delete this job'], 403);
        }

        $docRef->update([
            ['path' => 'deletedAt', 'value' => FieldValue::serverTimestamp()],
            ['path' => 'updatedAt', 'value' => FieldValue::serverTimestamp()],
        ]);

        return response()->json(['message' => 'Job deleted successfully'], 200);
    }

    public function index(Request $request)
    {
        validator($request->all(), [
            'limit'          => ['sometimes', 'integer', 'min:1', 'max:50'],
            'sort_by'        => ['sometimes', 'string', 'in:createdAt,salary,expiresAt'],
            'sort_direction'  => ['sometimes', 'string', 'in:asc,desc'],
            'employer'       => ['sometimes', 'string'],
            'min_salary'     => ['sometimes', 'numeric', 'min:0'],
            'max_salary'     => ['sometimes', 'numeric', 'min:0'],
            'start_after'    => ['sometimes', 'string'],
        ])->validate();

        $query = $this->database->collection('jobs');

        $nowTimestamp = new Timestamp(Carbon::now()->toDateTimeImmutable());
        $query = $query->where('deletedAt', '=', null);
        $query = $query->where('expiresAt', '>', $nowTimestamp);

        $sortBy = $request->input('sort_by', 'createdAt');
        $sortDirection = $request->input('sort_direction', 'desc');

        // Equality filters
        if ($request->filled('employer')) {
            $query = $query->where('employer', '=', $request->employer);
        }

        // Range filters — Firestore requires range-filtered field to be the first orderBy
        if ($request->filled('min_salary') || $request->filled('max_salary')) {
            $sortBy = 'salary';
            if ($request->filled('min_salary')) {
                $query = $query->where('salary', '>=', (float) $request->min_salary);
            }
            if ($request->filled('max_salary')) {
                $query = $query->where('salary', '<=', (float) $request->max_salary);
            }
        }

        $query = $query->orderBy($sortBy, $sortDirection);
        if ($sortBy !== 'expiresAt') {
            $query = $query->orderBy('expiresAt', 'asc');
        }

        // Cursor-based pagination
        if ($request->filled('start_after')) {
            $startAfterValue = $request->start_after;

            if ($sortBy === 'salary') {
                $startAfterValue = (float) $startAfterValue;
            } elseif (in_array($sortBy, ['createdAt', 'expiresAt'])) {
                $startAfterValue = new Timestamp(Carbon::parse($startAfterValue)->toDateTimeImmutable());
            }

            $query = $query->startAfter([$startAfterValue]);
        }

        $perPage = (int) $request->input('limit', 15);
        $query = $query->limit($perPage);

        $documents = $query->documents();
        $jobs = [];

        foreach ($documents as $doc) {
            if ($doc->exists()) {
                $data = $doc->data();
                $data['id'] = $doc->id();
                $jobs[] = $data;
            }
        }

        $employerUids = array_unique(array_column($jobs, 'employer'));
        $employerProfiles = [];
        foreach ($employerUids as $employerUid) {
            $snap = $this->database
                ->collection('employers')
                ->document($employerUid)
                ->snapshot();
            $employerProfiles[$employerUid] = $snap->exists() ? $snap->data() : null;
        }
        foreach ($jobs as &$job) {
            $job['employer'] = $employerProfiles[$job['employer']] ?? null;
        }
        unset($job);

        return response()->json([
            'message' => 'Jobs retrieved successfully',
            'data'    => $jobs,
        ], 200);
    }
}
