package site.pkpay.smsforwarder;

import android.app.job.JobInfo;
import android.app.job.JobParameters;
import android.app.job.JobScheduler;
import android.app.job.JobService;
import android.content.ComponentName;
import android.content.Context;
import android.os.SystemClock;

/** Background retry: runs when there is a network and backs off exponentially on failures. */
public class ForwardJobService extends JobService {
    private static final int JOB_ID = 1001;

    static void schedule(Context context) {
        JobScheduler scheduler = context.getSystemService(JobScheduler.class);
        if (scheduler == null) {
            return;
        }
        JobInfo job = new JobInfo.Builder(JOB_ID, new ComponentName(context, ForwardJobService.class))
                .setRequiredNetworkType(JobInfo.NETWORK_TYPE_ANY)
                .setBackoffCriteria(30_000, JobInfo.BACKOFF_POLICY_EXPONENTIAL)
                .setPersisted(true)
                .build();
        scheduler.schedule(job);
    }

    @Override
    public boolean onStartJob(JobParameters params) {
        new Thread(() -> {
            boolean done = false;
            try {
                done = Forwarder.flush(getApplicationContext(), SystemClock.elapsedRealtime() + 8 * 60_000);
            } catch (Throwable ignored) {
                // Retried with backoff.
            }
            jobFinished(params, !done);
        }, "sms-forward-job").start();
        return true;
    }

    @Override
    public boolean onStopJob(JobParameters params) {
        return true;
    }
}
