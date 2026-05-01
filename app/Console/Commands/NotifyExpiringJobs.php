<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Google\Cloud\Firestore\FieldValue;
use Google\Cloud\Core\Timestamp;
use Carbon\Carbon;
use Kreait\Laravel\Firebase\Facades\Firebase;
use App\Services\NotificationService;

class NotifyExpiringJobs extends Command
{
    protected $signature   = 'notifications:expiring-jobs';
    protected $description = 'Notify employers of job posts expiring within 24 hours';

    public function handle(): void
    {
        $database = Firebase::firestore()->database();

        $now   = Carbon::now();
        $in24h = $now->copy()->addHours(24);

        $nowTs  = new Timestamp($now->toDateTimeImmutable());
        $in24Ts = new Timestamp($in24h->toDateTimeImmutable());

        $docs = $database
            ->collection('jobs')
            ->where('deletedAt', '=', null)
            ->where('filledAt',  '=', null)
            ->where('expiresAt', '>', $nowTs)
            ->where('expiresAt', '<=', $in24Ts)
            ->documents();

        $sent = 0;

        foreach ($docs as $doc) {
            if (!$doc->exists()) {
                continue;
            }

            $job = $doc->data();

            if (!empty($job['expiryNotifiedAt'])) {
                continue;
            }

            NotificationService::notify(
                $job['employer'],
                'job_expiring',
                'Job Post Expiring Soon',
                'Your job post "' . ($job['title'] ?? 'Untitled') . '" expires within 24 hours.',
                ['jobId' => $doc->id()]
            );

            $doc->reference()->update([
                ['path' => 'expiryNotifiedAt', 'value' => FieldValue::serverTimestamp()],
                ['path' => 'updatedAt',         'value' => FieldValue::serverTimestamp()],
            ]);

            $sent++;
        }

        $this->info("Sent {$sent} expiry notification(s).");
    }
}
