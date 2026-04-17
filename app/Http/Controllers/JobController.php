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
            'expiresAt'   => ['required', 'date'],
            'duration'    => ['required', 'regex:/^\d+\s+(hour|day|week|month|year)\(s\)$/i'],
            'salary'      => ['required', 'numeric', 'min:0'],
            'location'    => ['required', 'string', 'max:255'],
            'tags'        => ['required', 'array', 'max:10'],
            'tags.*'      => ['string'],
        ])->validate();

        foreach ($request->tags as $tagId) {
            $tagSnap = $this->database->collection('serviceTags')->document($tagId)->snapshot();
            if (!$tagSnap->exists()) {
                return response()->json(['error' => "Tag '{$tagId}' not found"], 422);
            }
            if (!($tagSnap->data()['isActive'] ?? false)) {
                return response()->json(['error' => "Tag '{$tagId}' is inactive"], 422);
            }
        }

        $expiryDate      = Carbon::parse($request->expiresAt)->toDateTimeImmutable();
        $expiryTimestamp = new Timestamp($expiryDate);

        $jobData = [
            'title'               => $request->title,
            'description'         => $request->description,
            'employer'            => $uid,
            'expiresAt'           => $expiryTimestamp,
            'duration'            => $request->duration,
            'salary'              => (float) $request->salary,
            'location'            => $request->location,
            'tags'                => $request->tags,
            'deletedAt'           => null,
            'filledAt'            => null,
            'hiredApplicationId'  => null,
            'createdAt'           => FieldValue::serverTimestamp(),
            'updatedAt'           => FieldValue::serverTimestamp(),
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

    public function destroy(Request $request)
    {
        $uid = $request->authUid;
        $id  = $request->jobId;

        if (!$uid) {
            return response()->json(['error' => 'UID not found'], 400);
        }

        $docRef   = $this->database->collection('jobs')->document($id);
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
            'limit'         => ['sometimes', 'integer', 'min:1', 'max:50'],
            'sortBy'        => ['sometimes', 'string', 'in:createdAt,salary,expiresAt'],
            'sortDirection' => ['sometimes', 'string', 'in:asc,desc'],
            'employer'      => ['sometimes', 'string'],
            'minSalary'     => ['sometimes', 'numeric', 'min:0'],
            'maxSalary'     => ['sometimes', 'numeric', 'min:0'],
            'startAfter'    => ['sometimes', 'string'],
            'tags'          => ['sometimes', 'array', 'max:10'],
            'tags.*'        => ['string'],
        ])->validate();

        $hasSalaryRange = $request->filled('minSalary') || $request->filled('maxSalary');
        $hasTags        = $request->filled('tags') && count($request->input('tags', [])) > 0;

        if ($hasSalaryRange && $hasTags) {
            return response()->json([
                'error' => 'Cannot filter by salary range and tags simultaneously due to Firestore index constraints',
            ], 422);
        }

        $query = $this->database->collection('jobs');

        $nowTimestamp = new Timestamp(Carbon::now()->toDateTimeImmutable());
        $query = $query->where('deletedAt', '=', null);
        $query = $query->where('filledAt',  '=', null);
        $query = $query->where('expiresAt', '>', $nowTimestamp);

        $sortBy        = $request->input('sortBy', 'createdAt');
        $sortDirection = $request->input('sortDirection', 'desc');

        if ($request->filled('employer')) {
            $query = $query->where('employer', '=', $request->employer);
        }

        if ($hasTags) {
            $tags  = $request->input('tags');
            $query = $query->where('tags', 'array-contains-any', $tags);
        }

        if ($hasSalaryRange) {
            $sortBy = 'salary';
            if ($request->filled('minSalary')) {
                $query = $query->where('salary', '>=', (float) $request->minSalary);
            }
            if ($request->filled('maxSalary')) {
                $query = $query->where('salary', '<=', (float) $request->maxSalary);
            }
        }

        $query = $query->orderBy($sortBy, $sortDirection);
        if ($sortBy !== 'expiresAt') {
            $query = $query->orderBy('expiresAt', 'asc');
        }
        $query = $query->orderBy('__name__', 'asc');

        if ($request->filled('startAfter')) {
            $startAfterValue = $request->startAfter;

            if ($sortBy === 'salary') {
                $startAfterValue = (float) $startAfterValue;
            } elseif (in_array($sortBy, ['createdAt', 'expiresAt'])) {
                $startAfterValue = new Timestamp(Carbon::parse($startAfterValue)->toDateTimeImmutable());
            }

            $query = $query->startAfter([$startAfterValue]);
        }

        $perPage   = (int) $request->input('limit', 15);
        $query     = $query->limit($perPage);
        $documents = $query->documents();
        $jobs      = [];

        foreach ($documents as $doc) {
            if ($doc->exists()) {
                $data       = $doc->data();
                $data['id'] = $doc->id();
                $jobs[]     = $data;
            }
        }

        $employerUids    = array_unique(array_column($jobs, 'employer'));
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
