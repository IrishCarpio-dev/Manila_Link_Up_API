<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Google\Cloud\Firestore\FieldValue;
use Kreait\Laravel\Firebase\Facades\Firebase;

class SeekerPreferencesController extends Controller
{
    protected $database;

    public function __construct()
    {
        $this->database = Firebase::firestore()->database();
    }

    public function show(Request $request)
    {
        $uid = $request->authUid;

        if (!$uid) {
            return response()->json(['error' => 'UID not found'], 400);
        }

        $snap = $this->database->collection('seekers')->document($uid)->snapshot();

        if (!$snap->exists()) {
            return response()->json(['error' => 'Seeker not found'], 404);
        }

        $data = $snap->data();

        return response()->json([
            'message' => 'Preferences retrieved successfully',
            'data'    => $data['preferences'] ?? [],
        ], 200);
    }

    public function upsert(Request $request)
    {
        $uid = $request->authUid;

        if (!$uid) {
            return response()->json(['error' => 'UID not found'], 400);
        }

        $seekerRef = $this->database->collection('seekers')->document($uid);

        if (!$seekerRef->snapshot()->exists()) {
            return response()->json(['error' => 'Seeker not found'], 404);
        }

        $request->validate([
            'preferredSalary'   => 'sometimes|numeric|min:0',
            'preferredDuration' => 'sometimes|string|max:100',
            'preferredLocation' => 'sometimes|string|max:255',
            'serviceTags'       => 'sometimes|array|max:10',
            'serviceTags.*'     => 'sometimes|string',
        ]);

        if ($request->has('serviceTags')) {
            foreach ($request->serviceTags as $tagId) {
                $tagSnap = $this->database->collection('serviceTags')->document($tagId)->snapshot();

                if (!$tagSnap->exists()) {
                    return response()->json(['error' => "Service tag '{$tagId}' not found"], 422);
                }

                if (!($tagSnap->data()['isActive'] ?? false)) {
                    return response()->json(['error' => "Service tag '{$tagId}' is inactive"], 422);
                }
            }
        }

        $updates = [];

        if ($request->filled('preferredSalary')) {
            $updates[] = ['path' => 'preferences.preferredSalary', 'value' => (float) $request->preferredSalary];
        }
        if ($request->filled('preferredDuration')) {
            $updates[] = ['path' => 'preferences.preferredDuration', 'value' => $request->preferredDuration];
        }
        if ($request->filled('preferredLocation')) {
            $updates[] = ['path' => 'preferences.preferredLocation', 'value' => $request->preferredLocation];
        }
        if ($request->has('serviceTags')) {
            $updates[] = ['path' => 'preferences.serviceTags', 'value' => $request->serviceTags];
        }

        if (empty($updates)) {
            return response()->json(['error' => 'No preference fields provided'], 422);
        }

        $updates[] = ['path' => 'updatedAt', 'value' => FieldValue::serverTimestamp()];

        $seekerRef->update($updates);

        $updated = $seekerRef->snapshot()->data();

        return response()->json([
            'message' => 'Preferences updated successfully',
            'data'    => $updated['preferences'] ?? [],
        ], 200);
    }
}
