<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

        validator($request->all(), [
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'expiresAt'   => ['required', 'date'],
            'duration'    => ['required', 'regex:/^\d+\s+(hour|day|week|month|year)\(s\)$/i'],
            'salary'      => ['required', 'numeric', 'min:0'],
            'location'    => ['required', 'string', Rule::in(config('manila.districts'))],
            'tags'        => ['required', 'array', 'max:10'],
            'tags.*'      => ['string'],
        ])->validate();

        if ($request->authRole !== 'employer') {
            return response()->json(['error' => 'Only employers can create jobs'], 403);
        }

        $tagRefs    = array_map(fn($id) => $this->database->collection('serviceTags')->document($id), $request->tags);
        $tagSnapMap = [];
        foreach ($this->database->documents($tagRefs) as $snap) {
            $tagSnapMap[$snap->id()] = $snap;
        }
        foreach ($request->tags as $tagId) {
            $tagSnap = $tagSnapMap[$tagId] ?? null;
            if (!$tagSnap || !$tagSnap->exists()) {
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
            'completedAt'         => null,
            'hiredApplicationId'  => null,
            'createdAt'           => FieldValue::serverTimestamp(),
            'updatedAt'           => FieldValue::serverTimestamp(),
        ];

        $docRef  = $this->database->collection('jobs')->add($jobData);
        $snap    = $docRef->snapshot();
        $created = array_merge($snap->data(), ['id' => $docRef->id()]);

        return response()->json([
            'message' => 'Job created successfully',
            'data'    => $this->formatDoc($created),
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

    public function seekerJobs(Request $request)
    {
        $uid = $request->authUid;

        if (!$uid) {
            return response()->json(['error' => 'UID not found'], 400);
        }

        if ($request->authRole !== 'seeker') {
            return response()->json(['error' => 'Only seekers can access this endpoint'], 403);
        }

        $seekerSnap = $this->database->collection('seekers')->document($uid)->snapshot();
        $seekerData = $seekerSnap->data();

        if (!($seekerData['isProfileSet'] ?? false)) {
            return response()->json(['error' => 'Profile must be set up before browsing jobs'], 403);
        }

        validator($request->all(), [
            'mode'               => ['sometimes', 'string', 'in:curated,all'],
            'limit'              => ['sometimes', 'integer', 'min:1', 'max:50'],
            'startAfter'         => ['sometimes', 'string'],
            'startAfterCreatedAt' => ['sometimes', 'string'],
        ])->validate();

        $mode    = $request->input('mode', 'curated');
        $perPage = (int) $request->input('limit', 15);

        $preferences   = $seekerData['preferences'] ?? [];
        $prefTags      = $preferences['tags'] ?? [];
        $prefSalary    = isset($preferences['preferredSalary']) ? (integer) $preferences['preferredSalary'] : null;
        $prefLocation  = $preferences['preferredLocation'] ?? null;

        $nowTimestamp = new Timestamp(Carbon::now()->toDateTimeImmutable());
        $query = $this->database->collection('jobs')
            ->where('deletedAt',   '=', null)
            ->where('filledAt',    '=', null)
            ->where('completedAt', '=', null)
            ->where('expiresAt',   '>', $nowTimestamp);

        $phpSalaryFilter    = false;
        $phpLocationFilter  = $mode === 'curated' && !empty($prefLocation);
        $phpCompletedFilter = false;

        if ($mode === 'curated') {
            $phpSalaryFilter = $prefSalary !== null;

            if (!empty($prefTags)) {
                $query = $query->where('tags', 'array-contains-any', $prefTags);
            } 
            
            if ($phpSalaryFilter) {
                $query = $query->where('salary', '>=', $prefSalary);
            }
        }

        $query = $query
            ->orderBy('expiresAt', 'asc')
            ->orderBy('createdAt', 'desc');

        if ($request->filled('startAfter')) {
            $expiresAtCursor  = new Timestamp(Carbon::parse($request->startAfter)->toDateTimeImmutable());
            $createdAtCursor  = $request->filled('startAfterCreatedAt')
                ? new Timestamp(Carbon::parse($request->startAfterCreatedAt)->toDateTimeImmutable())
                : null;
            $cursorValues = $createdAtCursor
                ? [$expiresAtCursor, $createdAtCursor]
                : [$expiresAtCursor];
            $query = $query->startAfter($cursorValues);
        }

        $applyPhpFilters = $phpSalaryFilter || $phpLocationFilter || $phpCompletedFilter;
        $fetchLimit      = $applyPhpFilters ? min($perPage * 2, 100) : $perPage;
        $documents       = $query->limit($fetchLimit)->documents();

        $jobs = [];
        foreach ($documents as $doc) {
            if ($doc->exists()) {
                $data       = $doc->data();
                $data['id'] = $doc->id();
                $jobs[]     = $data;
            }
        }

        if ($applyPhpFilters) {
            $jobs = array_values(array_filter($jobs, function ($job) use ($phpSalaryFilter, $prefSalary, $phpLocationFilter, $prefLocation) {
                if ($phpSalaryFilter && isset($job['salary']) && (float) $job['salary'] < $prefSalary) {
                    return false;
                }
                if ($phpLocationFilter && ($job['location'] ?? null) !== $prefLocation) {
                    return false;
                }
                return true;
            }));
            $jobs = array_slice($jobs, 0, $perPage);
        }

        $employerUids     = array_unique(array_column($jobs, 'employer'));
        $employerProfiles = [];
        foreach ($employerUids as $employerUid) {
            $snap = $this->database->collection('employers')->document($employerUid)->snapshot();
            $employerProfiles[$employerUid] = $snap->exists() ? $snap->data() : null;
        }
        foreach ($jobs as &$job) {
            $job['employer'] = $employerProfiles[$job['employer']] ?? null;
        }
        unset($job);

        $jobIds = array_column($jobs, 'id');
        $appliedJobIds = [];
        foreach (array_chunk($jobIds, 30) as $chunk) {
            $appDocs = $this->database->collection('applications')
                ->where('seekerUid', '=', $uid)
                ->where('jobId', 'in', $chunk)
                ->documents();
            foreach ($appDocs as $appDoc) {
                if ($appDoc->exists()) {
                    $appliedJobIds[] = $appDoc->data()['jobId'];
                }
            }
        }
        $appliedJobIds = array_flip($appliedJobIds);
        $jobs = array_values(array_filter($jobs, fn($job) => !isset($appliedJobIds[$job['id']])));

        $hasMore    = count($jobs) >= $perPage;
        $lastJob    = !empty($jobs) ? end($jobs) : null;
        $nextCursor = ($hasMore && $lastJob)
            ? [
                'expiresAt' => $lastJob['expiresAt'] instanceof Timestamp
                    ? $lastJob['expiresAt']->get()->format('c')
                    : $lastJob['expiresAt'],
                'createdAt' => $lastJob['createdAt'] instanceof Timestamp
                    ? $lastJob['createdAt']->get()->format('c')
                    : $lastJob['createdAt'],
            ]
            : null;

        $jobs = array_map([$this, 'formatDoc'], $jobs);

        return response()->json([
            'message'    => 'Jobs retrieved successfully',
            'data'       => $jobs,
            'hasMore'    => $hasMore,
            'nextCursor' => $nextCursor,
        ], 200);
    }

    public function employerArchivedJobs(Request $request)
    {
        $uid = $request->authUid;

        if (!$uid) {
            return response()->json(['error' => 'UID not found'], 400);
        }

        if ($request->authRole !== 'employer') {
            return response()->json(['error' => 'Only employers can access this endpoint'], 403);
        }

        validator($request->all(), [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ])->validate();

        $limit        = (int) $request->input('limit', 20);
        $nowTimestamp = new Timestamp(Carbon::now()->toDateTimeImmutable());
        // Epoch sentinel: any non-null server timestamp will be greater than this
        $epoch        = new Timestamp(Carbon::createFromDate(2000, 1, 1)->toDateTimeImmutable());
        $col          = $this->database->collection('jobs');
        $seen         = [];
        $allJobs      = [];

        $queries = [
            // Deleted (soft-archived) jobs — deletedAt is a timestamp, not null
            $col->where('employer', '=', $uid)
                ->where('deletedAt', '>', $epoch)
                ->orderBy('deletedAt', 'desc')
                ->limit($limit),

            // Filled jobs (hired / completed) — not deleted
            $col->where('employer', '=', $uid)
                ->where('deletedAt', '=', null)
                ->where('filledAt', '>', $epoch)
                ->orderBy('filledAt', 'desc')
                ->limit($limit),

            // Expired jobs — not filled, not deleted
            $col->where('employer', '=', $uid)
                ->where('deletedAt', '=', null)
                ->where('filledAt', '=', null)
                ->where('expiresAt', '<', $nowTimestamp)
                ->orderBy('expiresAt', 'desc')
                ->limit($limit),
        ];

        foreach ($queries as $query) {
            foreach ($query->documents() as $doc) {
                if ($doc->exists() && !isset($seen[$doc->id()])) {
                    $seen[$doc->id()] = true;
                    $data             = $doc->data();
                    $data['id']       = $doc->id();
                    $allJobs[]        = $data;
                }
            }
        }

        // Batch-fetch hired applications
        $hiredAppIds = array_unique(array_filter(array_column($allJobs, 'hiredApplicationId')));
        $hiredApps   = [];
        foreach ($hiredAppIds as $appId) {
            $snap              = $this->database->collection('applications')->document($appId)->snapshot();
            $hiredApps[$appId] = $snap->exists() ? array_merge($snap->data(), ['id' => $snap->id()]) : null;
        }

        // Batch-fetch hired seekers
        $hiredSeekerUids = array_unique(array_filter(
            array_map(fn($a) => $a['seekerUid'] ?? null, $hiredApps)
        ));
        $hiredSeekers = [];
        foreach ($hiredSeekerUids as $seekerUid) {
            $snap                    = $this->database->collection('seekers')->document($seekerUid)->snapshot();
            $hiredSeekers[$seekerUid] = $snap->exists() ? $snap->data() : null;
        }

        foreach ($allJobs as &$job) {
            $appId = $job['hiredApplicationId'] ?? null;
            $app   = $appId ? ($hiredApps[$appId] ?? null) : null;

            if ($app) {
                $seekerData          = $hiredSeekers[$app['seekerUid']] ?? null;
                $job['hiredApplication'] = [
                    'id'                  => $app['id'],
                    'status'              => $app['status'],
                    'employerCompletedAt' => $app['employerCompletedAt'] ?? null,
                    'seekerCompletedAt'   => $app['seekerCompletedAt'] ?? null,
                    'seeker'              => $seekerData ? [
                        'firstName' => $seekerData['firstName'] ?? null,
                        'lastName'  => $seekerData['lastName']  ?? null,
                    ] : null,
                ];
            } else {
                $job['hiredApplication'] = null;
            }
        }
        unset($job);

        usort($allJobs, function ($a, $b) {
            $ts = fn($j) => ($j['updatedAt'] instanceof Timestamp)
                ? $j['updatedAt']->get()->getTimestamp()
                : 0;
            return $ts($b) <=> $ts($a);
        });

        $allJobs = array_map([$this, 'formatDoc'], $allJobs);

        return response()->json([
            'message' => 'Archived jobs retrieved successfully',
            'data'    => $allJobs,
        ], 200);
    }

    public function index(Request $request)
    {
        $uid = $request->authUid;

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

        // Employer viewing their own jobs: show filled jobs too so hired applicants are visible
        $isOwnerView = $uid && $request->input('employer') === $uid
            && $request->authRole === 'employer';

        $query = $this->database->collection('jobs');

        $nowTimestamp = new Timestamp(Carbon::now()->toDateTimeImmutable());
        $query = $query->where('deletedAt',   '=', null)
                       ->where('completedAt', '=', null);
        if (!$isOwnerView) {
            $query = $query->where('filledAt', '=', null);
            $query = $query->where('expiresAt', '>', $nowTimestamp);
        }

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

        if ($isOwnerView) {
            $hiredAppIds = array_filter(array_column($jobs, 'hiredApplicationId'));
            $hiredApps   = [];
            foreach (array_unique($hiredAppIds) as $appId) {
                $snap = $this->database->collection('applications')->document($appId)->snapshot();
                $hiredApps[$appId] = $snap->exists() ? array_merge($snap->data(), ['id' => $snap->id()]) : null;
            }

            $hiredSeekerUids = array_unique(array_filter(
                array_map(fn($a) => $a['seekerUid'] ?? null, $hiredApps)
            ));
            $hiredSeekers = [];
            foreach ($hiredSeekerUids as $seekerUid) {
                $snap = $this->database->collection('seekers')->document($seekerUid)->snapshot();
                $hiredSeekers[$seekerUid] = $snap->exists() ? $snap->data() : null;
            }

            foreach ($jobs as &$job) {
                $appId = $job['hiredApplicationId'] ?? null;
                $app   = $appId ? ($hiredApps[$appId] ?? null) : null;

                if ($app && ($app['status'] ?? 0) >= 5) {
                    $seekerData = $hiredSeekers[$app['seekerUid']] ?? null;
                    $job['hiredApplication'] = [
                        'id'                  => $app['id'],
                        'status'              => $app['status'],
                        'employerCompletedAt' => $app['employerCompletedAt'] ?? null,
                        'seeker'              => $seekerData ? [
                            'firstName' => $seekerData['firstName'] ?? null,
                            'lastName'  => $seekerData['lastName']  ?? null,
                        ] : null,
                    ];
                } else {
                    $job['hiredApplication'] = null;
                }
            }
            unset($job);
        }

        $jobs = array_map([$this, 'formatDoc'], $jobs);

        return response()->json([
            'message' => 'Jobs retrieved successfully',
            'data'    => $jobs,
        ], 200);
    }

}
