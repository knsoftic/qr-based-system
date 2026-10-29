package site.pkpay.smsforwarder;

import android.Manifest;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.telephony.SubscriptionInfo;
import android.telephony.SubscriptionManager;

/** Which SIM received an SMS (slot is best effort; carrier name needs READ_PHONE_STATE). */
final class SimInfo {
    final int slot;
    final String name;

    private SimInfo(int slot, String name) {
        this.slot = slot;
        this.name = name;
    }

    static SimInfo from(Context context, Intent intent) {
        int slot = intent.getIntExtra("android.telephony.extra.SLOT_INDEX", intent.getIntExtra("slot", -1));
        int subId = intent.getIntExtra("android.telephony.extra.SUBSCRIPTION_INDEX", intent.getIntExtra("subscription", -1));
        String name = null;

        if (subId >= 0 && context.checkSelfPermission(Manifest.permission.READ_PHONE_STATE) == PackageManager.PERMISSION_GRANTED) {
            try {
                SubscriptionManager manager = context.getSystemService(SubscriptionManager.class);
                SubscriptionInfo info = manager == null ? null : manager.getActiveSubscriptionInfo(subId);
                if (info != null) {
                    if (slot < 0) {
                        slot = info.getSimSlotIndex();
                    }
                    CharSequence carrier = info.getCarrierName() != null ? info.getCarrierName() : info.getDisplayName();
                    name = carrier == null ? null : carrier.toString();
                }
            } catch (SecurityException ignored) {
                // Permission revoked: send without SIM details.
            }
        }

        return new SimInfo(slot, name);
    }
}
