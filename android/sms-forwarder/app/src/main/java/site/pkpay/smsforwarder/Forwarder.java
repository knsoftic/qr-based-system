package site.pkpay.smsforwarder;

import android.content.Context;
import android.os.Build;
import android.os.SystemClock;

import org.json.JSONException;
import org.json.JSONObject;

import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.List;

/** Sends queued SMS to the gateway: POST {server}/api/v1/sms with "Authorization: Bearer <device key>". */
final class Forwarder {
    static final class Result {
        final int code;
        final String message;

        Result(int code, String message) {
            this.code = code;
            this.message = message;
        }

        boolean ok() {
            return code >= 200 && code < 300;
        }

        /** The server refused the content itself: retrying the same data will not help. */
        boolean permanent() {
            return code == 400 || code == 413 || code == 422;
        }
    }

    private Forwarder() {
    }

    /**
     * Sends pending SMS oldest first until the queue is empty, the network
     * fails or the deadline passes. Returns true when nothing is left to send.
     */
    static synchronized boolean flush(Context context, long deadlineElapsed) {
        if (!Prefs.isConfigured(context)) {
            return false;
        }

        Outbox outbox = new Outbox(context);
        try {
            while (SystemClock.elapsedRealtime() < deadlineElapsed) {
                List<Outbox.Item> batch = outbox.pending(20);
                if (batch.isEmpty()) {
                    return true;
                }
                for (Outbox.Item item : batch) {
                    if (SystemClock.elapsedRealtime() >= deadlineElapsed) {
                        return false;
                    }
                    Result result = post(context, "/api/v1/sms", payload(item));
                    if (result.ok()) {
                        outbox.markSent(item.id);
                    } else if (result.permanent()) {
                        outbox.markFailed(item.id, result.message);
                    } else {
                        // Network down, server error or wrong key: keep it and try again later.
                        outbox.markRetry(item.id, result.message);
                        return false;
                    }
                }
            }
            return outbox.count(Outbox.PENDING) == 0;
        } finally {
            outbox.close();
        }
    }

    static Result ping(Context context) {
        try {
            return post(context, "/api/v1/sms/ping", base());
        } catch (JSONException e) {
            return new Result(0, e.getMessage());
        }
    }

    private static JSONObject payload(Outbox.Item item) {
        try {
            JSONObject json = base();
            json.put("message_id", item.uuid);
            json.put("sender", item.sender);
            json.put("body", item.body);
            json.put("received_at", item.receivedAt);
            if (item.simSlot >= 0) {
                json.put("sim_slot", item.simSlot);
            }
            if (item.simName != null && !item.simName.isEmpty()) {
                json.put("sim_name", item.simName);
            }
            return json;
        } catch (JSONException e) {
            throw new IllegalStateException(e);
        }
    }

    private static JSONObject base() throws JSONException {
        JSONObject device = new JSONObject();
        device.put("manufacturer", Build.MANUFACTURER);
        device.put("model", Build.MODEL);
        device.put("android", Build.VERSION.RELEASE);

        JSONObject json = new JSONObject();
        json.put("app_version", BuildConfig.VERSION_NAME);
        json.put("device", device);
        return json;
    }

    private static Result post(Context context, String path, JSONObject json) {
        HttpURLConnection connection = null;
        try {
            URL url = new URL(Prefs.serverUrl(context) + path);
            connection = (HttpURLConnection) url.openConnection();
            connection.setConnectTimeout(10_000);
            connection.setReadTimeout(15_000);
            connection.setRequestMethod("POST");
            connection.setDoOutput(true);
            connection.setInstanceFollowRedirects(false);
            connection.setRequestProperty("Authorization", "Bearer " + Prefs.deviceKey(context));
            connection.setRequestProperty("Content-Type", "application/json; charset=utf-8");
            connection.setRequestProperty("Accept", "application/json");
            connection.setRequestProperty("User-Agent", "PKPaySmsForwarder/" + BuildConfig.VERSION_NAME);

            byte[] body = json.toString().getBytes(StandardCharsets.UTF_8);
            connection.setFixedLengthStreamingMode(body.length);
            try (OutputStream out = connection.getOutputStream()) {
                out.write(body);
            }

            int code = connection.getResponseCode();
            String response = read(code >= 400 ? connection.getErrorStream() : connection.getInputStream());
            return new Result(code, code + " " + shortMessage(response));
        } catch (IOException | RuntimeException e) {
            return new Result(0, e.getClass().getSimpleName() + ": " + e.getMessage());
        } finally {
            if (connection != null) {
                connection.disconnect();
            }
        }
    }

    private static String shortMessage(String response) {
        try {
            JSONObject json = new JSONObject(response);
            if (json.has("error")) {
                return json.getJSONObject("error").optString("message", response);
            }
            return json.optString("status", response);
        } catch (JSONException e) {
            return response.length() > 120 ? response.substring(0, 120) : response;
        }
    }

    private static String read(InputStream in) throws IOException {
        if (in == null) {
            return "";
        }
        try (InputStream stream = in; ByteArrayOutputStream out = new ByteArrayOutputStream()) {
            byte[] buffer = new byte[4096];
            int n;
            while ((n = stream.read(buffer)) != -1 && out.size() < 64_000) {
                out.write(buffer, 0, n);
            }
            return out.toString("UTF-8");
        }
    }
}
