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
        ])->validate();

        $query = $this->database
            ->collection('notifications')
            ->where('uid', '=', $uid)
            ->orderBy('createdAt', 'DESC');

        if ($request->filled('startAfter')) {
            $cursor = new Timestamp(Carbon::parse($request->startAfter)->toDateTimeImmutable());
            $query  = $query->startAfter([$cursor]);
        }

        $perPage   = (int) $request->input('limit', 20);
        $documents = $query->limit($perPage)->documents();

        $notifications = [];
        foreach ($documents as $doc) {
            if ($doc->exists()) {
                $notifications[] = $this->format($doc->id(), $doc->data());
            }
        }

        return response()->json(['data' => $notifications], 200);
    }

    public function readAll(Request $request)
    {
        $uid = $request->authUid;

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

    private function format(string $id, array $data): array
    {
        $createdAt = $data['createdAt'] ?? null;

        return [
            'id'         => $id,
            'type'       => $data['type'] ?? null,
            'title'      => $data['title'] ?? null,
            'body'       => $data['body'] ?? null,
            'is_read'    => ($data['readAt'] ?? null) !== null,
            'created_at' => $createdAt instanceof Timestamp
                ? $createdAt->get()->format('c')
                : $createdAt,
            'data'       => $data['data'] ?? [],
        ];
    }
}
