package ru.triadamendeleeva.app;

import android.app.Activity;
import android.app.DownloadManager;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.net.Uri;
import android.os.Build;
import android.os.Handler;
import android.os.Looper;
import android.provider.Settings;
import android.widget.Toast;

import androidx.appcompat.app.AlertDialog;
import androidx.core.content.ContextCompat;
import androidx.core.content.FileProvider;

import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.File;
import java.io.InputStreamReader;
import java.net.HttpURLConnection;
import java.net.URL;

/**
 * Проверка обновлений и установка нового APK «внутри приложения».
 *
 * Приложение раздаётся файлом (не через Google Play), поэтому обновляемся сами:
 *   1. GET {@link #VERSION_URL} → JSON {versionCode, versionName, url, notes}.
 *   2. Если versionCode на сервере больше установленного — показываем диалог.
 *   3. По кнопке «Обновить» качаем APK через DownloadManager и открываем
 *      системный установщик (нужна одна подпись у всех сборок — см. build.gradle).
 *
 * Всё тихо: любая сетевая ошибка = «обновлений нет», без всплывашек.
 */
public class UpdateChecker {

    private static final String VERSION_URL = "https://triada-mendeleeva.ru/app/version.php";
    private static final String APK_MIME = "application/vnd.android.package-archive";
    private static final String APK_NAME = "triada-update.apk";

    /** Запустить проверку через небольшую задержку после старта. */
    public static void check(final Activity activity) {
        new Handler(Looper.getMainLooper()).postDelayed(new Runnable() {
            @Override public void run() { runCheck(activity); }
        }, 2500);
    }

    private static void runCheck(final Activity activity) {
        new Thread(new Runnable() {
            @Override public void run() {
                try {
                    JSONObject info = fetchJson(VERSION_URL);
                    if (info == null) return;
                    int latest = info.optInt("versionCode", 0);
                    int current = currentVersionCode(activity);
                    if (latest <= current) return;

                    final String url = info.optString("url", "");
                    final String name = info.optString("versionName", "");
                    final String notes = info.optString("notes", "");
                    if (url.isEmpty()) return;

                    activity.runOnUiThread(new Runnable() {
                        @Override public void run() { showDialog(activity, name, notes, url); }
                    });
                } catch (Throwable ignored) {
                    // нет сети / кривой ответ — молча выходим
                }
            }
        }).start();
    }

    private static void showDialog(final Activity activity, String name, String notes, final String url) {
        if (activity.isFinishing()) return;
        StringBuilder msg = new StringBuilder();
        if (!name.isEmpty()) msg.append("Версия ").append(name).append("\n\n");
        msg.append(notes.isEmpty() ? "Доступна новая версия приложения." : notes);

        new AlertDialog.Builder(activity)
                .setTitle("Доступно обновление")
                .setMessage(msg.toString())
                .setCancelable(true)
                .setPositiveButton("Обновить", (d, w) -> startUpdate(activity, url))
                .setNegativeButton("Позже", null)
                .show();
    }

    private static void startUpdate(final Activity activity, String url) {
        // Разрешение «установка неизвестных приложений» (Android 8+)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O
                && !activity.getPackageManager().canRequestPackageInstalls()) {
            Toast.makeText(activity,
                    "Разрешите установку из этого приложения и нажмите «Обновить» ещё раз",
                    Toast.LENGTH_LONG).show();
            try {
                Intent i = new Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES,
                        Uri.parse("package:" + activity.getPackageName()));
                activity.startActivity(i);
            } catch (Throwable ignored) {}
            return;
        }
        download(activity, url);
    }

    private static void download(final Activity activity, String url) {
        try {
            File dir = new File(activity.getExternalFilesDir(null), "updates");
            if (!dir.exists()) dir.mkdirs();
            final File apk = new File(dir, APK_NAME);
            if (apk.exists()) apk.delete();

            DownloadManager dm = (DownloadManager) activity.getSystemService(Context.DOWNLOAD_SERVICE);
            DownloadManager.Request req = new DownloadManager.Request(Uri.parse(url));
            req.setTitle("Обновление «Триада Менделеева»");
            req.setMimeType(APK_MIME);
            req.setDestinationInExternalFilesDir(activity, null, "updates/" + APK_NAME);
            req.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
            final long id = dm.enqueue(req);

            Toast.makeText(activity, "Загрузка обновления…", Toast.LENGTH_SHORT).show();

            BroadcastReceiver receiver = new BroadcastReceiver() {
                @Override public void onReceive(Context ctx, Intent intent) {
                    long done = intent.getLongExtra(DownloadManager.EXTRA_DOWNLOAD_ID, -1);
                    if (done != id) return;
                    try { ctx.unregisterReceiver(this); } catch (Throwable ignored) {}
                    install(activity, apk);
                }
            };
            ContextCompat.registerReceiver(activity, receiver,
                    new IntentFilter(DownloadManager.ACTION_DOWNLOAD_COMPLETE),
                    ContextCompat.RECEIVER_EXPORTED);
        } catch (Throwable t) {
            Toast.makeText(activity, "Не удалось скачать обновление", Toast.LENGTH_LONG).show();
        }
    }

    private static void install(Activity activity, File apk) {
        try {
            if (!apk.exists() || apk.length() == 0) {
                Toast.makeText(activity, "Файл обновления не загрузился", Toast.LENGTH_LONG).show();
                return;
            }
            Uri uri = FileProvider.getUriForFile(activity,
                    activity.getPackageName() + ".fileprovider", apk);
            Intent i = new Intent(Intent.ACTION_VIEW);
            i.setDataAndType(uri, APK_MIME);
            i.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION | Intent.FLAG_ACTIVITY_NEW_TASK);
            activity.startActivity(i);
        } catch (Throwable t) {
            Toast.makeText(activity, "Не удалось открыть установщик", Toast.LENGTH_LONG).show();
        }
    }

    private static int currentVersionCode(Context ctx) {
        try {
            return ctx.getPackageManager().getPackageInfo(ctx.getPackageName(), 0).versionCode;
        } catch (Throwable t) {
            return Integer.MAX_VALUE; // не знаем версию — считаем, что обновлять не надо
        }
    }

    private static JSONObject fetchJson(String urlStr) {
        HttpURLConnection c = null;
        try {
            URL url = new URL(urlStr);
            c = (HttpURLConnection) url.openConnection();
            c.setConnectTimeout(8000);
            c.setReadTimeout(8000);
            c.setRequestProperty("Accept", "application/json");
            c.setRequestProperty("User-Agent", "TriadaApp");
            if (c.getResponseCode() != 200) return null;
            StringBuilder sb = new StringBuilder();
            BufferedReader r = new BufferedReader(new InputStreamReader(c.getInputStream(), "UTF-8"));
            String line;
            while ((line = r.readLine()) != null) sb.append(line);
            r.close();
            String body = sb.toString().trim();
            if (body.isEmpty()) return null;
            return new JSONObject(body);
        } catch (Throwable t) {
            return null;
        } finally {
            if (c != null) c.disconnect();
        }
    }
}
