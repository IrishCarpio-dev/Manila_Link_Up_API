<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Google\Cloud\Firestore\FieldValue;
use Google\Cloud\Core\Timestamp;
use Carbon\Carbon;
use Kreait\Laravel\Firebase\Facades\Firebase;
use App\Services\FcmNotifier;

class RatingController extends Controller
{
    protected $database;

    public function __construct()
    {
        $this->database = Firebase::firestore()->database();
    }

    public function submit(Request $request)
    {
        $uid = $request->authUid;

        validator($request->all(), [
            'applicationId' => ['required', 'string'],
            'score'         => ['required', 'integer', 'min:1', 'max:5'],
            'comment'       => ['sometimes', 'string', 'max:1000'],
        ])->validate();

        $appSnap = $this->database->collection('applications')->document($request->applicationId)->snapshot();

        if (!$appSnap->exists()) {
            return response()->json(['error' => 'Application not found'], 404);
        }

        $app = $appSnap->data();

        if ($app['status'] !== 6) {
            return response()->json(['error' => 'Ratings are only available for completed jobs'], 422);
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

        $raterRole = $isSeeker ? 'seeker' : 'employer';
        $rateeRole = $isSeeker ? 'employer' : 'seeker';
        $rateeUid  = $isSeeker ? $job['employer'] : $app['seekerUid'];

        // Check for existing rating
        $existingDocs = $this->database
            ->collection('ratings')
            ->where('applicationId', '=', $request->applicationId)
            ->where('raterRole', '=', $raterRole)
            ->limit(1)
            ->documents();

        $existingDoc = null;
        foreach ($existingDocs as $doc) {
            if ($doc->exists()) {
                $existingDoc = $doc;
                break;
            }
        }

        if ($existingDoc !== null) {
            $existingData  = $existingDoc->data();
            $createdAt     = $existingData['createdAt'];
            $createdAtTime = $createdAt instanceof Timestamp
                ? Carbon::createFromTimestamp($createdAt->get()->getTimestamp())
                : Carbon::parse($createdAt);

            if ($createdAtTime->diffInHours(Carbon::now()) >= 24) {
                return response()->json(['error' => 'Rating can no longer be edited after 24 hours'], 422);
            }

            $scoreDelta = (int) $request->score - (int) $existingData['score'];

            $existingDoc->reference()->update([
                ['path' => 'score',     'value' => (int) $request->score],
                ['path' => 'comment',   'value' => $request->input('comment', '')],
                ['path' => 'updatedAt', 'value' => FieldValue::serverTimestamp()],
            ]);

            $this->syncUserRatingStats($rateeUid, $rateeRole, $scoreDelta, false);

            $result       = $existingDoc->reference()->snapshot()->data();
            $result['id'] = $existingDoc->id();

            return response()->json([
                'message' => 'Rating updated successfully',
                'data'    => $this->formatDoc($result),
            ], 200);
        }

        // New rating
        $ratingData = [
            'jobId'         => $app['jobId'],
            'applicationId' => $request->applicationId,
            'raterUid'      => $uid,
            'raterRole'     => $raterRole,
            'rateeUid'      => $rateeUid,
            'rateeRole'     => $rateeRole,
            'score'         => (int) $request->score,
            'comment'       => $request->input('comment', ''),
            'createdAt'     => FieldValue::serverTimestamp(),
            'updatedAt'     => FieldValue::serverTimestamp(),
        ];

        $docRef           = $this->database->collection('ratings')->add($ratingData);
        $ratingData['id'] = $docRef->id();

        $this->syncUserRatingStats($rateeUid, $rateeRole, (int) $request->score, true);

        FcmNotifier::sendToUser(
            $rateeUid,
            'New Rating',
            'You received a new rating!',
            ['type' => 'rating_received', 'applicationId' => $request->applicationId]
        );

        return response()->json([
            'message' => 'Rating submitted successfully',
            'data'    => $ratingData,
        ], 201);
    }

    private function computeBayesianAvg(int $count, float $sum, float $globalMean): float
    {
        $C = 5;
        return round(($C * $globalMean + $sum) / ($C + $count), 2);
    }

    private function syncUserRatingStats(string $rateeUid, string $rateeRole, int $scoreDelta, bool $isNew): void
    {
        $collection = $rateeRole === 'seeker' ? 'seekers' : 'employers';
        $userRef    = $this->database->collection($collection)->document($rateeUid);
        $userData   = $userRef->snapshot()->data() ?? [];

        $newCount = ($userData['ratingCount'] ?? 0) + ($isNew ? 1 : 0);
        $newSum   = (float) ($userData['ratingSum'] ?? 0) + $scoreDelta;

        $globalRef  = $this->database->collection('globalStats')->document('ratings');
        $globalSnap = $globalRef->snapshot();
        $globalData = $globalSnap->exists() ? ($globalSnap->data() ?? []) : [];

        $countKey       = $rateeRole . 'Count';
        $sumKey         = $rateeRole . 'Sum';
        $newGlobalCount = ($globalData[$countKey] ?? 0) + ($isNew ? 1 : 0);
        $newGlobalSum   = (float) ($globalData[$sumKey] ?? 0) + $scoreDelta;
        $globalMean     = $newGlobalCount > 0 ? $newGlobalSum / $newGlobalCount : 0.0;

        $bayesianAvg = $newCount > 0 ? $this->computeBayesianAvg($newCount, $newSum, $globalMean) : 0.0;

        $userRef->update([
            ['path' => 'ratingCount', 'value' => $newCount],
            ['path' => 'ratingSum',   'value' => $newSum],
            ['path' => 'bayesianAvg', 'value' => $bayesianAvg],
        ]);

        $globalRef->set([
            $countKey => $newGlobalCount,
            $sumKey   => $newGlobalSum,
        ], ['merge' => true]);
    }

    public function list(Request $request)
    {
        validator($request->all(), [
            'userUid'    => ['required', 'string'],
            'limit'      => ['sometimes', 'integer', 'min:1', 'max:50'],
            'startAfter' => ['sometimes', 'string'],
        ])->validate();

        $query = $this->database
            ->collection('ratings')
            ->where('rateeUid', '=', $request->userUid)
            ->orderBy('createdAt', 'DESC');

        if ($request->filled('startAfter')) {
            $cursor = new Timestamp(Carbon::parse($request->startAfter)->toDateTimeImmutable());
            $query  = $query->startAfter([$cursor]);
        }

        $perPage  = (int) $request->input('limit', 15);
        $docs     = $query->limit($perPage)->documents();
        $ratings  = [];

        foreach ($docs as $doc) {
            if ($doc->exists()) {
                $data       = $doc->data();
                $data['id'] = $doc->id();
                $ratings[]  = $data;
            }
        }

        // Batch-fetch rater profiles
        $raterUids = array_unique(array_column($ratings, 'raterUid'));
        $raters    = [];
        foreach ($raterUids as $raterUid) {
            $snap = $this->database->collection('seekers')->document($raterUid)->snapshot();
            if ($snap->exists()) {
                $raters[$raterUid] = $snap->data();
                continue;
            }
            $snap = $this->database->collection('employers')->document($raterUid)->snapshot();
            $raters[$raterUid] = $snap->exists() ? $snap->data() : null;
        }

        // Batch-fetch job titles
        $jobIds = array_unique(array_column($ratings, 'jobId'));
        $jobs   = [];
        foreach ($jobIds as $jobId) {
            $snap = $this->database->collection('jobs')->document($jobId)->snapshot();
            $jobs[$jobId] = $snap->exists() ? $snap->data() : null;
        }

        foreach ($ratings as &$rating) {
            $rater = $raters[$rating['raterUid']] ?? null;
            $job   = $jobs[$rating['jobId']] ?? null;

            $rating['rater'] = $rater ? [
                'uid'             => $rating['raterUid'],
                'name'            => $rater['fullName'] ?? (($rater['firstName'] ?? '') . ' ' . ($rater['lastName'] ?? '')),
                'profilePhotoUrl' => $rater['profilePhotoUrl'] ?? null,
            ] : null;

            $rating['job'] = $job ? [
                'id'    => $rating['jobId'],
                'title' => $job['title'] ?? null,
            ] : null;
        }
        unset($rating);

        $ratings = array_map([$this, 'formatDoc'], $ratings);

        return response()->json([
            'message' => 'Ratings retrieved successfully',
            'data'    => $ratings,
        ], 200);
    }
}
