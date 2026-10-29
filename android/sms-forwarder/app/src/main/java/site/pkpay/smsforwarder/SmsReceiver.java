package site.pkpay.smsforwarder;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.os.SystemClock;
import android.provider.Telephony;
import android.telephony.SmsMessage;

import java.util.LinkedHashMap;
import java.util.Map;

/**
 * Called by Android for every incoming SMS. Long SMS arrive in parts in one
 * broadcast; they are joined per sender. Each SMS is stored in the outbox
 * first and then sent right away (with a background retry if that fails).
 */
public class SmsReceiver extends BroadcastReceiver {
    private static final long SEND_NOW_BUDGET_MS = 8_000;

    @Override
    public void onReceive(Context context, Intent intent) {
        if (!Telephony.Sms.Intents.SMS_RECEIVED_ACTION.equals(intent.getAction()) || !Prefs.enabled(context)) {
            return;
        }

        SmsMessage[] parts = Telephony.Sms.Intents.getMessagesFromIntent(intent);
        if (parts == null || parts.length == 0) {
            return;
        }

        Map<String, StringBuilder> bodies = new LinkedHashMap<>();
        Map<String, Long> times = new LinkedHashMap<>();
        for (SmsMessage part : parts) {
            if (part == null) {
                continue;
            }
            String sender = part.getDisplayOriginatingAddress();
            if (sender == null) {
                sender = "unknown";
            }
            StringBuilder body = bodies.get(sender);
            if (body == null) {
                body = new StringBuilder();
                bodies.put(sender, body);
                // Network (SMSC) timestamp: when the SMS was sent; fall back to now.
                long sentAt = part.getTimestampMillis();
                times.put(sender, sentAt > 0 ? sentAt : System.currentTimeMillis());
            }
            String text = part.getDisplayMessageBody();
            if (text != null) {
                body.append(text);
            }
        }

        SimInfo sim = SimInfo.from(context, intent);
        Outbox outbox = new Outbox(context);
        boolean queued = false;
        try {
            for (Map.Entry<String, StringBuilder> entry : bodies.entrySet()) {
                if (Prefs.allowsSender(context, entry.getKey())) {
                    outbox.add(entry.getKey(), entry.getValue().toString(), times.get(entry.getKey()), sim.slot, sim.name);
                    queued = true;
                }
            }
        } finally {
            outbox.close();
        }

        if (!queued) {
            return;
        }

        final Context app = context.getApplicationContext();
        final PendingResult pending = goAsync();
        new Thread(() -> {
            try {
                if (!Forwarder.flush(app, SystemClock.elapsedRealtime() + SEND_NOW_BUDGET_MS)) {
                    ForwardJobService.schedule(app);
                }
            } catch (Throwable t) {
                ForwardJobService.schedule(app);
            } finally {
                pending.finish();
            }
        }, "sms-forward").start();
    }
}
