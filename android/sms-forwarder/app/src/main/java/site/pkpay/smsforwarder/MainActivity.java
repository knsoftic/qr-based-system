package site.pkpay.smsforwarder;

import android.Manifest;
import android.app.Activity;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.PowerManager;
import android.os.SystemClock;
import android.provider.Settings;
import android.text.format.DateFormat;
import android.view.View;
import android.widget.Button;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.Switch;
import android.widget.TextView;
import android.widget.Toast;

import java.util.Date;
import java.util.List;

/** Settings, permissions, connection test and the recent SMS log. */
public class MainActivity extends Activity {
    private static final int REQUEST_PERMISSIONS = 1;

    private EditText serverUrl;
    private EditText deviceKey;
    private EditText senderFilter;
    private Switch enabled;
    private TextView status;
    private LinearLayout recent;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);

        serverUrl = findViewById(R.id.server_url);
        deviceKey = findViewById(R.id.device_key);
        senderFilter = findViewById(R.id.sender_filter);
        enabled = findViewById(R.id.enabled);
        status = findViewById(R.id.status);
        recent = findViewById(R.id.recent);

        serverUrl.setText(Prefs.serverUrl(this));
        deviceKey.setText(Prefs.deviceKey(this));
        senderFilter.setText(Prefs.senderFilter(this));
        enabled.setChecked(Prefs.enabled(this));

        ((Button) findViewById(R.id.save)).setOnClickListener(v -> save());
        ((Button) findViewById(R.id.test)).setOnClickListener(v -> testConnection());
        ((Button) findViewById(R.id.send_test)).setOnClickListener(v -> sendTestSms());
        ((Button) findViewById(R.id.permission)).setOnClickListener(v -> requestSmsPermission());
        ((Button) findViewById(R.id.battery)).setOnClickListener(v -> openBatterySettings());
    }

    @Override
    protected void onResume() {
        super.onResume();
        refresh();
    }

    private boolean save() {
        String url = Prefs.trimTrailingSlash(serverUrl.getText().toString().trim());
        String key = deviceKey.getText().toString().trim();

        boolean secure = url.startsWith("https://") || (BuildConfig.DEBUG && url.startsWith("http://"));
        if (!secure || url.length() < 12) {
            serverUrl.setError(getString(R.string.error_url));
            return false;
        }
        if (!key.startsWith("dk_") || key.length() < 20) {
            deviceKey.setError(getString(R.string.error_key));
            return false;
        }

        Prefs.save(this, url, key, senderFilter.getText().toString(), enabled.isChecked());
        Toast.makeText(this, R.string.saved, Toast.LENGTH_SHORT).show();
        refresh();
        return true;
    }

    private void testConnection() {
        if (!save()) {
            return;
        }
        status.setText(R.string.testing);
        new Thread(() -> {
            Forwarder.Result result = Forwarder.ping(getApplicationContext());
            runOnUiThread(() -> {
                String text = result.ok() ? getString(R.string.connection_ok) : getString(R.string.connection_failed, result.message);
                Toast.makeText(this, text, Toast.LENGTH_LONG).show();
                refresh();
                status.setText(text + "\n\n" + status.getText());
            });
        }).start();
    }

    /** Queues a harmless test SMS so the whole path (outbox -> server -> SMS inbox) can be checked. */
    private void sendTestSms() {
        if (!save()) {
            return;
        }
        Outbox outbox = new Outbox(this);
        try {
            outbox.add("PKPAY-TEST", "Test message from " + Build.MANUFACTURER + " " + Build.MODEL
                    + " at " + DateFormat.format("yyyy-MM-dd HH:mm:ss", new Date()), System.currentTimeMillis(), -1, null);
        } finally {
            outbox.close();
        }
        new Thread(() -> {
            boolean done = Forwarder.flush(getApplicationContext(), SystemClock.elapsedRealtime() + 15_000);
            if (!done) {
                ForwardJobService.schedule(getApplicationContext());
            }
            runOnUiThread(() -> {
                Toast.makeText(this, done ? R.string.test_sent : R.string.test_queued, Toast.LENGTH_LONG).show();
                refresh();
            });
        }).start();
    }

    private void requestSmsPermission() {
        requestPermissions(new String[]{Manifest.permission.RECEIVE_SMS, Manifest.permission.READ_PHONE_STATE}, REQUEST_PERMISSIONS);
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, String[] permissions, int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        refresh();
    }

    private void openBatterySettings() {
        try {
            Intent intent = new Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, Uri.parse("package:" + getPackageName()));
            startActivity(intent);
        } catch (RuntimeException e) {
            startActivity(new Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS));
        }
    }

    private void refresh() {
        boolean smsGranted = checkSelfPermission(Manifest.permission.RECEIVE_SMS) == PackageManager.PERMISSION_GRANTED;
        PowerManager power = getSystemService(PowerManager.class);
        boolean batteryOk = power != null && power.isIgnoringBatteryOptimizations(getPackageName());

        Outbox outbox = new Outbox(this);
        List<Outbox.Item> items;
        int pending;
        int failed;
        try {
            items = outbox.recent(30);
            pending = outbox.count(Outbox.PENDING);
            failed = outbox.count(Outbox.FAILED);
        } finally {
            outbox.close();
        }

        StringBuilder text = new StringBuilder();
        text.append(smsGranted ? "✓ SMS access allowed" : "✗ SMS access not allowed: tap \"Allow SMS access\"").append('\n');
        text.append(Prefs.isConfigured(this) ? "✓ Server and key saved" : "✗ Enter server URL and device key").append('\n');
        text.append(Prefs.enabled(this) ? "✓ Forwarding is ON" : "✗ Forwarding is OFF").append('\n');
        text.append(batteryOk ? "✓ Background running allowed" : "! Tap \"Battery settings\" so no SMS is missed").append('\n');
        text.append("Waiting to send: ").append(pending).append(" · Failed: ").append(failed);
        status.setText(text);

        findViewById(R.id.permission).setVisibility(smsGranted ? View.GONE : View.VISIBLE);

        recent.removeAllViews();
        if (items.isEmpty()) {
            TextView empty = new TextView(this);
            empty.setText(R.string.no_sms);
            empty.setTextColor(getColor(R.color.muted));
            recent.addView(empty);
        }
        for (Outbox.Item item : items) {
            TextView row = (TextView) getLayoutInflater().inflate(R.layout.item_sms, recent, false);
            String mark = Outbox.SENT.equals(item.status) ? "✓ sent" : Outbox.FAILED.equals(item.status) ? "✗ failed" : "… waiting";
            String line = mark + " · " + item.sender + " · " + DateFormat.format("dd MMM HH:mm", new Date(item.receivedAt))
                    + "\n" + (item.body.length() > 120 ? item.body.substring(0, 120) + "…" : item.body);
            if (!Outbox.SENT.equals(item.status) && item.lastError != null) {
                line += "\n" + item.lastError;
            }
            row.setText(line);
            recent.addView(row);
        }
    }
}
