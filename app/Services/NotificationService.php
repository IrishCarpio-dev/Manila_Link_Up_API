<?php

namespace App\Services;

use Google\Cloud\Firestore\FieldValue;
use Kreait\Laravel\Firebase\Facades\Firebase;
use App\Services\FcmNotifier;

class NotificationService
{
    public static function notify(string $uid, string $type, string $title, string $body, array $data = []): void
    {
        $database = Firebase::firestore()->database();

        $payload = array_merge($data, ['type' => $type]);

        $database->collection('notifications')->add([
            'uid'       => $uid,
            'type'      => $type,
            'title'     => $title,
            'body'      => $body,
            'data'      => $payload,
            'readAt'    => null,
            'createdAt' => FieldValue::serverTimestamp(),
        ]);

        FcmNotifier::sendToUser($uid, $title, $body, $payload);
    }
}
