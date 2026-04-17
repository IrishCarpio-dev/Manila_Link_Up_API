<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Google\Cloud\Firestore\FieldValue;
use Google\Cloud\Core\Timestamp;
use Carbon\Carbon;
use Kreait\Laravel\Firebase\Facades\Firebase;
use App\Services\FcmNotifier;

class ChatController extends Controller
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

        // Firestore doesn't support OR queries across fields in a single query.
        // Fetch seeker-side and employer-side chats separately, merge, sort by lastMessageAt.
        $seekerChats   = $this->fetchChatsForField($uid, 'seekerUid', $request);
        $employerChats = $this->fetchChatsForField($uid, 'employerUid', $request);

        $allChats = array_merge($seekerChats, $employerChats);

        // Filter out hidden chats
        $allChats = array_filter($allChats, function ($chat) use ($uid) {
            return !in_array($uid, $chat['hiddenBy'] ?? []);
        });

        // Sort by lastMessageAt DESC
        usort($allChats, function ($a, $b) {
            $aTime = $a['lastMessageAt'] instanceof Timestamp ? $a['lastMessageAt']->get()->getTimestamp() : 0;
            $bTime = $b['lastMessageAt'] instanceof Timestamp ? $b['lastMessageAt']->get()->getTimestamp() : 0;
            return $bTime <=> $aTime;
        });

        $perPage  = (int) $request->input('limit', 20);
        $allChats = array_slice(array_values($allChats), 0, $perPage);

        // Batch-fetch counterpart profiles and job titles
        $jobIds      = array_unique(array_column($allChats, 'jobId'));
        $jobs        = [];
        foreach ($jobIds as $jobId) {
            $snap = $this->database->collection('jobs')->document($jobId)->snapshot();
            $jobs[$jobId] = $snap->exists() ? $snap->data() : null;
        }

        $counterpartUids = [];
        foreach ($allChats as $chat) {
            $counterpartUids[] = ($chat['seekerUid'] === $uid) ? $chat['employerUid'] : $chat['seekerUid'];
        }
        $counterpartUids = array_unique($counterpartUids);

        $profiles = [];
        foreach ($counterpartUids as $cUid) {
            $snap = $this->database->collection('seekers')->document($cUid)->snapshot();
            if ($snap->exists()) {
                $profiles[$cUid] = $snap->data();
                continue;
            }
            $snap = $this->database->collection('employers')->document($cUid)->snapshot();
            $profiles[$cUid] = $snap->exists() ? $snap->data() : null;
        }

        foreach ($allChats as &$chat) {
            $cUid          = ($chat['seekerUid'] === $uid) ? $chat['employerUid'] : $chat['seekerUid'];
            $profile       = $profiles[$cUid] ?? null;
            $job           = $jobs[$chat['jobId']] ?? null;

            $chat['counterpart'] = $profile ? [
                'uid'             => $cUid,
                'name'            => $profile['fullName'] ?? (($profile['firstName'] ?? '') . ' ' . ($profile['lastName'] ?? '')),
                'profilePhotoUrl' => $profile['profilePhotoUrl'] ?? null,
            ] : null;

            $chat['job'] = $job ? [
                'id'    => $chat['jobId'],
                'title' => $job['title'] ?? null,
            ] : null;

            $chat['unreadCount'] = ($chat['seekerUid'] === $uid)
                ? ($chat['unreadBySeeker'] ?? 0)
                : ($chat['unreadByEmployer'] ?? 0);
        }
        unset($chat);

        return response()->json([
            'message' => 'Chats retrieved successfully',
            'data'    => array_values($allChats),
        ], 200);
    }

    private function fetchChatsForField(string $uid, string $field, Request $request): array
    {
        $query = $this->database
            ->collection('chats')
            ->where($field, '=', $uid)
            ->orderBy('lastMessageAt', 'DESC');

        if ($request->filled('startAfter')) {
            $cursor = new Timestamp(Carbon::parse($request->startAfter)->toDateTimeImmutable());
            $query  = $query->startAfter([$cursor]);
        }

        $docs   = $query->limit((int) $request->input('limit', 20))->documents();
        $chats  = [];
        foreach ($docs as $doc) {
            if ($doc->exists()) {
                $data       = $doc->data();
                $data['id'] = $doc->id();
                $chats[]    = $data;
            }
        }
        return $chats;
    }

    public function messages(Request $request)
    {
        $uid = $request->authUid;

        validator($request->all(), [
            'chatId'     => ['required', 'string'],
            'limit'      => ['sometimes', 'integer', 'min:1', 'max:100'],
            'startAfter' => ['sometimes', 'string'],
        ])->validate();

        $chatSnap = $this->database->collection('chats')->document($request->chatId)->snapshot();

        if (!$chatSnap->exists()) {
            return response()->json(['error' => 'Chat not found'], 404);
        }

        $chat = $chatSnap->data();

        if ($chat['seekerUid'] !== $uid && $chat['employerUid'] !== $uid) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $query = $this->database
            ->collection('chats')
            ->document($request->chatId)
            ->collection('messages')
            ->orderBy('createdAt', 'DESC');

        if ($request->filled('startAfter')) {
            $cursor = new Timestamp(Carbon::parse($request->startAfter)->toDateTimeImmutable());
            $query  = $query->startAfter([$cursor]);
        }

        $perPage  = (int) $request->input('limit', 30);
        $docs     = $query->limit($perPage)->documents();
        $messages = [];

        foreach ($docs as $doc) {
            if ($doc->exists()) {
                $data       = $doc->data();
                $data['id'] = $doc->id();
                $messages[] = $data;
            }
        }

        return response()->json([
            'message' => 'Messages retrieved successfully',
            'data'    => $messages,
        ], 200);
    }

    public function send(Request $request)
    {
        $uid = $request->authUid;

        validator($request->all(), [
            'chatId' => ['required', 'string'],
            'text'   => ['required', 'string', 'max:2000'],
        ])->validate();

        $chatRef  = $this->database->collection('chats')->document($request->chatId);
        $chatSnap = $chatRef->snapshot();

        if (!$chatSnap->exists()) {
            return response()->json(['error' => 'Chat not found'], 404);
        }

        $chat = $chatSnap->data();

        if ($chat['seekerUid'] !== $uid && $chat['employerUid'] !== $uid) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $isSeeker     = $chat['seekerUid'] === $uid;
        $recipientUid = $isSeeker ? $chat['employerUid'] : $chat['seekerUid'];

        $messageData = [
            'senderUid' => $uid,
            'text'      => $request->text,
            'readAt'    => null,
            'createdAt' => FieldValue::serverTimestamp(),
        ];

        $msgRef = $chatRef->collection('messages')->add($messageData);

        $unreadField = $isSeeker ? 'unreadByEmployer' : 'unreadBySeeker';

        $chatRef->update([
            ['path' => 'lastMessage',   'value' => $request->text],
            ['path' => 'lastMessageAt', 'value' => FieldValue::serverTimestamp()],
            ['path' => $unreadField,    'value' => FieldValue::increment(1)],
            // Remove recipient from hiddenBy so the chat resurfaces
            ['path' => 'hiddenBy',      'value' => FieldValue::arrayRemove([$recipientUid])],
            ['path' => 'updatedAt',     'value' => FieldValue::serverTimestamp()],
        ]);

        FcmNotifier::sendToUser(
            $recipientUid,
            'New Message',
            $request->text,
            ['type' => 'chat_message', 'chatId' => $request->chatId, 'jobId' => $chat['jobId']]
        );

        $messageData['id'] = $msgRef->id();

        return response()->json([
            'message' => 'Message sent successfully',
            'data'    => $messageData,
        ], 201);
    }

    public function markRead(Request $request)
    {
        $uid = $request->authUid;

        validator($request->all(), [
            'chatId' => ['required', 'string'],
        ])->validate();

        $chatRef  = $this->database->collection('chats')->document($request->chatId);
        $chatSnap = $chatRef->snapshot();

        if (!$chatSnap->exists()) {
            return response()->json(['error' => 'Chat not found'], 404);
        }

        $chat = $chatSnap->data();

        if ($chat['seekerUid'] !== $uid && $chat['employerUid'] !== $uid) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $isSeeker    = $chat['seekerUid'] === $uid;
        $unreadField = $isSeeker ? 'unreadBySeeker' : 'unreadByEmployer';

        $chatRef->update([
            ['path' => $unreadField, 'value' => 0],
            ['path' => 'updatedAt',  'value' => FieldValue::serverTimestamp()],
        ]);

        // Mark individual unread messages as read
        $unread = $chatRef->collection('messages')
            ->where('senderUid', '!=', $uid)
            ->where('readAt', '=', null)
            ->documents();

        foreach ($unread as $doc) {
            if ($doc->exists()) {
                $doc->reference()->update([
                    ['path' => 'readAt', 'value' => FieldValue::serverTimestamp()],
                ]);
            }
        }

        return response()->json(['message' => 'Chat marked as read'], 200);
    }

    public function hide(Request $request)
    {
        $uid = $request->authUid;

        validator($request->all(), [
            'chatId' => ['required', 'string'],
        ])->validate();

        $chatRef  = $this->database->collection('chats')->document($request->chatId);
        $chatSnap = $chatRef->snapshot();

        if (!$chatSnap->exists()) {
            return response()->json(['error' => 'Chat not found'], 404);
        }

        $chat = $chatSnap->data();

        if ($chat['seekerUid'] !== $uid && $chat['employerUid'] !== $uid) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $chatRef->update([
            ['path' => 'hiddenBy',   'value' => FieldValue::arrayUnion([$uid])],
            ['path' => 'updatedAt',  'value' => FieldValue::serverTimestamp()],
        ]);

        return response()->json(['message' => 'Chat hidden successfully'], 200);
    }
}
