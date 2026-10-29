package site.pkpay.smsforwarder;

import android.content.Context;
import android.content.SharedPreferences;

import java.util.Locale;

/** App settings (stored privately on the phone). */
final class Prefs {
    private static final String FILE = "settings";

    private Prefs() {
    }

    private static SharedPreferences of(Context context) {
        return context.getSharedPreferences(FILE, Context.MODE_PRIVATE);
    }

    static String serverUrl(Context context) {
        return of(context).getString("server_url", BuildConfig.DEFAULT_SERVER_URL);
    }

    static String deviceKey(Context context) {
        return of(context).getString("device_key", "");
    }

    /** Comma or newline separated sender names / numbers; empty = forward every SMS. */
    static String senderFilter(Context context) {
        return of(context).getString("sender_filter", "");
    }

    static boolean enabled(Context context) {
        return of(context).getBoolean("enabled", true);
    }

    static void save(Context context, String serverUrl, String deviceKey, String senderFilter, boolean enabled) {
        of(context).edit()
                .putString("server_url", trimTrailingSlash(serverUrl.trim()))
                .putString("device_key", deviceKey.trim())
                .putString("sender_filter", senderFilter.trim())
                .putBoolean("enabled", enabled)
                .apply();
    }

    static boolean isConfigured(Context context) {
        return !serverUrl(context).isEmpty() && !deviceKey(context).isEmpty();
    }

    /** True when the sender matches the filter (case-insensitive "contains"), or there is no filter. */
    static boolean allowsSender(Context context, String sender) {
        String filter = senderFilter(context);
        if (filter.isEmpty()) {
            return true;
        }
        String from = sender == null ? "" : sender.toLowerCase(Locale.ROOT);
        for (String part : filter.split("[,\\n]")) {
            String wanted = part.trim().toLowerCase(Locale.ROOT);
            if (!wanted.isEmpty() && from.contains(wanted)) {
                return true;
            }
        }
        return false;
    }

    static String trimTrailingSlash(String url) {
        while (url.endsWith("/")) {
            url = url.substring(0, url.length() - 1);
        }
        return url;
    }
}
