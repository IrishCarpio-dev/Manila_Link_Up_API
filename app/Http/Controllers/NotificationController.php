<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Google\Cloud\Firestore\FieldValue;
use Google\Cloud\Core\Timestamp;
use Carbon\Carbon;
use Kreait\Laravel\Firebase\Facades\Firebase;

class NotificationController extends Controller
{
    protected $database;

    public function __construct()
    {
        $this->database = Firebase::firestore()->database();
    }

    public function list(Request $request)
    {
        $uid = $request->authUid;

        validator($request->all(), [
            'limit'      => ['sometimes', 'integer', 'min:1', 'max:50'],
            'startAfter' => ['sometimes', 'string'],
            'unreadOnly' => ['sometimes', 'boolean'],
        ])->validate();

        $query = $this->database
            ->collection('notifications')
            ->where('uid', '=', $uid);

        if ($request->boolean('unreadOnly')) {
            $query = $query->where('readAt', '=', null);
        }

        $query = $query->orderBy('createdAt', 'DESC');

        if ($request->filled('startAfter')) {
            $cursor = new Timestamp(Carbon::parse($request->startAfter)->toDateTimeImmutable());
            $query  = $query->startAfter([$cursor]);
        }

        $perPage   = (int) $request->input('limit', 20);
        $documents = $query->limit($perPage)->documents();

        $notifications = [];
        foreach ($documents as $doc) {
            if ($doc->exists()) {
                $data       = $doc->data();
                $data['id'] = $doc->id();
                $notifications[] = $data;
            }
        }

        return response()->json([
            'message' => 'Notifications retrieved successfully',
            'data'    => $notifications,
        ], 200);
    }

    public function markRead(Request $request)
    {
        $uid = $request->authUid;

        validator($request->all(), [
            'notificationId' => ['sometimes', 'string'],
            'all'            => ['sometimes', 'boolean'],
        ])->validate();

        if ($request->boolean('all')) {
            $docs = $this->database
                ->collection('notifications')
                ->where('uid', '=', $uid)
                ->where('readAt', '=', null)
                ->documents();

            $batch = $this->database->batch();
            foreach ($docs as $doc) {
                if ($doc->exists()) {
                    $batch->update($doc->reference(), [
                        ['path' => 'readAt', 'value' => FieldValue::serverTimestamp()],
                    ]);
                }
            }
            $batch->commit();

            return response()->json(['message' => 'All notifications marked as read'], 200);
        }

        if (!$request->filled('notificationId')) {
            return response()->json(['error' => 'Provide notificationId or set all=true'], 422);
        }

        $docRef  = $this->database->collection('notifications')->document($request->notificationId);
        $docSnap = $docRef->snapshot();

        if (!$docSnap->exists()) {
            return response()->json(['error' => 'Notification not found'], 404);
        }

        if ($docSnap->data()['uid'] !== $uid) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $docRef->update([
            ['path' => 'readAt', 'value' => FieldValue::serverTimestamp()],
        ]);

        return response()->json(['message' => 'Notification marked as read'], 200);
    }

    public function unreadCount(Request $request)
    {
        $uid = $request->authUid;

        $docs = $this->database
            ->collection('notifications')
            ->where('uid', '=', $uid)
            ->where('readAt', '=', null)
            ->documents();

        $count = 0;
        foreach ($docs as $doc) {
            if ($doc->exists()) {
                $count++;
            }
        }

        return response()->json(['count' => $count], 200);
    }
}
