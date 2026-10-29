package site.pkpay.smsforwarder;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

/** After a reboot, resume sending anything still in the outbox. */
public class BootReceiver extends BroadcastReceiver {
    @Override
    public void onReceive(Context context, Intent intent) {
        if (!Intent.ACTION_BOOT_COMPLETED.equals(intent.getAction())) {
            return;
        }
        Outbox outbox = new Outbox(context);
        try {
            if (outbox.count(Outbox.PENDING) > 0) {
                ForwardJobService.schedule(context);
            }
        } finally {
            outbox.close();
        }
    }
}
