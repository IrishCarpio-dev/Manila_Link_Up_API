<?php

namespace App\Services;

use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Laravel\Firebase\Facades\Firebase;

class FcmNotifier
{
    public static function sendToUser(string $uid, string $title, string $body, array $data = []): void
    {
        $database = Firebase::firestore()->database();

        $tokenDocs = $database
            ->collection('devices')
            ->where('uid', '=', $uid)
            ->documents();

        $tokens = [];
        foreach ($tokenDocs as $doc) {
            if ($doc->exists()) {
                $tokens[] = $doc->data()['fcmToken'];
            }
        }

        if (empty($tokens)) {
            return;
        }

        $messaging = Firebase::messaging();
        $notification = Notification::create($title, $body);

        $invalidTokens = [];

        foreach ($tokens as $token) {
            try {
                $message = CloudMessage::withTarget('token', $token)
                    ->withNotification($notification)
                    ->withData($data);
                $messaging->send($message);
            } catch (\Kreait\Firebase\Exception\Messaging\NotFound $e) {
                $invalidTokens[] = $token;
            } catch (\Throwable $e) {
                \Log::warning('FcmNotifier: failed to send to token', ['token' => $token, 'error' => $e->getMessage()]);
            }
        }

        // Prune invalid tokens
        if (!empty($invalidTokens)) {
            $docs = $database->collection('devices')->where('uid', '=', $uid)->documents();
            foreach ($docs as $doc) {
                if ($doc->exists() && in_array($doc->data()['fcmToken'], $invalidTokens)) {
                    $doc->reference()->delete();
                }
            }
        }
    }
}
