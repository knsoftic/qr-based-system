package site.pkpay.smsforwarder;

import android.content.ContentValues;
import android.content.Context;
import android.database.Cursor;
import android.database.sqlite.SQLiteDatabase;
import android.database.sqlite.SQLiteOpenHelper;

import java.util.ArrayList;
import java.util.List;
import java.util.UUID;

/**
 * Every SMS is saved here first, then sent. Unsent rows survive network
 * outages and reboots and are retried until the server accepts them.
 */
final class Outbox extends SQLiteOpenHelper {
    static final String PENDING = "pending";
    static final String SENT = "sent";
    static final String FAILED = "failed";

    private static final int KEEP_SENT = 300;

    static final class Item {
        long id;
        String uuid;
        String sender;
        String body;
        long receivedAt;
        int simSlot;
        String simName;
        String status;
        int attempts;
        String lastError;
    }

    Outbox(Context context) {
        super(context.getApplicationContext(), "outbox.db", null, 1);
    }

    @Override
    public void onCreate(SQLiteDatabase db) {
        db.execSQL("CREATE TABLE messages ("
                + "id INTEGER PRIMARY KEY AUTOINCREMENT,"
                + "uuid TEXT NOT NULL UNIQUE,"
                + "sender TEXT NOT NULL,"
                + "body TEXT NOT NULL,"
                + "received_at INTEGER NOT NULL,"
                + "sim_slot INTEGER NOT NULL DEFAULT -1,"
                + "sim_name TEXT,"
                + "status TEXT NOT NULL,"
                + "attempts INTEGER NOT NULL DEFAULT 0,"
                + "last_error TEXT,"
                + "created_at INTEGER NOT NULL)");
        db.execSQL("CREATE INDEX messages_status ON messages(status, id)");
    }

    @Override
    public void onUpgrade(SQLiteDatabase db, int oldVersion, int newVersion) {
        // Version 1 only.
    }

    String add(String sender, String body, long receivedAt, int simSlot, String simName) {
        String uuid = UUID.randomUUID().toString();
        ContentValues values = new ContentValues();
        values.put("uuid", uuid);
        values.put("sender", sender == null ? "unknown" : sender);
        values.put("body", body == null ? "" : body);
        values.put("received_at", receivedAt);
        values.put("sim_slot", simSlot);
        values.put("sim_name", simName);
        values.put("status", PENDING);
        values.put("created_at", System.currentTimeMillis());
        getWritableDatabase().insert("messages", null, values);
        return uuid;
    }

    List<Item> pending(int limit) {
        return query("status = ?", new String[]{PENDING}, "id ASC", limit);
    }

    List<Item> recent(int limit) {
        return query(null, null, "id DESC", limit);
    }

    int count(String status) {
        try (Cursor c = getReadableDatabase().rawQuery("SELECT COUNT(*) FROM messages WHERE status = ?", new String[]{status})) {
            return c.moveToFirst() ? c.getInt(0) : 0;
        }
    }

    void markSent(long id) {
        update(id, SENT, null);
        getWritableDatabase().execSQL("DELETE FROM messages WHERE status = ? AND id NOT IN (SELECT id FROM messages WHERE status = ? ORDER BY id DESC LIMIT " + KEEP_SENT + ")", new Object[]{SENT, SENT});
    }

    void markRetry(long id, String error) {
        update(id, PENDING, error);
    }

    void markFailed(long id, String error) {
        update(id, FAILED, error);
    }

    private void update(long id, String status, String error) {
        getWritableDatabase().execSQL(
                "UPDATE messages SET status = ?, last_error = ?, attempts = attempts + 1 WHERE id = ?",
                new Object[]{status, error, id});
    }

    private List<Item> query(String where, String[] args, String order, int limit) {
        List<Item> items = new ArrayList<>();
        try (Cursor c = getReadableDatabase().query("messages", null, where, args, null, null, order, String.valueOf(limit))) {
            while (c.moveToNext()) {
                Item item = new Item();
                item.id = c.getLong(c.getColumnIndexOrThrow("id"));
                item.uuid = c.getString(c.getColumnIndexOrThrow("uuid"));
                item.sender = c.getString(c.getColumnIndexOrThrow("sender"));
                item.body = c.getString(c.getColumnIndexOrThrow("body"));
                item.receivedAt = c.getLong(c.getColumnIndexOrThrow("received_at"));
                item.simSlot = c.getInt(c.getColumnIndexOrThrow("sim_slot"));
                item.simName = c.getString(c.getColumnIndexOrThrow("sim_name"));
                item.status = c.getString(c.getColumnIndexOrThrow("status"));
                item.attempts = c.getInt(c.getColumnIndexOrThrow("attempts"));
                item.lastError = c.getString(c.getColumnIndexOrThrow("last_error"));
                items.add(item);
            }
        }
        return items;
    }
}
